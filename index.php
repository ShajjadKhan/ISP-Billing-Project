<?php
session_start();
date_default_timezone_set('Asia/Riyadh');
define('DB_PATH', '/home/tserver/cybernet/cybernet.db');
define('APP_NAME', 'CyberNet ISP');
require_once __DIR__ . '/radius_manager.php';

$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$db->exec("PRAGMA journal_mode=WAL");

// ── HELPERS ──────────────────────────────────────────────────────────
function isLoggedIn() { return isset($_SESSION['user_id']); }
function isMaster()   { return isset($_SESSION['role']) && $_SESSION['role'] === 'master'; }
function isAdmin()    { return isset($_SESSION['role']) && in_array($_SESSION['role'], ['master','admin']); }
function e($s)        { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function getSetting($db, $key) {
    return $db->querySingle("SELECT value FROM settings WHERE key='".SQLite3::escapeString($key)."'") ?: '';
}
function logAction($db, $uid, $action, $details) {
    $a = SQLite3::escapeString($action);
    $d = SQLite3::escapeString($details);
    $db->exec("INSERT INTO audit_log (user_id,action,details) VALUES ($uid,'$a','$d')");
}
function sendWhatsApp($db, $mobile, $message) {
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $sid  = getSetting($db,'openwa_session_id');
    if (!$sid || !$base) return false;
    $phone = preg_replace('/^0+/','', $mobile);
    if (!preg_match('/^966/',$phone)) $phone = '966'.$phone;
    $ch = curl_init("$base/api/sessions/$sid/messages/send-text");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","X-API-Key: $key"],
        CURLOPT_POSTFIELDS=>json_encode(['chatId'=>$phone.'@c.us','text'=>$message])]);
    $r = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
function getCustomerStatus($db, $customer_id) {
    $pkg = $db->querySingle("SELECT end_date, status FROM packages WHERE customer_id=$customer_id AND status='active' ORDER BY end_date DESC LIMIT 1", true);
    if (!$pkg) return 'no_package';
    if (strtotime($pkg['end_date']) < time()) return 'expired';
    return 'active';
}
function getDaysLeft($db, $customer_id) {
    $end = $db->querySingle("SELECT end_date FROM packages WHERE customer_id=$customer_id AND status='active' ORDER BY end_date DESC LIMIT 1");
    if (!$end) return 0;
    $diff = strtotime($end) - time();
    return max(0, ceil($diff / 86400));
}

// ── AUTH ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
if ($action === 'login') {
    $u = $db->querySingle("SELECT * FROM users WHERE username='".SQLite3::escapeString($_POST['username'])."'",true);
    if ($u && password_verify($_POST['password'],$u['password'])) {
        $_SESSION = ['user_id'=>$u['id'],'role'=>$u['role'],'fullname'=>$u['fullname'],'username'=>$u['username']];
        header('Location: index.php'); exit;
    }
    $login_error = "Invalid username or password";
}

// ── ACTIONS ───────────────────────────────────────────────────────────

// Add/Edit Customer
if (isLoggedIn() && isAdmin() && $action === 'save_customer') {
    $id          = intval($_POST['id'] ?? 0);
    $name        = SQLite3::escapeString($_POST['name']);
    $phone       = SQLite3::escapeString($_POST['phone']);
    $room        = SQLite3::escapeString($_POST['room']);
    $building    = SQLite3::escapeString($_POST['building']);
    $nationality = SQLite3::escapeString($_POST['nationality'] ?? '');
    $iqama       = SQLite3::escapeString($_POST['iqama_number'] ?? '');
    $notes       = SQLite3::escapeString($_POST['notes'] ?? '');
    $device_limit= intval($_POST['device_limit'] ?? 1);
    $monthly_fee = floatval($_POST['monthly_fee'] ?? 30);
    $status      = SQLite3::escapeString($_POST['status'] ?? 'active');
    if ($id) {
        $db->exec("UPDATE customers SET name='$name',phone='$phone',room='$room',building='$building',
            nationality='$nationality',iqama_number='$iqama',notes='$notes',
            device_limit=$device_limit,monthly_fee=$monthly_fee,status='$status' WHERE id=$id");
        logAction($db,$_SESSION['user_id'],'EDIT_CUSTOMER',"Updated customer ID $id: $name");
    } else {
        $db->exec("INSERT INTO customers (name,phone,room,building,nationality,iqama_number,notes,device_limit,monthly_fee,status,created_by)
            VALUES ('$name','$phone','$room','$building','$nationality','$iqama','$notes',$device_limit,$monthly_fee,'$status',{$_SESSION['user_id']})");
        $id = $db->lastInsertRowID();
        logAction($db,$_SESSION['user_id'],'ADD_CUSTOMER',"Added customer: $name");
        // Send welcome WhatsApp
        $welcome = getSetting($db,'whatsapp_welcome');
        if ($welcome && $phone) sendWhatsApp($db, $phone, str_replace('{name}', $_POST['name'], $welcome));
    }
    $_SESSION['msg'] = "Customer saved.";
    header('Location: index.php?page=customers'); exit;
}

// Delete Customer
if (isLoggedIn() && isMaster() && isset($_GET['delete_customer'])) {
    $id = intval($_GET['delete_customer']);
    $c  = $db->querySingle("SELECT name FROM customers WHERE id=$id",true);
    $devs=$db->query("SELECT mac_address FROM devices WHERE customer_id=$id AND status='active'");
    while($dv=$devs->fetchArray(SQLITE3_ASSOC)) radius_remove_user($dv['mac_address']);
    $db->exec("DELETE FROM customers WHERE id=$id");
    $db->exec("DELETE FROM devices WHERE customer_id=$id");
    $db->exec("DELETE FROM packages WHERE customer_id=$id");
    logAction($db,$_SESSION['user_id'],'DELETE_CUSTOMER',"Deleted: {$c['name']}");
    header('Location: index.php?page=customers'); exit;
}

// Add Device
if (isLoggedIn() && isAdmin() && $action === 'add_device') {
    $customer_id = intval($_POST['customer_id']);
    $mac         = strtoupper(SQLite3::escapeString(trim($_POST['mac_address'])));
    $device_name = SQLite3::escapeString($_POST['device_name'] ?? 'Device');
    // Check device limit
    $current_count = $db->querySingle("SELECT COUNT(*) FROM devices WHERE customer_id=$customer_id AND status='active'");
    $limit = $db->querySingle("SELECT device_limit FROM customers WHERE id=$customer_id");
    if ($current_count >= $limit) {
        $_SESSION['msg'] = "Device limit reached for this customer ($limit devices max).";
    } else {
        $db->exec("INSERT OR IGNORE INTO devices (customer_id,mac_address,device_name) VALUES ($customer_id,'$mac','$device_name')");
        logAction($db,$_SESSION['user_id'],'ADD_DEVICE',"Added device $mac for customer $customer_id");
        $_SESSION['msg'] = "Device added.";
    }
    header('Location: index.php?page=customer_detail&id='.$customer_id); exit;
}

// Delete Device
if (isLoggedIn() && isAdmin() && isset($_GET['delete_device'])) {
    $id  = intval($_GET['delete_device']);
    $cid = intval($_GET['cid']);
    $dev = $db->querySingle("SELECT mac_address FROM devices WHERE id=$id",true);
    $db->exec("UPDATE devices SET status='removed' WHERE id=$id");
    radius_remove_user($dev['mac_address']);
    logAction($db,$_SESSION['user_id'],'REMOVE_DEVICE',"Removed device {$dev['mac_address']}");
    header('Location: index.php?page=customer_detail&id='.$cid); exit;
}

// Add Package
if (isLoggedIn() && isAdmin() && $action === 'add_package') {
    $customer_id = intval($_POST['customer_id']);
    $days        = intval($_POST['days']);
    $fee         = floatval($_POST['fee']);
    $start       = date('Y-m-d');
    $end         = date('Y-m-d', strtotime("+$days days"));
    // Expire old packages
    $db->exec("UPDATE packages SET status='expired' WHERE customer_id=$customer_id AND status='active'");
    $db->exec("INSERT INTO packages (customer_id,days,start_date,end_date,fee,created_by)
        VALUES ($customer_id,$days,'$start','$end',$fee,{$_SESSION['user_id']})");
    // Activate customer
    $db->exec("UPDATE customers SET status='active' WHERE id=$customer_id");
    $cust = $db->querySingle("SELECT name,phone FROM customers WHERE id=$customer_id",true);
    logAction($db,$_SESSION['user_id'],'ADD_PACKAGE',"Package: $days days for {$cust['name']} expires $end");
    // WhatsApp notification
    $msg = "Dear {$cust['name']}, your internet package has been activated for $days days. Valid until $end. Thank you!";
    if ($cust['phone']) sendWhatsApp($db, $cust['phone'], $msg);
    $_SESSION['msg'] = "Package activated. Valid until $end.";
    header('Location: index.php?page=customer_detail&id='.$customer_id); exit;
}

// Approve pending request
if (isLoggedIn() && isAdmin() && $action === 'approve_pending') {
    $pending_id  = intval($_POST['pending_id']);
    $pending     = $db->querySingle("SELECT * FROM pending_approvals WHERE id=$pending_id",true);
    if ($pending) {
        // Save customer
        $name     = SQLite3::escapeString($_POST['name']);
        $phone    = SQLite3::escapeString($pending['phone']);
        $room     = SQLite3::escapeString($_POST['room']);
        $building = SQLite3::escapeString($_POST['building']);
        $limit    = intval($_POST['device_limit'] ?? 1);
        $fee      = floatval($_POST['monthly_fee'] ?? 30);
        $days     = intval($_POST['days'] ?? 30);
        $db->exec("INSERT INTO customers (name,phone,room,building,device_limit,monthly_fee,status,created_by)
            VALUES ('$name','$phone','$room','$building',$limit,$fee,'active',{$_SESSION['user_id']})");
        $cid = $db->lastInsertRowID();
        // Add device
        $mac = SQLite3::escapeString($pending['mac_address']);
        $db->exec("INSERT OR IGNORE INTO devices (customer_id,mac_address,device_name) VALUES ($cid,'$mac','Device 1')");
        // Add package
        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+$days days"));
        $db->exec("INSERT INTO packages (customer_id,days,start_date,end_date,fee,created_by)
            VALUES ($cid,$days,'$start','$end',$fee,{$_SESSION['user_id']})");
        // Add MAC to RADIUS
        radius_add_user($pending['mac_address']);
        // Mark pending as approved
        $db->exec("UPDATE pending_approvals SET status='approved' WHERE id=$pending_id");
        logAction($db,$_SESSION['user_id'],'APPROVE_PENDING',"Approved: $name phone: {$pending['phone']} MAC: {$pending['mac_address']}");
        // Welcome WhatsApp
        $msg = "Dear $name, welcome to CyberNet! Your internet is now active for $days days (until $end). Enjoy!";
        if ($phone) sendWhatsApp($db, $phone, $msg);
        $_SESSION['msg'] = "Customer approved and connected!";
    }
    header('Location: index.php?page=hotspot'); exit;
}

// Reject pending
if (isLoggedIn() && isAdmin() && isset($_GET['reject_pending'])) {
    $id = intval($_GET['reject_pending']);
    $db->exec("UPDATE pending_approvals SET status='rejected' WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'REJECT_PENDING',"Rejected pending ID $id");
    header('Location: index.php?page=hotspot'); exit;
}

// Suspend / Activate customer
if (isLoggedIn() && isAdmin() && isset($_GET['toggle_customer'])) {
    $id   = intval($_GET['toggle_customer']);
    $cust = $db->querySingle("SELECT name,status FROM customers WHERE id=$id",true);
    $new  = $cust['status'] === 'active' ? 'suspended' : 'active';
    $db->exec("UPDATE customers SET status='$new' WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'TOGGLE_CUSTOMER',"{$cust['name']} set to $new");
    $devs=$db->query("SELECT mac_address FROM devices WHERE customer_id=$id AND status='active'");
    while($dv=$devs->fetchArray(SQLITE3_ASSOC)){
        if($new==='suspended') radius_remove_user($dv['mac_address']);
        else radius_add_user($dv['mac_address']);
    }
    header('Location: index.php?page=customers'); exit;
}

// Save settings
if (isLoggedIn() && isMaster() && $action === 'save_settings') {
    $fields = ['system_name','mikrotik_ip','mikrotik_user','mikrotik_pass','mikrotik_port',
               'radius_secret','openwa_url','openwa_api_key','openwa_session_id',
               'whatsapp_welcome','whatsapp_expiry','whatsapp_expired'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $k = SQLite3::escapeString($f);
            $v = SQLite3::escapeString($_POST[$f]);
            $db->exec("INSERT OR REPLACE INTO settings (key,value) VALUES ('$k','$v')");
        }
    }
    logAction($db,$_SESSION['user_id'],'SAVE_SETTINGS','Updated system settings');
    $_SESSION['msg'] = "Settings saved.";
    header('Location: index.php?page=settings'); exit;
}

// Change password
if (isLoggedIn() && $action === 'change_password') {
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];
    $u   = $db->querySingle("SELECT password FROM users WHERE id={$_SESSION['user_id']}",true);
    if (password_verify($old,$u['password'])) {
        $hash = password_hash($new,PASSWORD_DEFAULT);
        $db->exec("UPDATE users SET password='$hash' WHERE id={$_SESSION['user_id']}");
        logAction($db,$_SESSION['user_id'],'CHANGE_PASSWORD','Password changed');
        $_SESSION['msg'] = "Password changed successfully.";
    } else {
        $_SESSION['msg'] = "Old password incorrect.";
    }
    header('Location: index.php?page=settings'); exit;
}

// ── DATA LOADING ──────────────────────────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';

// Dashboard stats
$total_customers  = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status != 'deleted'");
$active_customers = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='active'");
$suspended        = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='suspended'");
$pending_count    = $db->querySingle("SELECT COUNT(*) FROM pending_approvals WHERE status='pending'");
$total_devices    = $db->querySingle("SELECT COUNT(*) FROM devices WHERE status='active'");
$expiring_soon    = $db->querySingle("SELECT COUNT(DISTINCT customer_id) FROM packages WHERE status='active' AND date(end_date) BETWEEN date('now') AND date('now','+3 days')");
$expired_today    = $db->querySingle("SELECT COUNT(DISTINCT customer_id) FROM packages WHERE status='active' AND date(end_date) < date('now')");
$revenue_month    = $db->querySingle("SELECT COALESCE(SUM(fee),0) FROM packages WHERE strftime('%Y-%m',created_at)='".date('Y-m')."'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(getSetting($db,'system_name')) ?> — Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f0f4f8;--surface:#fff;--surface2:#f7fafc;--border:#e2e8f0;--text:#1a202c;--muted:#718096;--accent:#0ea5e9;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;--nav:#0f172a;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.04)}
[data-theme=dark]{--bg:#0d1117;--surface:#161b22;--surface2:#1c2230;--border:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#38bdf8;--success:#34d399;--danger:#f87171;--warning:#fbbf24;--nav:#010409;--shadow:0 1px 3px rgba(0,0,0,.4),0 4px 16px rgba(0,0,0,.3)}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:14px;transition:background .2s,color .2s}
.topbar{background:var(--nav);height:56px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1000;border-bottom:1px solid rgba(255,255,255,.06);gap:12px}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:#fff;flex-shrink:0}
.brand-icon{width:32px;height:32px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center}
.brand-name{font-weight:700;font-size:15px}.brand-name span{color:var(--accent)}
.nav-wrap{display:flex;overflow-x:auto;gap:2px;scrollbar-width:none;flex:1;justify-content:center}
.nav-wrap::-webkit-scrollbar{display:none}
.nav-wrap a{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;color:#94a3b8;text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap;transition:all .15s;border:1px solid transparent;position:relative}
.nav-wrap a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-wrap a.active{background:rgba(14,165,233,.15);color:var(--accent);border-color:rgba(14,165,233,.3)}
.nav-wrap a .badge-dot{position:absolute;top:4px;right:4px;width:8px;height:8px;background:var(--danger);border-radius:50%;display:none}
.nav-wrap a .badge-dot.show{display:block}
.topbar-right{display:flex;align-items:center;gap:8px;flex-shrink:0}
.user-pill{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:4px 12px;font-size:12px;color:#cbd5e1;display:flex;align-items:center;gap:6px}
.role-tag{background:var(--accent);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700}
.btn-icon{width:34px;height:34px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#94a3b8;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;text-decoration:none;font-size:13px}
.btn-icon:hover{background:rgba(255,255,255,.15);color:#fff}
.main{flex:1;padding:20px;max-width:1600px;margin:0 auto;width:100%}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface)}
.card-title{font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px}
.card-title i{color:var(--accent);font-size:13px}
.card-body{padding:16px 18px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow);transition:transform .2s}
.stat:hover{transform:translateY(-2px)}
.stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.stat-value{font-size:28px;font-weight:700;font-family:'IBM Plex Mono',monospace;line-height:1.1;margin-top:2px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.c-blue{color:#3b82f6}.ci-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.c-green{color:var(--success)}.ci-green{background:rgba(16,185,129,.12);color:var(--success)}
.c-red{color:var(--danger)}.ci-red{background:rgba(239,68,68,.12);color:var(--danger)}
.c-yellow{color:var(--warning)}.ci-yellow{background:rgba(245,158,11,.12);color:var(--warning)}
.c-purple{color:#8b5cf6}.ci-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.c-teal{color:var(--accent)}.ci-teal{background:rgba(14,165,233,.12);color:var(--accent)}
.c-orange{color:#f97316}.ci-orange{background:rgba(249,115,22,.12);color:#f97316}
.c-pink{color:#ec4899}.ci-pink{background:rgba(236,72,153,.12);color:#ec4899}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--surface2)}
thead th{padding:10px 12px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);border-bottom:2px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
tbody tr:hover{background:var(--surface2)}
tbody td{padding:10px 12px;vertical-align:middle}
.form-control,.form-select{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;width:100%;transition:border-color .15s}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.15)}
.form-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;display:block}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{filter:brightness(1.1)}
.btn-success{background:var(--success);color:#fff}.btn-success:hover{filter:brightness(1.1)}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{filter:brightness(1.1)}
.btn-warning{background:var(--warning);color:#000}.btn-warning:hover{filter:brightness(1.1)}
.btn-secondary{background:var(--surface2);color:var(--text);border-color:var(--border)}
.btn-wa{background:#25D366;color:#fff}.btn-wa:hover{background:#128C7E}
.btn-sm{padding:4px 10px;font-size:12px}
.btn-xs{padding:2px 8px;font-size:11px;border-radius:6px}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
.badge-green{background:rgba(16,185,129,.15);color:var(--success)}
.badge-red{background:rgba(239,68,68,.15);color:var(--danger)}
.badge-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.badge-yellow{background:rgba(245,158,11,.15);color:var(--warning)}
.badge-gray{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}
.badge-purple{background:rgba(139,92,246,.15);color:#8b5cf6}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal-overlay.show{display:flex}
.modal-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:100%;max-width:560px;max-height:90vh;overflow-y:auto;animation:mIn .2s ease}
.modal-box-lg{max-width:800px}
@keyframes mIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
.modal-head{padding:16px 20px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-head h5{font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px}
.modal-body{padding:16px 20px}
.modal-close{width:28px;height:28px;border-radius:6px;border:none;background:var(--surface2);color:var(--muted);cursor:pointer;font-size:14px}
.modal-close:hover{background:var(--danger);color:#fff}
.alert{padding:12px 16px;border-radius:10px;border:1px solid;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px}
.alert-info{background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.3);color:#818cf8}
.alert-success{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3);color:var(--success)}
.alert-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:var(--danger)}
.alert-warning{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.3);color:var(--warning)}
.alert-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:16px}
.divider{border:none;border-top:1px solid var(--border);margin:14px 0}
.mono{font-family:'IBM Plex Mono',monospace}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px}
.dot-green{background:var(--success);box-shadow:0 0 6px var(--success)}
.dot-red{background:var(--danger)}
.dot-yellow{background:var(--warning);animation:pulse 1.5s infinite}
.dot-gray{background:var(--muted)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--nav)}
.login-card{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:36px 32px;width:100%;max-width:400px}
.login-card .ico{width:56px;height:56px;background:var(--accent);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:22px;color:#fff;margin-bottom:12px}
.login-btn{background:var(--accent);color:#fff;width:100%;padding:10px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.detail-item{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px}
.detail-label{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;margin-bottom:4px}
.detail-value{font-size:14px;font-weight:500}
.pending-card{background:var(--surface);border:2px solid var(--warning);border-radius:12px;padding:16px;margin-bottom:12px;animation:mIn .3s ease}
.expiry-bar{height:6px;border-radius:3px;background:var(--border);overflow:hidden;margin-top:6px}
.expiry-fill{height:100%;border-radius:3px;transition:width .3s}
[data-theme=dark] .form-control,[data-theme=dark] .form-select{background:var(--surface2);color:var(--text);border-color:var(--border)}
[data-theme=dark] .card,[data-theme=dark] .modal-box,[data-theme=dark] .stat{background:var(--surface);border-color:var(--border)}
[data-theme=dark] table{color:var(--text)}
[data-theme=dark] thead tr,[data-theme=dark] tbody tr:hover{background:var(--surface2)}
[data-theme=dark] .detail-item{background:var(--surface2)}
@media(max-width:768px){.stats-grid{grid-template-columns:1fr 1fr}.topbar-brand .brand-name{display:none}.main{padding:12px}.stat-value{font-size:20px}.detail-grid{grid-template-columns:1fr}}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr 1fr}.nav-wrap a{padding:6px 8px;font-size:11px}.nav-wrap a i{display:none}.topbar{padding:0 10px}}

[data-theme=dark] .card,[data-theme=dark] .stat,[data-theme=dark] .modal-box{background:var(--surface)!important;border-color:var(--border)!important}
[data-theme=dark] table{color:var(--text)}
[data-theme=dark] thead tr,[data-theme=dark] tbody tr:hover{background:var(--surface2)!important}
[data-theme=dark] tbody td,[data-theme=dark] thead th{border-color:var(--border);color:var(--text)}
[data-theme=dark] .form-control,[data-theme=dark] .form-select{background:var(--surface2)!important;color:var(--text)!important;border-color:var(--border)!important}
[data-theme=dark] .card-header{background:var(--surface)!important;border-color:var(--border)!important}
[data-theme=dark] .detail-item{background:var(--surface2)!important;border-color:var(--border)!important}
[data-theme=dark] .pending-card{background:var(--surface)!important}
[data-theme=dark] .btn-secondary{background:var(--surface2);color:var(--text);border-color:var(--border)}
[data-theme=dark] .modal-head{border-color:var(--border)}
[data-theme=dark] input,[data-theme=dark] textarea,[data-theme=dark] select{background:var(--surface2)!important;color:var(--text)!important;border-color:var(--border)!important}
</style>
</head>
<body>
<?php if (!isLoggedIn()): ?>
<div class="login-wrap">
 <div class="login-card">
  <div style="text-align:center;margin-bottom:24px">
   <div class="ico"><i class="fas fa-wifi"></i></div>
   <h2 style="color:#fff;font-size:20px;font-weight:700"><?= e(getSetting($db,'system_name')) ?></h2>
   <p style="color:#8b949e;font-size:13px">ISP Management Portal</p>
  </div>
  <?php if(isset($login_error)):?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><?= e($login_error)?></div><?php endif;?>
  <form method="post">
   <input type="hidden" name="action" value="login">
   <div style="margin-bottom:14px"><label class="form-label" style="color:#8b949e">Username</label><input type="text" name="username" class="form-control" style="background:#0d1117;color:#e6edf3;border-color:#30363d" required autofocus></div>
   <div style="margin-bottom:20px"><label class="form-label" style="color:#8b949e">Password</label><input type="password" name="password" class="form-control" style="background:#0d1117;color:#e6edf3;border-color:#30363d" required></div>
   <button type="submit" class="login-btn"><i class="fas fa-sign-in-alt"></i> Sign In</button>
  </form>
 </div>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;min-height:100vh">
<nav class="topbar">
 <a href="index.php" class="brand"><div class="brand-icon"><i class="fas fa-wifi" style="color:#fff;font-size:14px"></i></div><div class="brand-name">Cyber<span>Net</span></div></a>
 <div class="nav-wrap">
  <a href="?page=dashboard" class="<?=$page=='dashboard'?'active':''?>"><i class="fas fa-chart-bar"></i> Dashboard</a>
  <a href="?page=hotspot" class="<?=$page=='hotspot'?'active':''?>" id="nav-hotspot"><i class="fas fa-wifi"></i> Hotspot<span class="badge-dot <?=$pending_count>0?'show':''?>"></span></a>
  <a href="?page=customers" class="<?=$page=='customers'?'active':''?>"><i class="fas fa-users"></i> Customers</a>
  <a href="?page=packages" class="<?=$page=='packages'?'active':''?>"><i class="fas fa-box"></i> Packages</a>
  <a href="?page=devices" class="<?=$page=='devices'?'active':''?>"><i class="fas fa-mobile-alt"></i> Devices</a>
  <a href="?page=logs" class="<?=$page=='logs'?'active':''?>"><i class="fas fa-shield-alt"></i> Logs</a>
  <a href="?page=reports" class="<?=$page=='reports'?'active':''?>"><i class="fas fa-file-alt"></i> Reports</a>
  <?php if(isMaster()):?>
  <a href="?page=settings" class="<?=$page=='settings'?'active':''?>"><i class="fas fa-cog"></i> Settings</a>
  <?php endif;?>
 </div>
 <div class="topbar-right">
  <button class="btn-icon" onclick="toggleTheme()"><i class="fas fa-moon" id="theme-icon"></i></button>
  <div class="user-pill"><i class="fas fa-circle" style="font-size:6px;color:var(--success)"></i><?=e($_SESSION['fullname'])?><span class="role-tag"><?=$_SESSION['role']?></span></div>
  <a href="?logout=1" class="btn-icon" style="color:#f87171"><i class="fas fa-sign-out-alt"></i></a>
 </div>
</nav>
<div class="main">
<?php if(isset($_SESSION['msg'])):?>
<div class="alert alert-info" id="top-msg"><i class="fas fa-info-circle"></i><span><?=e($_SESSION['msg'])?></span><button class="alert-close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['msg']);endif;?>

<?php if($page==='dashboard'): ?>
<!-- DASHBOARD -->
<div class="stats-grid">
 <div class="stat"><div><div class="stat-label">Total Customers</div><div class="stat-value c-blue"><?=$total_customers?></div></div><div class="stat-icon ci-blue"><i class="fas fa-users"></i></div></div>
 <div class="stat"><div><div class="stat-label">Active</div><div class="stat-value c-green"><?=$active_customers?></div></div><div class="stat-icon ci-green"><i class="fas fa-check-circle"></i></div></div>
 <div class="stat"><div><div class="stat-label">Suspended</div><div class="stat-value c-red"><?=$suspended?></div></div><div class="stat-icon ci-red"><i class="fas fa-ban"></i></div></div>
 <div class="stat"><div><div class="stat-label">Pending Approvals</div><div class="stat-value c-yellow"><?=$pending_count?></div></div><div class="stat-icon ci-yellow"><i class="fas fa-clock"></i></div></div>
 <div class="stat"><div><div class="stat-label">Total Devices</div><div class="stat-value c-purple"><?=$total_devices?></div></div><div class="stat-icon ci-purple"><i class="fas fa-mobile-alt"></i></div></div>
 <div class="stat"><div><div class="stat-label">Expiring (3 days)</div><div class="stat-value c-orange"><?=$expiring_soon?></div></div><div class="stat-icon ci-orange"><i class="fas fa-exclamation-triangle"></i></div></div>
 <div class="stat"><div><div class="stat-label">Expired</div><div class="stat-value c-red"><?=$expired_today?></div></div><div class="stat-icon ci-red"><i class="fas fa-times-circle"></i></div></div>
 <div class="stat"><div><div class="stat-label">Revenue (Month)</div><div class="stat-value c-teal"><?=number_format($revenue_month,0)?></div></div><div class="stat-icon ci-teal"><i class="fas fa-money-bill-wave"></i></div></div>
</div>

<?php if($pending_count > 0):?>
<div class="card" style="border-color:var(--warning)">
 <div class="card-header"><div class="card-title"><i class="fas fa-clock" style="color:var(--warning)"></i> Pending Approvals <span class="badge badge-yellow"><?=$pending_count?> waiting</span></div><a href="?page=hotspot" class="btn btn-warning btn-sm"><i class="fas fa-arrow-right"></i> Review Now</a></div>
</div>
<?php endif;?>

<?php if($expiring_soon > 0):?>
<div class="card" style="border-color:var(--warning)">
 <div class="card-header"><div class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Expiring Soon <span class="badge badge-yellow"><?=$expiring_soon?> customers</span></div></div>
 <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>Expires</th><th>Days Left</th><th>Action</th></tr></thead><tbody>
 <?php $exp=$db->query("SELECT c.id,c.name,c.phone,p.end_date FROM customers c JOIN packages p ON c.id=p.customer_id WHERE p.status='active' AND date(p.end_date) BETWEEN date('now') AND date('now','+3 days') ORDER BY p.end_date");
 while($r=$exp->fetchArray(SQLITE3_ASSOC)):$dl=max(0,ceil((strtotime($r['end_date'])-time())/86400));?>
 <tr><td><strong><?=e($r['name'])?></strong></td><td class="mono"><?=e($r['phone'])?></td><td class="mono"><?=$r['end_date']?></td><td><span class="badge badge-yellow"><?=$dl?> days</span></td><td><a href="?page=customer_detail&id=<?=$r['id']?>" class="btn btn-primary btn-xs">Renew</a></td></tr>
 <?php endwhile;?>
 </tbody></table></div>
</div>
<?php endif;?>

<?php if($expired_today > 0):?>
<div class="card" style="border-color:var(--danger)">
 <div class="card-header"><div class="card-title"><i class="fas fa-times-circle" style="color:var(--danger)"></i> Expired Packages <span class="badge badge-red"><?=$expired_today?> customers</span></div></div>
 <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>Expired On</th><th>Action</th></tr></thead><tbody>
 <?php $exp2=$db->query("SELECT c.id,c.name,c.phone,p.end_date FROM customers c JOIN packages p ON c.id=p.customer_id WHERE p.status='active' AND date(p.end_date) < date('now') ORDER BY p.end_date DESC");
 while($r=$exp2->fetchArray(SQLITE3_ASSOC)):?>
 <tr><td><strong><?=e($r['name'])?></strong></td><td class="mono"><?=e($r['phone'])?></td><td class="mono" style="color:var(--danger)"><?=$r['end_date']?></td><td><a href="?page=customer_detail&id=<?=$r['id']?>" class="btn btn-danger btn-xs">Renew</a></td></tr>
 <?php endwhile;?>
 </tbody></table></div>
</div>
<?php endif;?>

<?php elseif($page==='hotspot'): ?>
<!-- HOTSPOT / PENDING APPROVALS -->
<div class="card">
 <div class="card-header"><div class="card-title"><i class="fas fa-clock"></i> Pending Approvals <?php if($pending_count>0):?><span class="badge badge-yellow"><?=$pending_count?></span><?php endif;?></div></div>
 <?php $pendings=$db->query("SELECT * FROM pending_approvals WHERE status='pending' ORDER BY requested_at DESC");
 $has=false;
 while($p=$pendings->fetchArray(SQLITE3_ASSOC)):$has=true;?>
 <div class="pending-card" style="margin:12px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
   <div>
    <div style="font-weight:700;font-size:15px"><i class="fas fa-mobile-alt" style="color:var(--warning)"></i> <?=e($p['phone'])?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">MAC: <span class="mono"><?=e($p['mac_address'])?></span> · IP: <span class="mono"><?=e($p['ip_address'])?></span> · <?=$p['requested_at']?></div>
   </div>
   <div style="display:flex;gap:6px">
    <button class="btn btn-success btn-sm" onclick="openApprove(<?=$p['id']?>, '<?=e($p['phone'])?>', '<?=e($p['mac_address'])?>')"><i class="fas fa-check"></i> Approve</button>
    <a href="?reject_pending=<?=$p['id']?>&page=hotspot" class="btn btn-danger btn-sm" onclick="return confirm('Reject this request?')"><i class="fas fa-times"></i> Reject</a>
   </div>
  </div>
 </div>
 <?php endwhile;
 if(!$has):?><div style="padding:24px;text-align:center;color:var(--muted)"><i class="fas fa-check-circle" style="color:var(--success)"></i> No pending approvals</div><?php endif;?>
</div>

<div class="card">
 <div class="card-header"><div class="card-title"><i class="fas fa-history"></i> Recently Processed</div></div>
 <div class="table-wrap"><table><thead><tr><th>Phone</th><th>MAC</th><th>IP</th><th>Requested</th><th>Status</th></tr></thead><tbody>
 <?php $recent=$db->query("SELECT * FROM pending_approvals WHERE status != 'pending' ORDER BY requested_at DESC LIMIT 20");
 while($r=$recent->fetchArray(SQLITE3_ASSOC)):?>
 <tr><td class="mono"><?=e($r['phone'])?></td><td class="mono" style="font-size:11px"><?=e($r['mac_address'])?></td><td class="mono" style="font-size:11px"><?=e($r['ip_address'])?></td><td style="font-size:11px;color:var(--muted)"><?=$r['requested_at']?></td>
 <td><span class="badge <?=$r['status']==='approved'?'badge-green':'badge-red'?>"><?=$r['status']?></span></td></tr>
 <?php endwhile;?></tbody></table></div>
</div>

<!-- Approve Modal -->
<div class="modal-overlay" id="approveModal">
 <div class="modal-box">
  <div class="modal-head"><h5><i class="fas fa-user-check" style="color:var(--success)"></i> Approve Customer</h5><button class="modal-close" onclick="closeModal('approveModal')">×</button></div>
  <div class="modal-body">
   <form method="post">
    <input type="hidden" name="action" value="approve_pending">
    <input type="hidden" name="pending_id" id="ap_id">
    <div style="background:var(--surface2);border-radius:8px;padding:12px;margin-bottom:14px;font-size:12px">
     <div>Phone: <span class="mono" id="ap_phone"></span></div>
     <div>MAC: <span class="mono" id="ap_mac"></span></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
     <div><label class="form-label">Phone</label><input type="text" name="phone" id="ap_phone_input" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Building</label><input type="text" name="building" class="form-control"></div>
     <div><label class="form-label">Room</label><input type="text" name="room" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
     <div><label class="form-label">Package Days</label><input type="number" name="days" class="form-control" value="30" min="1" required></div>
     <div><label class="form-label">Monthly Fee (SAR)</label><input type="number" name="monthly_fee" class="form-control" value="30" step="0.5"></div>
     <div><label class="form-label">Device Limit</label><input type="number" name="device_limit" class="form-control" value="1" min="1" max="10"></div>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve & Connect</button>
   </form>
  </div>
 </div>
</div>

<?php elseif($page==='customers'): ?>
<!-- CUSTOMERS -->
<div class="card">
 <div class="card-header">
  <div class="card-title"><i class="fas fa-users"></i> All Customers</div>
  <button class="btn btn-success btn-sm" onclick="openModal('addCustModal')"><i class="fas fa-plus"></i> Add Customer</button>
 </div>
 <div class="card-body" style="padding-bottom:8px">
  <input type="text" id="custSearch" class="form-control" placeholder="Search name, phone, room, building…" style="max-width:400px">
 </div>
 <div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Building/Room</th><th>Devices</th><th>Package</th><th>Status</th><th>Actions</th></tr></thead>
 <tbody id="custTbody">
 <?php $custs=$db->query("SELECT c.*,(SELECT COUNT(*) FROM devices WHERE customer_id=c.id AND status='active') as dev_count FROM customers c WHERE c.status!='deleted' ORDER BY c.name");
 $i=0; while($c=$custs->fetchArray(SQLITE3_ASSOC)):$i++;
 $dl=getDaysLeft($db,$c['id']);
 $cs=getCustomerStatus($db,$c['id']);
 $status_class=['active'=>'badge-green','suspended'=>'badge-red','expired'=>'badge-yellow','pending'=>'badge-gray','no_package'=>'badge-gray'][$cs]??'badge-gray';
 ?>
 <tr data-name="<?=e($c['name'])?>" data-phone="<?=e($c['phone'])?>" data-room="<?=e($c['room'])?>" data-building="<?=e($c['building'])?>">
  <td class="mono" style="color:var(--muted)"><?=$i?></td>
  <td><strong><a href="?page=customer_detail&id=<?=$c['id']?>" style="color:var(--accent);text-decoration:none"><?=e($c['name'])?></a></strong></td>
  <td class="mono"><?=e($c['phone'])?></td>
  <td style="font-size:12px;color:var(--muted)"><?=e($c['building'])?> <?=e($c['room'])?></td>
  <td><span class="badge badge-blue"><?=$c['dev_count']?>/<?=$c['device_limit']?></span></td>
  <td><?php if($dl>0):?><span class="badge <?=$dl<=3?'badge-yellow':'badge-green'?>"><?=$dl?> days</span><?php else:?><span class="badge badge-gray">—</span><?php endif;?></td>
  <td><span class="badge <?=$status_class?>"><?=$cs?></span></td>
  <td style="white-space:nowrap">
   <a href="?page=customer_detail&id=<?=$c['id']?>" class="btn btn-primary btn-xs"><i class="fas fa-eye"></i></a>
   <a href="?toggle_customer=<?=$c['id']?>&page=customers" class="btn btn-xs <?=$c['status']==='active'?'btn-warning':'btn-success'?>" onclick="return confirm('<?=$c['status']==='active'?'Suspend':'Activate'?> this customer?')"><i class="fas fa-<?=$c['status']==='active'?'pause':'play'?>"></i></a>
   <?php if(isMaster()):?><a href="?delete_customer=<?=$c['id']?>&page=customers" class="btn btn-danger btn-xs" onclick="return confirm('Delete <?=e(addslashes($c['name']))?>? This cannot be undone.')"><i class="fas fa-trash"></i></a><?php endif;?>
  </td>
 </tr>
 <?php endwhile;?></tbody></table></div>
</div>

<!-- Add Customer Modal -->
<div class="modal-overlay" id="addCustModal">
 <div class="modal-box modal-box-lg">
  <div class="modal-head"><h5><i class="fas fa-user-plus" style="color:var(--success)"></i> Add New Customer</h5><button class="modal-close" onclick="closeModal('addCustModal')">×</button></div>
  <div class="modal-body">
   <form method="post">
    <input type="hidden" name="action" value="save_customer">
    <input type="hidden" name="id" value="">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
     <div><label class="form-label">Phone *</label><input type="text" name="phone" class="form-control" required></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Building</label><input type="text" name="building" class="form-control"></div>
     <div><label class="form-label">Room</label><input type="text" name="room" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Nationality</label><input type="text" name="nationality" class="form-control" placeholder="e.g. Bangladeshi"></div>
     <div><label class="form-label">Iqama/ID Number <small style="text-transform:none;font-weight:400">(optional)</small></label><input type="text" name="iqama_number" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Device Limit</label><input type="number" name="device_limit" class="form-control" value="1" min="1" max="10"></div>
     <div><label class="form-label">Monthly Fee (SAR)</label><input type="number" name="monthly_fee" class="form-control" value="30" step="0.5"></div>
    </div>
    <div style="margin-bottom:14px"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Optional notes…"></textarea></div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Customer</button>
   </form>
  </div>
 </div>
</div>

<?php elseif($page==='customer_detail' && isset($_GET['id'])): ?>
<!-- CUSTOMER DETAIL -->
<?php $cid=intval($_GET['id']); $cust=$db->querySingle("SELECT * FROM customers WHERE id=$cid",true);
if(!$cust){echo '<div class="alert alert-danger">Customer not found</div>';} else {
$cs=getCustomerStatus($db,$cid); $dl=getDaysLeft($db,$cid);
$pkg=$db->querySingle("SELECT * FROM packages WHERE customer_id=$cid AND status='active' ORDER BY end_date DESC LIMIT 1",true);
$devices=$db->query("SELECT * FROM devices WHERE customer_id=$cid AND status='active' ORDER BY added_at");
$all_pkgs=$db->query("SELECT * FROM packages WHERE customer_id=$cid ORDER BY created_at DESC LIMIT 10");
?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap">
 <h4 style="font-weight:700"><?=e($cust['name'])?></h4>
 <span class="badge <?=['active'=>'badge-green','suspended'=>'badge-red','expired'=>'badge-yellow','no_package'=>'badge-gray'][$cs]??'badge-gray'?>"><?=$cs?></span>
 <?php if($dl>0):?><span class="badge <?=$dl<=3?'badge-yellow':'badge-blue'?>"><?=$dl?> days left</span><?php endif;?>
 <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
  <a href="?page=customers" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
  <button class="btn btn-primary btn-sm" onclick="openEditCust(<?=json_encode($cust)?>)"><i class="fas fa-edit"></i> Edit</button>
  <button class="btn btn-success btn-sm" onclick="openModal('addPkgModal')"><i class="fas fa-box"></i> Add Package</button>
  <button class="btn btn-secondary btn-sm" onclick="openModal('addDevModal')"><i class="fas fa-mobile-alt"></i> Add Device</button>
  <?php if($cust['phone']):?><button class="btn btn-wa btn-sm" onclick="sendWa('<?=e($cust['phone'])?>','<?=e(addslashes($cust['name']))?>')"><i class="fab fa-whatsapp"></i> WhatsApp</button><?php endif;?>
 </div>
</div>

<?php if($pkg):
 $total_days=max(1,$pkg['days']);
 $days_used=ceil((time()-strtotime($pkg['start_date']))/86400);
 $pct=min(100,round(($days_used/$total_days)*100));
?>
<div class="card" style="border-color:<?=$dl<=3?'var(--warning)':'var(--border)'?>">
 <div class="card-body">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
   <span style="font-weight:600">Active Package — <?=$pkg['days']?> days</span>
   <span class="mono" style="font-size:13px"><?=$pkg['start_date']?> → <?=$pkg['end_date']?></span>
  </div>
  <div class="expiry-bar"><div class="expiry-fill" style="width:<?=$pct?>%;background:<?=$dl<=3?'var(--warning)':($dl<=7?'#f97316':'var(--success)')?>"></div></div>
  <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:var(--muted)"><span><?=$days_used?> days used</span><span><?=$dl?> days remaining</span></div>
 </div>
</div>
<?php endif;?>

<div class="detail-grid">
 <div class="detail-item"><div class="detail-label">Phone</div><div class="detail-value mono"><?=e($cust['phone'])?></div></div>
 <div class="detail-item"><div class="detail-label">Building / Room</div><div class="detail-value"><?=e($cust['building'])?> — <?=e($cust['room'])?></div></div>
 <div class="detail-item"><div class="detail-label">Nationality</div><div class="detail-value"><?=e($cust['nationality'])?:' — '?></div></div>
 <div class="detail-item"><div class="detail-label">Iqama / ID</div><div class="detail-value mono"><?=e($cust['iqama_number'])?:' — '?></div></div>
 <div class="detail-item"><div class="detail-label">Monthly Fee</div><div class="detail-value mono"><?=$cust['monthly_fee']?> SAR</div></div>
 <div class="detail-item"><div class="detail-label">Device Limit</div><div class="detail-value"><?=$cust['device_limit']?> devices</div></div>
 <div class="detail-item"><div class="detail-label">Registered</div><div class="detail-value mono"><?=$cust['created_at']?></div></div>
 <?php if($cust['notes']):?><div class="detail-item" style="grid-column:1/-1"><div class="detail-label">Notes</div><div class="detail-value"><?=e($cust['notes'])?></div></div><?php endif;?>
</div>

<div class="card" style="margin-top:16px">
 <div class="card-header"><div class="card-title"><i class="fas fa-mobile-alt"></i> Devices (<?=$cust['device_limit']?> max)</div><button class="btn btn-secondary btn-sm" onclick="openModal('addDevModal')"><i class="fas fa-plus"></i> Add Device</button></div>
 <div class="table-wrap"><table><thead><tr><th>#</th><th>Device Name</th><th>MAC Address</th><th>Status</th><th>Added</th><th>Action</th></tr></thead><tbody>
 <?php $di=0; while($d=$devices->fetchArray(SQLITE3_ASSOC)):$di++;?>
 <tr><td class="mono" style="color:var(--muted)"><?=$di?></td><td><?=e($d['device_name'])?></td><td class="mono" style="font-size:12px"><?=e($d['mac_address'])?></td><td><span class="badge badge-green">active</span></td><td style="font-size:11px;color:var(--muted)"><?=$d['added_at']?></td>
 <td><a href="?delete_device=<?=$d['id']?>&cid=<?=$cid?>&page=customer_detail&id=<?=$cid?>" class="btn btn-danger btn-xs" onclick="return confirm('Remove this device?')"><i class="fas fa-trash"></i></a></td></tr>
 <?php endwhile; if($di===0):?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:16px">No devices registered</td></tr><?php endif;?></tbody></table></div>
</div>

<div class="card" style="margin-top:16px">
 <div class="card-header"><div class="card-title"><i class="fas fa-box"></i> Package History</div></div>
 <div class="table-wrap"><table><thead><tr><th>Days</th><th>Start</th><th>End</th><th>Fee</th><th>Status</th></tr></thead><tbody>
 <?php while($pk=$all_pkgs->fetchArray(SQLITE3_ASSOC)):?>
 <tr><td><?=$pk['days']?> days</td><td class="mono"><?=$pk['start_date']?></td><td class="mono"><?=$pk['end_date']?></td><td class="mono"><?=$pk['fee']?> SAR</td><td><span class="badge <?=$pk['status']==='active'?'badge-green':'badge-gray'?>"><?=$pk['status']?></span></td></tr>
 <?php endwhile;?></tbody></table></div>
</div>

<!-- Add Package Modal -->
<div class="modal-overlay" id="addPkgModal">
 <div class="modal-box">
  <div class="modal-head"><h5><i class="fas fa-box" style="color:var(--success)"></i> Add Package — <?=e($cust['name'])?></h5><button class="modal-close" onclick="closeModal('addPkgModal')">×</button></div>
  <div class="modal-body">
   <form method="post">
    <input type="hidden" name="action" value="add_package">
    <input type="hidden" name="customer_id" value="<?=$cid?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
     <div><label class="form-label">Package Days *</label><input type="number" name="days" class="form-control" value="30" min="1" required></div>
     <div><label class="form-label">Fee (SAR)</label><input type="number" name="fee" class="form-control" value="<?=$cust['monthly_fee']?>" step="0.5"></div>
    </div>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> Package starts today and expires after the set number of days. All devices will be active during this period.</div>
    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Activate Package</button>
   </form>
  </div>
 </div>
</div>

<!-- Add Device Modal -->
<div class="modal-overlay" id="addDevModal">
 <div class="modal-box">
  <div class="modal-head"><h5><i class="fas fa-mobile-alt" style="color:var(--accent)"></i> Add Device — <?=e($cust['name'])?></h5><button class="modal-close" onclick="closeModal('addDevModal')">×</button></div>
  <div class="modal-body">
   <form method="post">
    <input type="hidden" name="action" value="add_device">
    <input type="hidden" name="customer_id" value="<?=$cid?>">
    <div style="margin-bottom:10px"><label class="form-label">Device Name</label><input type="text" name="device_name" class="form-control" placeholder="e.g. iPhone, Samsung, Laptop"></div>
    <div style="margin-bottom:14px"><label class="form-label">MAC Address *</label><input type="text" name="mac_address" class="form-control" placeholder="e.g. AA:BB:CC:DD:EE:FF" required></div>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> Enter the device MAC address exactly. Limit: <?=$cust['device_limit']?> devices.</div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Device</button>
   </form>
  </div>
 </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal-overlay" id="editCustModal">
 <div class="modal-box modal-box-lg">
  <div class="modal-head"><h5><i class="fas fa-edit" style="color:var(--accent)"></i> Edit Customer</h5><button class="modal-close" onclick="closeModal('editCustModal')">×</button></div>
  <div class="modal-body">
   <form method="post">
    <input type="hidden" name="action" value="save_customer">
    <input type="hidden" name="id" id="edit_id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Full Name</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
     <div><label class="form-label">Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Building</label><input type="text" name="building" id="edit_building" class="form-control"></div>
     <div><label class="form-label">Room</label><input type="text" name="room" id="edit_room" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Nationality</label><input type="text" name="nationality" id="edit_nat" class="form-control"></div>
     <div><label class="form-label">Iqama/ID <small>(optional)</small></label><input type="text" name="iqama_number" id="edit_iqama" class="form-control"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
     <div><label class="form-label">Device Limit</label><input type="number" name="device_limit" id="edit_dlimit" class="form-control" min="1" max="10"></div>
     <div><label class="form-label">Monthly Fee</label><input type="number" name="monthly_fee" id="edit_fee" class="form-control" step="0.5"></div>
     <div><label class="form-label">Status</label><select name="status" id="edit_status" class="form-select"><option value="active">Active</option><option value="suspended">Suspended</option></select></div>
    </div>
    <div style="margin-bottom:14px"><label class="form-label">Notes</label><textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea></div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
   </form>
  </div>
 </div>
</div>
<?php }?>

<?php elseif($page==='packages'): ?>
<!-- PACKAGES -->
<div class="card">
 <div class="card-header"><div class="card-title"><i class="fas fa-box"></i> All Active Packages</div></div>
 <div class="table-wrap"><table><thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Days</th><th>Start</th><th>Expires</th><th>Days Left</th><th>Fee</th><th>Status</th></tr></thead><tbody>
 <?php $pkgs=$db->query("SELECT p.*,c.name,c.phone FROM packages p JOIN customers c ON p.customer_id=c.id ORDER BY p.end_date ASC");
 $i=0; while($pk=$pkgs->fetchArray(SQLITE3_ASSOC)):$i++;
 $dl2=max(0,ceil((strtotime($pk['end_date'])-time())/86400));
 $is_exp=strtotime($pk['end_date'])<time();?>
 <tr<?=$is_exp?' style="background:rgba(239,68,68,.05)"':''?>>
  <td class="mono" style="color:var(--muted)"><?=$i?></td>
  <td><a href="?page=customer_detail&id=<?=$pk['customer_id']?>" style="color:var(--accent);text-decoration:none"><?=e($pk['name'])?></a></td>
  <td class="mono"><?=e($pk['phone'])?></td>
  <td><?=$pk['days']?></td>
  <td class="mono" style="font-size:11px"><?=$pk['start_date']?></td>
  <td class="mono" style="font-size:11px;color:<?=$is_exp?'var(--danger)':($dl2<=3?'var(--warning)':'inherit')?>"><?=$pk['end_date']?></td>
  <td><?php if($is_exp):?><span class="badge badge-red">Expired</span><?php else:?><span class="badge <?=$dl2<=3?'badge-yellow':'badge-green'?>"><?=$dl2?>d</span><?php endif;?></td>
  <td class="mono"><?=$pk['fee']?> SAR</td>
  <td><span class="badge <?=$pk['status']==='active'?'badge-green':'badge-gray'?>"><?=$pk['status']?></span></td>
 </tr>
 <?php endwhile;?></tbody></table></div>
</div>

<?php elseif($page==='devices'): ?>
<!-- DEVICES -->
<div class="card">
 <div class="card-header"><div class="card-title"><i class="fas fa-mobile-alt"></i> All Registered Devices</div></div>
 <div class="table-wrap"><table><thead><tr><th>#</th><th>Customer</th><th>Device Name</th><th>MAC Address</th><th>Status</th><th>Added</th></tr></thead><tbody>
 <?php $devs=$db->query("SELECT d.*,c.name as cname FROM devices d JOIN customers c ON d.customer_id=c.id WHERE d.status='active' ORDER BY c.name");
 $i=0; while($d=$devs->fetchArray(SQLITE3_ASSOC)):$i++;?>
 <tr><td class="mono" style="color:var(--muted)"><?=$i?></td>
  <td><a href="?page=customer_detail&id=<?=$d['customer_id']?>" style="color:var(--accent);text-decoration:none"><?=e($d['cname'])?></a></td>
  <td><?=e($d['device_name'])?></td>
  <td class="mono" style="font-size:12px"><?=e($d['mac_address'])?></td>
  <td><span class="badge badge-green">active</span></td>
  <td style="font-size:11px;color:var(--muted)"><?=$d['added_at']?></td>
 </tr>
 <?php endwhile;?></tbody></table></div>
</div>

<?php elseif($page==='logs'): ?>
<!-- LOGS / LEGAL COMPLIANCE -->
<div class="card">
 <div class="card-header">
  <div class="card-title"><i class="fas fa-shield-alt"></i> Connection Logs — Legal Compliance</div>
  <a href="?page=reports" class="btn btn-primary btn-sm"><i class="fas fa-file-pdf"></i> Generate Report</a>
 </div>
 <div class="table-wrap"><table><thead><tr><th>Time</th><th>Customer</th><th>MAC Address</th><th>IP Address</th><th>Event</th><th>Data Used</th></tr></thead><tbody>
 <?php $logs=$db->query("SELECT l.*,c.name FROM connection_logs l LEFT JOIN customers c ON l.customer_id=c.id ORDER BY l.timestamp DESC LIMIT 200");
 while($l=$logs->fetchArray(SQLITE3_ASSOC)):?>
 <tr><td class="mono" style="font-size:11px"><?=$l['timestamp']?></td>
  <td><?=e($l['name']??'Unknown')?></td>
  <td class="mono" style="font-size:11px"><?=e($l['mac_address'])?></td>
  <td class="mono" style="font-size:11px"><?=e($l['ip_address'])?></td>
  <td><span class="badge <?=$l['event']==='connect'?'badge-green':($l['event']==='disconnect'?'badge-gray':'badge-yellow')?>"><?=$l['event']?></span></td>
  <td class="mono"><?=$l['data_used_mb']?> MB</td>
 </tr>
 <?php endwhile;?></tbody></table></div>
</div>

<?php elseif($page==='reports'): ?>
<!-- REPORTS -->
<div class="card">
 <div class="card-header"><div class="card-title"><i class="fas fa-file-alt"></i> Reports</div></div>
 <div class="card-body">
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
   <a href="?page=reports&type=active" class="btn btn-primary"><i class="fas fa-users"></i> Active Customers</a>
   <a href="?page=reports&type=expired" class="btn btn-danger"><i class="fas fa-times-circle"></i> Expired Packages</a>
   <a href="?page=reports&type=expiring" class="btn btn-warning"><i class="fas fa-clock"></i> Expiring Soon</a>
   <a href="?page=reports&type=devices" class="btn btn-secondary"><i class="fas fa-mobile-alt"></i> All Devices</a>
   <a href="?page=reports&type=legal" class="btn btn-secondary"><i class="fas fa-shield-alt"></i> Legal Report</a>
  </div>
 </div>
</div>
<?php if(isset($_GET['type'])):?>
<div class="card">
 <div class="card-header">
  <div class="card-title"><i class="fas fa-file-alt"></i> Report: <?=e($_GET['type'])?></div>
  <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Print / Save PDF</button>
 </div>
 <div class="table-wrap">
 <?php $type=$_GET['type'];
 if($type==='legal'){?>
  <table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Nationality</th><th>Iqama/ID</th><th>Building/Room</th><th>MAC Addresses</th><th>Status</th><th>Registered</th></tr></thead><tbody>
  <?php $r=$db->query("SELECT c.*,GROUP_CONCAT(d.mac_address,', ') as macs FROM customers c LEFT JOIN devices d ON c.id=d.customer_id AND d.status='active' GROUP BY c.id ORDER BY c.name");
  $i=0; while($row=$r->fetchArray(SQLITE3_ASSOC)):$i++;?>
  <tr><td><?=$i?></td><td><?=e($row['name'])?></td><td class="mono"><?=e($row['phone'])?></td><td><?=e($row['nationality'])?></td><td class="mono"><?=e($row['iqama_number'])?></td><td><?=e($row['building'])?> <?=e($row['room'])?></td><td class="mono" style="font-size:11px"><?=e($row['macs'])?></td><td><?=$row['status']?></td><td class="mono" style="font-size:11px"><?=$row['created_at']?></td></tr>
  <?php endwhile;?></tbody></table>
 <?php }elseif($type==='active'){?>
  <table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Room</th><th>Devices</th><th>Expires</th><th>Days Left</th></tr></thead><tbody>
  <?php $r=$db->query("SELECT c.*,p.end_date FROM customers c LEFT JOIN packages p ON c.id=p.customer_id AND p.status='active' WHERE c.status='active' ORDER BY p.end_date");
  $i=0; while($row=$r->fetchArray(SQLITE3_ASSOC)):$i++;$dl=getDaysLeft($db,$row['id']);?>
  <tr><td><?=$i?></td><td><?=e($row['name'])?></td><td class="mono"><?=e($row['phone'])?></td><td><?=e($row['room'])?></td><td><?=$db->querySingle("SELECT COUNT(*) FROM devices WHERE customer_id={$row['id']} AND status='active'")?></td><td class="mono"><?=$row['end_date']??'—'?></td><td><?=$dl?> days</td></tr>
  <?php endwhile;?></tbody></table>
 <?php }?>
 </div>
</div>
<?php endif;?>

<?php elseif($page==='settings' && isMaster()): ?>
<!-- SETTINGS -->
<div class="card">
 <div class="card-header"><div class="card-title"><i class="fas fa-cog"></i> System Settings</div></div>
 <div class="card-body">
  <form method="post">
   <input type="hidden" name="action" value="save_settings">
   <h6 style="font-weight:700;margin-bottom:12px;color:var(--muted)">GENERAL</h6>
   <div style="margin-bottom:10px"><label class="form-label">System Name</label><input type="text" name="system_name" class="form-control" value="<?=e(getSetting($db,'system_name'))?>"></div>
   <hr class="divider">
   <h6 style="font-weight:700;margin-bottom:12px;color:var(--muted)">MIKROTIK</h6>
   <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
    <div><label class="form-label">Mikrotik IP</label><input type="text" name="mikrotik_ip" class="form-control" value="<?=e(getSetting($db,'mikrotik_ip'))?>"></div>
    <div><label class="form-label">Username</label><input type="text" name="mikrotik_user" class="form-control" value="<?=e(getSetting($db,'mikrotik_user'))?>"></div>
    <div><label class="form-label">Password</label><input type="password" name="mikrotik_pass" class="form-control" placeholder="Leave blank to keep"></div>
   </div>
   <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
    <div><label class="form-label">API Port</label><input type="text" name="mikrotik_port" class="form-control" value="<?=e(getSetting($db,'mikrotik_port'))?>"></div>
    <div><label class="form-label">RADIUS Secret</label><input type="text" name="radius_secret" class="form-control" value="<?=e(getSetting($db,'radius_secret'))?>"></div>
   </div>
   <hr class="divider">
   <h6 style="font-weight:700;margin-bottom:12px;color:var(--muted)">WHATSAPP (OPENWA)</h6>
   <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
    <div><label class="form-label">OpenWA URL</label><input type="text" name="openwa_url" class="form-control" value="<?=e(getSetting($db,'openwa_url'))?>"></div>
    <div><label class="form-label">API Key</label><input type="text" name="openwa_api_key" class="form-control" value="<?=e(getSetting($db,'openwa_api_key'))?>"></div>
   </div>
   <div style="margin-bottom:10px"><label class="form-label">Session ID</label><input type="text" name="openwa_session_id" class="form-control" value="<?=e(getSetting($db,'openwa_session_id'))?>"></div>
   <hr class="divider">
   <h6 style="font-weight:700;margin-bottom:12px;color:var(--muted)">WHATSAPP MESSAGES</h6>
   <div style="margin-bottom:10px"><label class="form-label">Welcome Message</label><textarea name="whatsapp_welcome" class="form-control" rows="2"><?=e(getSetting($db,'whatsapp_welcome'))?></textarea></div>
   <div style="margin-bottom:10px"><label class="form-label">Expiry Warning <small>(use {name} and {days})</small></label><textarea name="whatsapp_expiry" class="form-control" rows="2"><?=e(getSetting($db,'whatsapp_expiry'))?></textarea></div>
   <div style="margin-bottom:14px"><label class="form-label">Expired Message <small>(use {name})</small></label><textarea name="whatsapp_expired" class="form-control" rows="2"><?=e(getSetting($db,'whatsapp_expired'))?></textarea></div>
   <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
  </form>
  <hr class="divider">
  <h6 style="font-weight:700;margin-bottom:12px;color:var(--muted)">CHANGE PASSWORD</h6>
  <form method="post" style="max-width:400px">
   <input type="hidden" name="action" value="change_password">
   <div style="margin-bottom:10px"><label class="form-label">Current Password</label><input type="password" name="old_password" class="form-control" required></div>
   <div style="margin-bottom:14px"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required></div>
   <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Change Password</button>
  </form>
 </div>
</div>
<?php endif;?>

</div><!-- main -->
</div><!-- app -->
<?php endif;?>

<script>
const t=localStorage.getItem('cn_theme')||'light';
document.documentElement.setAttribute('data-theme',t);
function updateThemeIcon(){const t=document.documentElement.getAttribute('data-theme');const i=document.getElementById('theme-icon');if(i)i.className=t==='dark'?'fas fa-sun':'fas fa-moon';}
updateThemeIcon();
function toggleTheme(){const c=document.documentElement.getAttribute('data-theme');const n=c==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',n);localStorage.setItem('cn_theme',n);updateThemeIcon();}
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
document.addEventListener('click',e=>{if(e.target.classList.contains('modal-overlay'))e.target.classList.remove('show');});
setTimeout(()=>{const a=document.getElementById('top-msg');if(a)a.remove();},5000);
function openApprove(id,phone,mac){
 document.getElementById('ap_id').value=id;
 document.getElementById('ap_phone').textContent=phone;
 document.getElementById('ap_mac').textContent=mac;
 document.getElementById('ap_phone_input').value=phone;
 openModal('approveModal');
}
function openEditCust(c){
 document.getElementById('edit_id').value=c.id;
 document.getElementById('edit_name').value=c.name||'';
 document.getElementById('edit_phone').value=c.phone||'';
 document.getElementById('edit_building').value=c.building||'';
 document.getElementById('edit_room').value=c.room||'';
 document.getElementById('edit_nat').value=c.nationality||'';
 document.getElementById('edit_iqama').value=c.iqama_number||'';
 document.getElementById('edit_dlimit').value=c.device_limit||1;
 document.getElementById('edit_fee').value=c.monthly_fee||30;
 document.getElementById('edit_notes').value=c.notes||'';
 document.getElementById('edit_status').value=c.status||'active';
 openModal('editCustModal');
}
const cs=document.getElementById('custSearch');
if(cs){cs.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('#custTbody tr').forEach(r=>{const t=[r.dataset.name,r.dataset.phone,r.dataset.room,r.dataset.building].join(' ').toLowerCase();r.style.display=t.includes(q)?'':'none';});});}
function sendWa(phone,name){
 const msg=`Dear ${name}, this is a message from CyberNet ISP. Please contact us if you need assistance.`;
 const sid='<?=getSetting($db,"openwa_session_id")?>';
 const url='<?=getSetting($db,"openwa_url")?>';
 const key='<?=getSetting($db,"openwa_api_key")?>';
 let p=phone.replace(/^0+/,'');
 if(!/^966/.test(p))p='966'+p;
 fetch(`${url}/api/sessions/${sid}/messages/send-text`,{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':key},body:JSON.stringify({chatId:p+'@c.us',text:msg})})
 .then(r=>r.ok?alert('✅ WhatsApp sent to '+name):alert('❌ Failed. Check WhatsApp tab.')).catch(()=>alert('❌ Network error'));
}
</script>
</body>
</html>

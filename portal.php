<?php
// ============================================================
// ISP BILLING PORTAL — v2.0  (fixed & polished)
// ============================================================
session_start();
date_default_timezone_set('Asia/Riyadh');

$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
$db->busyTimeout(5000);

// ============================================================
// TABLES
// ============================================================
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY, username TEXT UNIQUE,
    password TEXT, fullname TEXT, role TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY, name TEXT, mobile TEXT UNIQUE,
    building TEXT, apartment TEXT, room TEXT,
    billing_day INTEGER DEFAULT 1,
    billing_start_date TEXT,
    monthly_fee REAL DEFAULT 30,
    status TEXT DEFAULT 'active',
    created_by INTEGER, created_at TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY, customer_id INTEGER,
    month_year TEXT, amount REAL,
    collected_by INTEGER, collected_date TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY, user_id INTEGER,
    action TEXT, details TEXT, timestamp TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY, title TEXT, description TEXT,
    filename TEXT, original_name TEXT, size INTEGER,
    mime TEXT, uploaded_by INTEGER, upload_date TEXT,
    downloads INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY, value TEXT)");

// Safe column migrations
$col_check = $db->query("PRAGMA table_info(customers)");
$existing = [];
while ($r = $col_check->fetchArray(SQLITE3_ASSOC)) $existing[] = $r['name'];
if (!in_array('monthly_fee',        $existing)) $db->exec("ALTER TABLE customers ADD COLUMN monthly_fee REAL DEFAULT 30");
if (!in_array('billing_day',        $existing)) $db->exec("ALTER TABLE customers ADD COLUMN billing_day INTEGER DEFAULT 1");
if (!in_array('billing_start_date', $existing)) $db->exec("ALTER TABLE customers ADD COLUMN billing_start_date TEXT");

// Add is_settled to collections
$col_check2 = $db->query("PRAGMA table_info(collections)");
$existing2 = [];
while ($r2 = $col_check2->fetchArray(SQLITE3_ASSOC)) $existing2[] = $r2['name'];
if (!in_array('is_settled', $existing2)) $db->exec("ALTER TABLE collections ADD COLUMN is_settled INTEGER DEFAULT 0");

// Default settings
$defaults = [
    'openwa_url'       => 'http://10.12.14.16:2785',
    'openwa_api_key'   => 'dev-admin-key',
    'openwa_session_id'=> '84ecd217-e6bc-4b7e-ac92-1d09cb3f0be7',
    'movie_server'     => 'http://10.12.14.16:8082',
    'support_1_name'   => 'Cyber Net',
    'support_1_phone'  => '+966594266584',
    'support_2_name'   => 'Riyad Hossain',
    'support_2_phone'  => '+966546377863',
    'support_3_name'   => 'Jahir Hossain',
    'support_3_phone'  => '+966542349510',
];
foreach ($defaults as $k => $v) {
    $ek = SQLite3::escapeString($k);
    $ev = SQLite3::escapeString($v);
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('$ek','$ev')");
}

// ============================================================
// HELPERS
// ============================================================
function isLoggedIn() { return isset($_SESSION['user_id']); }
function isMaster()   { return isset($_SESSION['role']) && $_SESSION['role'] === 'master'; }
function isAdmin()    { return isset($_SESSION['role']) && in_array($_SESSION['role'], ['master','admin']); }

function logAction($db, $uid, $action, $details) {
    $a = SQLite3::escapeString($action);
    $d = SQLite3::escapeString($details);
    $db->exec("INSERT INTO audit_log (user_id,action,details,timestamp) VALUES ($uid,'$a','$d',datetime('now'))");
}
function getSetting($db, $key) {
    return $db->querySingle("SELECT value FROM settings WHERE key='".SQLite3::escapeString($key)."'") ?: '';
}
function setSetting($db, $key, $value) {
    $k = SQLite3::escapeString($key);
    $v = SQLite3::escapeString($value);
    $db->exec("INSERT OR REPLACE INTO settings (key,value) VALUES ('$k','$v')");
}

function sendWhatsAppMessage($db, $mobile, $message) {
    $base  = getSetting($db,'openwa_url');
    $key   = getSetting($db,'openwa_api_key');
    $sid   = getSetting($db,'openwa_session_id');
    if (!$sid || !$base) return false;
    $phone = preg_replace('/^0+/','', $mobile);
    if (!preg_match('/^966/',$phone)) $phone = '966'.$phone;
    $chatId = $phone.'@c.us';
    $url = "$base/api/sessions/$sid/messages/send-text";
    $ch  = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","X-API-Key: $key"],
        CURLOPT_POSTFIELDS=>json_encode(['chatId'=>$chatId,'text'=>$message]),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>10,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

function getEffectiveBillingStart($billing_start_date, $billing_day) {
    if (!$billing_start_date) return new DateTime(date('Y-m-01'));
    $dt      = new DateTime($billing_start_date);
    $join_day = (int)$dt->format('j');
    if ($join_day > (int)$billing_day) {
        $dt->modify('first day of next month');
    } else {
        $dt = new DateTime($dt->format('Y-m').'-01');
    }
    return $dt;
}

function getUnpaidMonths($db, $customer_id, $current_month) {
    $c = $db->querySingle("SELECT billing_start_date, billing_day, monthly_fee FROM customers WHERE id=$customer_id", true);
    if (!$c) return [];
    $fee = max(1, floatval($c['monthly_fee'] ?: 30));
    $bd  = (int)($c['billing_day'] ?: 1);
    $start = getEffectiveBillingStart($c['billing_start_date'], $bd);
    $end   = (new DateTime($current_month.'-01'))->modify('+1 month');
    $months = [];
    $period = new DatePeriod($start, new DateInterval('P1M'), $end);
    foreach ($period as $dt) $months[] = $dt->format('Y-m');

    $res = $db->query("SELECT month_year, SUM(amount) as total, MAX(is_settled) as settled FROM collections WHERE customer_id=$customer_id GROUP BY month_year");
    $paid = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $paid[$r['month_year']] = ['total'=>floatval($r['total']),'settled'=>intval($r['settled'])];

    $unpaid = [];
    foreach ($months as $m) {
        $p = $paid[$m] ?? null;
        // Skip if settled or waived (0 SAR with record)
        if ($p && ($p['settled'] == 1 || $p['total'] == 0)) continue;
        $paid_amt = $p ? $p['total'] : 0;
        $due = round($fee - $paid_amt, 2);
        if ($due > 0) $unpaid[] = ['month'=>$m,'due'=>$due,'fee'=>$fee];
    }
    return $unpaid;
}

function buildReceiptMsg($db, $customer_id, $customer_name, $collector, $paid_items, $total_paid) {
    $current_month = date('Y-m');
    $movie  = getSetting($db,'movie_server');
    $s1n    = getSetting($db,'support_1_name');
    $s1p    = getSetting($db,'support_1_phone');
    $s2n    = getSetting($db,'support_2_name');
    $s2p    = getSetting($db,'support_2_phone');
    $s3n    = getSetting($db,'support_3_name');
    $s3p    = getSetting($db,'support_3_phone');

    $msg  = "✅ PAYMENT RECEIPT\n\n";
    $msg .= "Customer : $customer_name\n";
    $msg .= "Date     : ".date("d M Y H:i")."\n";
    $msg .= "Collected: $collector\n\n";
    $msg .= "💳 Payment Details:\n";
    foreach ($paid_items as $item) {
        $label = $item['amount'] == 0 ? 'WAIVED' : 'PAID';
        $msg .= "  $label ".date('M Y', strtotime($item['month'].'-01')).": {$item['amount']} SAR\n";
    }
    $msg .= "\nTotal paid today: $total_paid SAR\n\n";

    $remaining = getUnpaidMonths($db, $customer_id, $current_month);
    if (!empty($remaining)) {
        $msg .= "⚠️ Remaining Balance:\n";
        foreach ($remaining as $r) {
            $msg .= "  ".date('M Y', strtotime($r['month'].'-01')).": {$r['due']} SAR\n";
        }
    } else {
        $msg .= "✅ All bills are paid. Thank you!\n";
    }
    $msg .= "\n🎬 Movies: $movie\n\n";
    $msg .= "📞 Support (24/7):\n";
    $msg .= "  $s1n: $s1p\n  $s2n: $s2p\n  $s3n: $s3p\n\n";
    $msg .= "Thank you for your payment! 🙏";
    return $msg;
}

// ============================================================
// AJAX / API HANDLERS
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'wa_status' && isLoggedIn()) {
    header('Content-Type: application/json');
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $sid  = getSetting($db,'openwa_session_id');
    $ch = curl_init("$base/api/sessions/$sid");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,
        CURLOPT_HTTPHEADER=>["X-API-Key: $key"]]);
    $r = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $r ? json_decode($r,true) : null;
    echo json_encode(['http_code'=>$code,'session'=>$data,'session_id'=>$sid]);
    exit;
}

if ($action === 'wa_qr' && isLoggedIn()) {
    header('Content-Type: application/json');
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $sid  = getSetting($db,'openwa_session_id');
    $ch = curl_init("$base/api/sessions/$sid/qr");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["X-API-Key: $key"]]);
    $r = curl_exec($ch); curl_close($ch);
    echo $r ?: json_encode(['error'=>'No response from OpenWA']);
    exit;
}

if ($action === 'wa_reconnect' && isLoggedIn() && isMaster()) {
    header('Content-Type: application/json');
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $old  = getSetting($db,'openwa_session_id');
    $log  = [];

    $ch = curl_init("$base/api/sessions/$old");
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>["X-API-Key: $key"]]);
    curl_exec($ch); curl_close($ch);
    $log[] = 'Old session deleted';

    $ch = curl_init("$base/api/sessions");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","X-API-Key: $key"],
        CURLOPT_POSTFIELDS=>json_encode(['name'=>'billing-bot'])]);
    $r = curl_exec($ch); curl_close($ch);
    $created = json_decode($r, true);
    $new_id  = $created['id'] ?? null;
    if (!$new_id) { echo json_encode(['error'=>'Could not create session','raw'=>$r]); exit; }
    $log[] = "New session created: $new_id";

    $ch = curl_init("$base/api/sessions/$new_id/start");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","X-API-Key: $key"],
        CURLOPT_POSTFIELDS=>'{}']);
    curl_exec($ch); curl_close($ch);
    $log[] = 'Session started';

    setSetting($db,'openwa_session_id',$new_id);
    logAction($db,$_SESSION['user_id'],'WA_RECONNECT',"New session: $new_id");
    $log[] = 'Session ID saved to database';

    echo json_encode(['success'=>true,'new_session_id'=>$new_id,'log'=>$log]);
    exit;
}

// ============================================================
// AUTH
// ============================================================
if (isset($_GET['logout'])) { session_destroy(); header('Location: portal.php'); exit; }

if ($action === 'login') {
    $u = $db->querySingle("SELECT * FROM users WHERE username='".SQLite3::escapeString($_POST['username'])."'",true);
    if ($u && password_verify($_POST['password'],$u['password'])) {
        $_SESSION = ['user_id'=>$u['id'],'role'=>$u['role'],'fullname'=>$u['fullname'],'username'=>$u['username']];
        header('Location: portal.php'); exit;
    }
    $login_error = "Invalid username or password";
}

$page        = $_GET['page'] ?? 'dashboard';
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;

// ============================================================
// ADMIN MANAGEMENT
// ============================================================
if (isLoggedIn() && isMaster() && $action === 'save_admin') {
    $fn = SQLite3::escapeString($_POST['fullname']);
    $un = SQLite3::escapeString($_POST['username']);
    $pw = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username,password,fullname,role) VALUES ('$un','$pw','$fn','admin')");
    logAction($db,$_SESSION['user_id'],'ADD_ADMIN',"Added $un");
    $_SESSION['msg'] = "Admin added successfully.";
    header('Location: portal.php?page=admins'); exit;
}
if (isLoggedIn() && isMaster() && isset($_GET['delete_admin'])) {
    $id = intval($_GET['delete_admin']);
    $a  = $db->querySingle("SELECT username FROM users WHERE id=$id AND role='admin'",true);
    if ($a) { $db->exec("DELETE FROM users WHERE id=$id"); logAction($db,$_SESSION['user_id'],'DELETE_ADMIN',"Deleted {$a['username']}"); }
    header('Location: portal.php?page=admins'); exit;
}
if (isLoggedIn() && isMaster() && $action === 'edit_master') {
    $id = intval($_POST['master_id']);
    $fn = SQLite3::escapeString($_POST['fullname']);
    $un = SQLite3::escapeString($_POST['username']);
    if (!empty($_POST['new_password'])) {
        $pw = password_hash($_POST['new_password'],PASSWORD_DEFAULT);
        $db->exec("UPDATE users SET fullname='$fn',username='$un',password='$pw' WHERE id=$id");
    } else {
        $db->exec("UPDATE users SET fullname='$fn',username='$un' WHERE id=$id");
    }
    logAction($db,$_SESSION['user_id'],'EDIT_MASTER','Updated master account');
    session_destroy(); header('Location: portal.php'); exit;
}
if (isLoggedIn() && isMaster() && $action === 'reset_password') {
    $id = intval($_POST['user_id']);
    $pw = password_hash($_POST['new_password'],PASSWORD_DEFAULT);
    $db->exec("UPDATE users SET password='$pw' WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'RESET_PASSWORD',"Reset password for user $id");
    $_SESSION['msg'] = "Password reset.";
    header('Location: portal.php?page=admins'); exit;
}

// ============================================================
// COLLECTION
// ============================================================
if (isLoggedIn() && isAdmin() && $action === 'add_collection_partial') {
    $customer_id = intval($_POST['customer_id']);
    $months      = $_POST['months']  ?? [];
    $amounts     = $_POST['amounts'] ?? [];
    $customer    = $db->querySingle("SELECT name,mobile FROM customers WHERE id=$customer_id",true);

    $paid_items  = [];
    $total_paid  = 0;

    $settled_months = $_POST['settled'] ?? [];
    foreach ($months as $i => $month) {
        $amt = floatval($amounts[$i]);
        $month = SQLite3::escapeString($month);
        $is_settled = in_array($month, $settled_months) ? 1 : 0;
        if ($amt >= 0) {
            $db->exec("INSERT INTO collections (customer_id,month_year,amount,collected_by,collected_date,is_settled)
                VALUES ($customer_id,'$month',$amt,{$_SESSION['user_id']},datetime('now'),$is_settled)");
            $action_label = $is_settled ? 'SETTLE' : 'COLLECTION';
            logAction($db,$_SESSION['user_id'],$action_label,"{$customer['name']} — $month: $amt SAR".($is_settled?' (settled)':''));
            $paid_items[] = ['month'=>$month,'amount'=>$amt,'settled'=>$is_settled];
            $total_paid  += $amt;
        }
    }

    $sent = false;
    if (!empty($paid_items)) {
        $msg  = buildReceiptMsg($db,$customer_id,$customer['name'],$_SESSION['fullname'],$paid_items,$total_paid);
        $sent = sendWhatsAppMessage($db,$customer['mobile'],$msg);
    }

    $_SESSION['last_collection'] = [
        'name'     => $customer['name'],
        'amount'   => $total_paid,
        'datetime' => date('Y-m-d H:i:s'),
        'collector'=> $_SESSION['fullname'],
    ];
    $_SESSION['msg'] = "Payment recorded. WhatsApp receipt ".($sent?'sent.':'could not be sent (check OpenWA).');
    header('Location: portal.php?page=collections'); exit;
}

// ============================================================
// CUSTOMER CRUD
// ============================================================
if (isLoggedIn() && isAdmin() && $action === 'save_customer') {
    $id          = intval($_POST['id'] ?? 0);
    $name        = SQLite3::escapeString($_POST['name']);
    $mobile      = SQLite3::escapeString($_POST['mobile']);
    $building    = SQLite3::escapeString($_POST['building']);
    $apartment   = SQLite3::escapeString($_POST['apartment']);
    $room        = SQLite3::escapeString($_POST['room']);
    $billing_day = intval($_POST['billing_day']);
    $join_date   = SQLite3::escapeString($_POST['billing_start_date']);
    $monthly_fee = max(1, floatval($_POST['monthly_fee']));

    if ($id) {
        $db->exec("UPDATE customers SET name='$name',mobile='$mobile',building='$building',
            apartment='$apartment',room='$room',billing_day=$billing_day,
            billing_start_date='$join_date',monthly_fee=$monthly_fee WHERE id=$id");
        logAction($db,$_SESSION['user_id'],'EDIT_CUSTOMER',"Updated customer ID $id");
    } else {
        $db->exec("INSERT INTO customers (name,mobile,building,apartment,room,billing_day,
            billing_start_date,monthly_fee,created_by,created_at)
            VALUES ('$name','$mobile','$building','$apartment','$room',
            $billing_day,'$join_date',$monthly_fee,{$_SESSION['user_id']},datetime('now'))");
        logAction($db,$_SESSION['user_id'],'ADD_CUSTOMER',"Added $name");
    }
    header('Location: portal.php?page=customers'); exit;
}

if (isLoggedIn() && isAdmin() && isset($_GET['delete_customer'])) {
    $id = intval($_GET['delete_customer']);
    $c  = $db->querySingle("SELECT name FROM customers WHERE id=$id",true);
    $db->exec("DELETE FROM customers WHERE id=$id");
    $db->exec("DELETE FROM collections WHERE customer_id=$id");
    logAction($db,$_SESSION['user_id'],'DELETE_CUSTOMER',"Deleted {$c['name']}");
    header('Location: portal.php?page=customers'); exit;
}

if (isLoggedIn() && isMaster() && $action === 'edit_collection') {
    $id  = intval($_POST['collection_id']);
    $amt = floatval($_POST['amount']);
    $old = $db->querySingle("SELECT amount FROM collections WHERE id=$id",true);
    $db->exec("UPDATE collections SET amount=$amt WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'EDIT_COLLECTION',"Collection $id: {$old['amount']}→$amt SAR");
    $_SESSION['msg'] = "Collection updated.";
    header('Location: portal.php?page=report'); exit;
}

if (isLoggedIn() && isMaster() && isset($_GET['delete_collection'])) {
    $id = intval($_GET['delete_collection']);
    $db->exec("DELETE FROM collections WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'DELETE_COLLECTION',"Deleted collection $id");
    $_SESSION['msg'] = "Collection deleted.";
    header('Location: portal.php?page=report'); exit;
}

// ============================================================
// FILE MANAGEMENT
// ============================================================
if (isset($_FILES['upload_file']) && isLoggedIn()) {
    $dir  = '/mnt/bigstorage/files/';
    $title = SQLite3::escapeString($_POST['title']);
    $desc  = SQLite3::escapeString($_POST['description']);
    $file  = $_FILES['upload_file'];
    $new_name = time().'_'.basename($file['name']);
    if (move_uploaded_file($file['tmp_name'], $dir.$new_name)) {
        $on   = SQLite3::escapeString($file['name']);
        $db->exec("INSERT INTO files (title,description,filename,original_name,size,uploaded_by,upload_date)
            VALUES ('$title','$desc','$new_name','$on',{$file['size']},{$_SESSION['user_id']},datetime('now'))");
        logAction($db,$_SESSION['user_id'],'UPLOAD_FILE',"Uploaded {$file['name']}");
        $_SESSION['msg'] = "File uploaded.";
    }
    header('Location: portal.php?page=files'); exit;
}
if (isset($_GET['delete_file']) && isMaster()) {
    $id = intval($_GET['delete_file']);
    $f  = $db->querySingle("SELECT filename,original_name FROM files WHERE id=$id",true);
    if ($f) { @unlink('/mnt/bigstorage/files/'.$f['filename']); $db->exec("DELETE FROM files WHERE id=$id"); }
    header('Location: portal.php?page=files'); exit;
}
if (isset($_GET['download'])) {
    $id = intval($_GET['download']);
    $f  = $db->querySingle("SELECT filename,original_name FROM files WHERE id=$id",true);
    if ($f && file_exists('/mnt/bigstorage/files/'.$f['filename'])) {
        $db->exec("UPDATE files SET downloads=downloads+1 WHERE id=$id");
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.addslashes($f['original_name']).'"');
        readfile('/mnt/bigstorage/files/'.$f['filename']); exit;
    }
}

// ============================================================
// DATA LOADING
// ============================================================
$current_month  = date('Y-m');
$display_month  = $_GET['stats_month'] ?? $current_month;

$pending_customers = [];
$all_c = $db->query("SELECT id,name,mobile,building,apartment,room,billing_day,billing_start_date,monthly_fee FROM customers WHERE status='active'");
while ($c = $all_c->fetchArray(SQLITE3_ASSOC)) {
    $unpaid = getUnpaidMonths($db,$c['id'],$current_month);
    if (!empty($unpaid)) {
        $c['unpaid']       = $unpaid;
        $c['oldest_unpaid']= $unpaid[0]['month'];
        $pending_customers[] = $c;
    }
}
usort($pending_customers, fn($a,$b) => strcmp($a['oldest_unpaid'],$b['oldest_unpaid']));

$total_customers = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='active'");
$collected_count = $db->querySingle("SELECT COUNT(DISTINCT customer_id) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month' AND amount>0");
$total_amount    = $db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month'");
$pending_count   = $total_customers - $collected_count;
$all_collections = $db->query("SELECT c.id,c.customer_id,cu.name AS cname,c.month_year,c.amount,c.collected_date,u.fullname AS collector
    FROM collections c JOIN customers cu ON c.customer_id=cu.id JOIN users u ON c.collected_by=u.id
    ORDER BY c.collected_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>CyberNet ISP Billing</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f4f8;--surface:#ffffff;--surface2:#f7fafc;--border:#e2e8f0;
  --text:#1a202c;--text-muted:#718096;--accent:#0ea5e9;--accent-dark:#0284c7;
  --success:#10b981;--danger:#ef4444;--warning:#f59e0b;--info:#6366f1;
  --nav-bg:#0f172a;--nav-text:#cbd5e1;--nav-active:#0ea5e9;
  --card-shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.04);--radius:12px;
}
[data-theme="dark"] {
  --bg:#0d1117;--surface:#161b22;--surface2:#1c2230;--border:#30363d;
  --text:#e6edf3;--text-muted:#8b949e;--accent:#38bdf8;--accent-dark:#0ea5e9;
  --success:#34d399;--danger:#f87171;--warning:#fbbf24;--info:#818cf8;
  --nav-bg:#010409;--nav-text:#8b949e;--nav-active:#38bdf8;
  --card-shadow:0 1px 3px rgba(0,0,0,.4),0 4px 16px rgba(0,0,0,.3);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:14px;line-height:1.6;transition:background .25s,color .25s;overflow-x:hidden}
.app-wrapper{display:flex;min-height:100vh;flex-direction:column}
.topbar{background:var(--nav-bg);padding:0 20px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1000;border-bottom:1px solid rgba(255,255,255,.06);gap:12px}
.topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:#fff;flex-shrink:0}
.topbar-brand .brand-icon{width:32px;height:32px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
.topbar-brand .brand-name{font-weight:700;font-size:15px;letter-spacing:.02em}
.topbar-brand .brand-name span{color:var(--accent)}
.nav-tabs-wrap{display:flex;overflow-x:auto;gap:2px;scrollbar-width:none;flex:1;justify-content:center}
.nav-tabs-wrap::-webkit-scrollbar{display:none}
.nav-tabs-wrap a{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;color:var(--nav-text);text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap;transition:all .15s;border:1px solid transparent}
.nav-tabs-wrap a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-tabs-wrap a.active{background:rgba(14,165,233,.15);color:var(--nav-active);border-color:rgba(14,165,233,.3)}
.nav-tabs-wrap a i{font-size:12px;opacity:.8}
.topbar-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
.user-badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:4px 12px;font-size:12px;color:#cbd5e1;display:flex;align-items:center;gap:6px}
.user-badge .role{background:var(--accent);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700}
.btn-icon{width:34px;height:34px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#cbd5e1;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;text-decoration:none;font-size:13px}
.btn-icon:hover{background:rgba(255,255,255,.12);color:#fff}
.main-content{flex:1;padding:20px;max-width:1600px;margin:0 auto;width:100%}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:visible;margin-bottom:16px}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--surface)}
.card-header-title{font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px}
.card-header-title i{color:var(--accent);font-size:13px}
.card-body{padding:16px 18px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--card-shadow);transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)}
.stat-value{font-size:28px;font-weight:700;font-family:'IBM Plex Mono',monospace;line-height:1.1;margin-top:2px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.stat-blue{color:#3b82f6}.stat-icon-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.stat-green{color:#10b981}.stat-icon-green{background:rgba(16,185,129,.12);color:#10b981}
.stat-red{color:#ef4444}.stat-icon-red{background:rgba(239,68,68,.12);color:#ef4444}
.stat-teal{color:#0ea5e9}.stat-icon-teal{background:rgba(14,165,233,.12);color:#0ea5e9}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--surface2)}
thead th{padding:10px 12px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);border-bottom:2px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
tbody tr:hover{background:var(--surface2)}
tbody td{padding:10px 12px;vertical-align:middle}
.form-control,.form-select{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:'IBM Plex Sans',sans-serif;transition:border-color .15s,box-shadow .15s;width:100%}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.15)}
.form-label{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;display:block}
.input-group{display:flex;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-dark);border-color:var(--accent-dark)}
.btn-success{background:var(--success);color:#fff}
.btn-success:hover{filter:brightness(1.1)}
.btn-danger{background:var(--danger);color:#fff}
.btn-danger:hover{filter:brightness(1.1)}
.btn-warning{background:var(--warning);color:#000}
.btn-secondary{background:var(--surface2);color:var(--text);border-color:var(--border)}
.btn-secondary:hover{background:var(--border)}
.btn-whatsapp{background:#25D366;color:#fff}
.btn-whatsapp:hover{background:#128C7E}
.btn-sm{padding:4px 10px;font-size:12px;gap:4px}
.btn-xs{padding:2px 7px;font-size:11px;gap:3px;border-radius:6px}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;gap:4px}
.badge-green{background:rgba(16,185,129,.15);color:var(--success)}
.badge-red{background:rgba(239,68,68,.15);color:var(--danger)}
.badge-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.badge-yellow{background:rgba(245,158,11,.15);color:var(--warning)}
.badge-gray{background:var(--surface2);color:var(--text-muted);border:1px solid var(--border)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal-overlay.show{display:flex}
.modal-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:100%;max-width:600px;max-height:90vh;overflow-y:auto;animation:modalIn .2s ease}
.modal-box-lg{max-width:800px}
@keyframes modalIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
.modal-header-custom{padding:18px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header-custom h5{font-weight:700;font-size:16px;display:flex;align-items:center;gap:8px}
.modal-body-custom{padding:18px 20px}
.modal-close{width:28px;height:28px;border-radius:6px;border:none;background:var(--surface2);color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px}
.modal-close:hover{background:var(--danger);color:#fff}
.alert{padding:12px 16px;border-radius:10px;border:1px solid;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px}
.alert-info{background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.3);color:var(--info)}
.alert-success{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3);color:var(--success)}
.alert-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:var(--danger)}
.alert-warning{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.3);color:var(--warning)}
.alert-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:16px;flex-shrink:0}
.alert-close:hover{opacity:1}
.search-wrap{position:relative}
.search-wrap input{padding-left:36px}
.search-wrap .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
.search-results{position:absolute;top:100%;left:0;right:0;z-index:9999;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:var(--card-shadow);max-height:320px;overflow-y:auto;margin-top:4px}
.search-result-item{padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s;display:flex;align-items:center;justify-content:space-between}
.search-result-item:hover{background:var(--surface2)}
.search-result-item:last-child{border-bottom:none}
.wa-status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
.wa-status-dot.connected{background:#25D366;box-shadow:0 0 8px #25D366}
.wa-status-dot.disconnected{background:var(--danger)}
.wa-status-dot.waiting{background:var(--warning);animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.qr-container{border:2px dashed var(--border);border-radius:12px;padding:20px;text-align:center;min-height:200px;display:flex;align-items:center;justify-content:center}
.qr-container img{max-width:240px;border-radius:8px}
.mono{font-family:'IBM Plex Mono',monospace}
.text-muted{color:var(--text-muted)}
.divider{border:none;border-top:1px solid var(--border);margin:14px 0}
.due-tag{font-family:'IBM Plex Mono',monospace;font-size:12px;background:rgba(239,68,68,.1);color:var(--danger);padding:2px 7px;border-radius:5px}
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--nav-bg)}
.login-card{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.login-card .brand-row{text-align:center;margin-bottom:28px}
.login-card .brand-row .ico{width:56px;height:56px;background:var(--accent);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:22px;color:#fff;margin-bottom:12px}
.login-card .brand-row h2{color:#fff;font-size:20px;font-weight:700}
.login-card .brand-row p{color:#8b949e;font-size:13px}
.login-card .form-control{background:#0d1117;color:#e6edf3;border-color:#30363d}
.login-card .form-label{color:#8b949e}
.login-btn{background:var(--accent);color:#fff;width:100%;padding:10px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s}
.login-btn:hover{background:var(--accent-dark)}
@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .topbar-brand .brand-name{display:none}
  .main-content{padding:12px}
  .stat-value{font-size:22px}
  .user-badge .role-label{display:none}
  .wa-grid{grid-template-columns:1fr !important}
}

@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr 1fr}
  .topbar{padding:6px 8px;gap:4px;height:auto;flex-wrap:wrap}
  .topbar-brand{order:1}
  .topbar-right{order:2;margin-left:auto}
  .nav-tabs-wrap{order:3;width:100%;flex:0 0 100%;border-top:1px solid rgba(255,255,255,.08);padding-top:6px;justify-content:flex-start;margin-top:2px}
  .nav-tabs-wrap a{padding:5px 10px;font-size:11px;gap:4px}
  .nav-tabs-wrap a i{display:inline-block;font-size:11px;opacity:.8}
  .user-badge span:first-of-type{display:none}
  .user-badge .role{display:inline-block}
  .main-content{padding:8px}
  .card-body{padding:12px}
  .card-header{padding:10px 12px}
  .stat-card{padding:12px}
  .stat-value{font-size:20px}
  .stat-icon{width:36px;height:36px;font-size:14px}
  .btn-xs{padding:3px 8px;font-size:11px}
  .modal-box{margin:8px;max-height:95vh}
  .modal-body-custom{padding:12px}
  .input-group{flex-wrap:wrap}
  .input-group > div{min-width:100% !important}
  table{font-size:11px}
  thead th{padding:7px 6px;font-size:10px;white-space:nowrap}
  tbody td{padding:7px 6px;white-space:nowrap}
  .table-wrap,.card .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .due-tag{font-size:10px}
  .topbar-brand .brand-name{display:block;font-size:13px}
}
[data-theme="dark"] .card,
[data-theme="dark"] .modal-box,
[data-theme="dark"] .search-results,
[data-theme="dark"] .search-result-item{background:var(--surface) !important;color:var(--text) !important;border-color:var(--border) !important}
[data-theme="dark"] table{color:var(--text)}
[data-theme="dark"] thead tr{background:var(--surface2)}
[data-theme="dark"] tbody tr:hover{background:var(--surface2)}
[data-theme="dark"] tbody td,[data-theme="dark"] thead th{border-color:var(--border);color:var(--text)}
[data-theme="dark"] .form-control,[data-theme="dark"] .form-select{background:var(--surface2) !important;color:var(--text) !important;border-color:var(--border) !important}
[data-theme="dark"] .btn-secondary{background:var(--surface2);color:var(--text);border-color:var(--border)}
[data-theme="dark"] .card-header{background:var(--surface);border-color:var(--border)}
[data-theme="dark"] .modal-header-custom{border-color:var(--border)}
[data-theme="dark"] .modal-close{background:var(--surface2);color:var(--text-muted)}
[data-theme="dark"] .login-wrap{background:var(--nav-bg)}
</style>
</head>
<body>
<?php if (!isLoggedIn()): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="brand-row">
      <div class="ico"><i class="fas fa-wifi"></i></div>
      <h2>CyberNet ISP</h2>
      <p>Billing Management Portal</p>
    </div>
    <?php if (isset($login_error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $login_error ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div style="margin-bottom:14px">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
      </div>
      <div style="margin-bottom:20px">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
      </div>
      <button type="submit" class="login-btn"><i class="fas fa-sign-in-alt"></i> Sign In</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="app-wrapper">
<nav class="topbar">
  <a href="?page=dashboard" class="topbar-brand">
    <div class="brand-icon"><i class="fas fa-wifi"></i></div>
    <div class="brand-name">Cyber<span>Net</span></div>
  </a>
  <div class="nav-tabs-wrap">
    <a href="?page=dashboard"   class="<?= $page=='dashboard'  ?'active':'' ?>"><i class="fas fa-chart-bar"></i> Dashboard</a>
    <a href="?page=collections" class="<?= $page=='collections'?'active':'' ?>"><i class="fas fa-hand-holding-usd"></i> Collections</a>
    <a href="?page=customers"   class="<?= $page=='customers'  ?'active':'' ?>"><i class="fas fa-users"></i> Customers</a>
    <a href="?page=files"       class="<?= $page=='files'      ?'active':'' ?>"><i class="fas fa-film"></i> Movies</a>
    <a href="?page=report"      class="<?= $page=='report'     ?'active':'' ?>"><i class="fas fa-file-invoice"></i> Report</a>
    <a href="?page=whatsapp"    class="<?= $page=='whatsapp'   ?'active':'' ?>"><i class="fab fa-whatsapp"></i> WhatsApp</a>
    <?php if (isMaster()): ?>
    <a href="?page=audit"       class="<?= $page=='audit'      ?'active':'' ?>"><i class="fas fa-history"></i> Audit</a>
    <a href="?page=admins"      class="<?= $page=='admins'     ?'active':'' ?>"><i class="fas fa-user-shield"></i> Admins</a>
    <?php endif; ?>
  </div>
  <div class="topbar-right">
    <button class="btn-icon" onclick="toggleTheme()" title="Toggle dark mode"><i class="fas fa-moon" id="theme-icon"></i></button>
    <div class="user-badge">
      <i class="fas fa-circle" style="font-size:7px;color:var(--success)"></i>
      <span><?= htmlspecialchars($_SESSION['fullname']) ?></span>
      <span class="role"><?= $_SESSION['role'] ?></span>
    </div>
    <a href="?logout=1" class="btn-icon" title="Logout" style="color:#f87171"><i class="fas fa-sign-out-alt"></i></a>
  </div>
</nav>
<div class="main-content">
<?php if (isset($_SESSION['msg'])): ?>
<div class="alert alert-info" id="top-alert">
  <i class="fas fa-info-circle"></i>
  <span><?= htmlspecialchars($_SESSION['msg']) ?></span>
  <button class="alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php unset($_SESSION['msg']); endif; ?>
<?php if (isset($_SESSION['last_collection'])): $lc=$_SESSION['last_collection']; ?>
<div class="alert alert-success">
  <i class="fas fa-check-circle"></i>
  <span><strong><?= htmlspecialchars($lc['name']) ?></strong> — <?= $lc['amount'] ?> SAR collected by <?= htmlspecialchars($lc['collector']) ?> on <?= $lc['datetime'] ?></span>
  <button class="alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php unset($_SESSION['last_collection']); endif; ?>

<?php if ($page === 'dashboard'): ?>
<div class="stats-grid">
  <div class="stat-card">
    <div><div class="stat-label">Total Customers</div><div class="stat-value stat-blue"><?= $total_customers ?></div></div>
    <div class="stat-icon stat-icon-blue"><i class="fas fa-users"></i></div>
  </div>
  <div class="stat-card">
    <div><div class="stat-label">Paid <?= date('M', strtotime($display_month)) ?></div><div class="stat-value stat-green"><?= $collected_count ?></div></div>
    <div class="stat-icon stat-icon-green"><i class="fas fa-check-circle"></i></div>
  </div>
  <div class="stat-card">
    <div><div class="stat-label">Pending <?= date('M', strtotime($display_month)) ?></div><div class="stat-value stat-red"><?= $pending_count ?></div></div>
    <div class="stat-icon stat-icon-red"><i class="fas fa-clock"></i></div>
  </div>
  <div class="stat-card">
    <div><div class="stat-label">Collected <?= date('M', strtotime($display_month)) ?></div><div class="stat-value stat-teal"><?= number_format($total_amount,2) ?></div></div>
    <div class="stat-icon stat-icon-teal"><i class="fas fa-money-bill-wave"></i></div>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-calendar-alt"></i> Filter Month</div></div>
  <div class="card-body">
    <form method="get" style="display:flex;gap:10px;align-items:center">
      <input type="hidden" name="page" value="dashboard">
      <select name="stats_month" class="form-select" style="width:200px">
        <?php for($i=0;$i<12;$i++){$m=date('Y-m',strtotime("-$i months"));$sel=($m==$display_month)?'selected':'';echo "<option value=\"$m\" $sel>".date('F Y',strtotime($m))."</option>";}?>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Show</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-users"></i> Collections by Staff — <?= date("F Y",strtotime($display_month)) ?></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Staff Name</th><th>Collections</th><th style="text-align:right">Total Collected</th><th style="text-align:right">Avg per Record</th></tr></thead>
      <tbody>
      <?php $staff=$db->query("SELECT id,fullname FROM users ORDER BY fullname");$gt=0;$gc=0;
      while($s=$staff->fetchArray(SQLITE3_ASSOC)){
        $t=$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month' AND collected_by={$s['id']}");
        $cnt=$db->querySingle("SELECT COUNT(*) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month' AND collected_by={$s['id']}");
        $avg=$cnt>0?round($t/$cnt,2):0;$gt+=$t;$gc+=$cnt;
        echo "<tr><td><strong>".htmlspecialchars($s['fullname'])."</strong></td><td><span class='badge badge-blue'>$cnt</span></td><td style='text-align:right'><span class='mono' style='color:var(--success)'>$t SAR</span></td><td style='text-align:right'><span class='mono'>$avg SAR</span></td></tr>";
      }?>
      <tr style="background:var(--surface2);font-weight:700">
        <td>TOTAL</td><td><span class="badge badge-gray"><?= $gc ?></span></td>
        <td style="text-align:right"><span class="mono" style="color:var(--accent)"><?= number_format($gt,2) ?> SAR</span></td>
        <td style="text-align:right"><span class="mono"><?= $gc>0?number_format($gt/$gc,2):0 ?> SAR</span></td>
      </tr>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'collections'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-search"></i> Search Customer</div></div>
  <div class="card-body">
    <div class="search-wrap">
      <i class="fas fa-search search-icon"></i>
      <input type="text" id="collSearch" class="form-control" placeholder="Name, mobile, building, room…" autocomplete="off">
      <div class="search-results" id="collResults" style="display:none"></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-clock"></i> Pending Bills (oldest first)</div>
    <span class="badge badge-red"><?= count($pending_customers) ?> customers</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Customer</th><th>Mobile</th><th>Billing Day</th><th>Address</th><th>Unpaid Months</th><th>Total Due</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($pending_customers)): ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted)"><i class="fas fa-check-circle" style="color:var(--success)"></i> All bills are paid!</td></tr>
      <?php else: $i=0; foreach($pending_customers as $p): $i++;
        $total_due=array_sum(array_column($p['unpaid'],'due'));
        $months_json=json_encode(array_map(fn($u)=>['month'=>$u['month'],'month_name'=>date('F Y',strtotime($u['month'].'-01')),'due'=>$u['due']],$p['unpaid']));
      ?>
        <tr>
          <td class="mono" style="color:var(--text-muted)"><?= $i ?></td>
          <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
          <td class="mono"><?= $p['mobile'] ?></td>
          <td><span class="badge badge-blue">Day <?= $p['billing_day'] ?></span></td>
          <td style="color:var(--text-muted);font-size:12px"><?= $p['building'].' '.$p['apartment'].' R'.$p['room'] ?></td>
          <td><?php foreach($p['unpaid'] as $u): ?><div style="font-size:12px"><span class="due-tag"><?= date('M Y',strtotime($u['month'].'-01')).' — '.$u['due'].' SAR' ?></span></div><?php endforeach; ?></td>
          <td><strong style="color:var(--danger);font-family:'IBM Plex Mono'"><?= $total_due ?> SAR</strong></td>
          <td style="white-space:nowrap">
            <button class="btn btn-primary btn-xs" onclick="openCollectModal(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>')"><i class="fas fa-money-bill"></i> Collect</button>
            <button class="btn btn-whatsapp btn-xs" onclick="sendReminder('<?= $p['mobile'] ?>','<?= htmlspecialchars(addslashes($p['name'])) ?>',<?= $total_due ?>,<?= htmlspecialchars($months_json,ENT_QUOTES) ?>)"><i class="fab fa-whatsapp"></i></button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="collectModal">
  <div class="modal-box modal-box-lg">
    <div class="modal-header-custom">
      <h5><i class="fas fa-money-bill" style="color:var(--accent)"></i> Collect Payment</h5>
      <button class="modal-close" onclick="closeModal('collectModal')">×</button>
    </div>
    <div class="modal-body-custom">
      <form method="post" id="collectForm">
        <input type="hidden" name="action" value="add_collection_partial">
        <input type="hidden" name="customer_id" id="coll_cid">
        <div style="margin-bottom:14px;font-weight:600;color:var(--text-muted)" id="coll_name"></div>
        <div id="unpaidList"></div>
        <hr class="divider">
        <div class="alert alert-info" style="margin-bottom:14px">
          <i class="fas fa-calculator"></i>
          <span>Total to collect: <strong class="mono" id="totalAmt">0</strong> SAR</span>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Record Payment</button>
      </form>
    </div>
  </div>
</div>

<?php elseif ($page === 'customers'): ?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-search"></i> Search</div>
    <button class="btn btn-success btn-sm" onclick="openModal('custModal');clearCustForm()"><i class="fas fa-plus"></i> Add Customer</button>
  </div>
  <div class="card-body">
    <div class="search-wrap">
      <i class="fas fa-search search-icon"></i>
      <input type="text" id="custSearch" class="form-control" placeholder="Name, mobile, building, room…">
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-users"></i> All Customers</div>
    <span class="badge badge-blue"><?= $total_customers ?> active</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Mobile</th><th>Address</th><th>Billing Day</th><th>Monthly Fee</th><th>WhatsApp</th><th>Actions</th></tr></thead>
      <tbody id="custTbody">
      <?php $i=0;$custs=$db->query("SELECT * FROM customers WHERE status='active' ORDER BY name");
      while($c=$custs->fetchArray(SQLITE3_ASSOC)){$i++;?>
        <tr data-n="<?= htmlspecialchars($c['name']) ?>" data-m="<?= $c['mobile'] ?>" data-b="<?= $c['building'] ?>" data-r="<?= $c['room'] ?>">
          <td class="mono" style="color:var(--text-muted)"><?= $i ?></td>
          <td><strong><?= htmlspecialchars($c['name']) ?></strong><br><a href="#" style="font-size:11px;color:var(--accent)" onclick="showHistory(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>')">View History</a></td>
          <td class="mono"><?= $c['mobile'] ?></td>
          <td style="color:var(--text-muted);font-size:12px"><?= trim($c['building'].' '.$c['apartment'].' R'.$c['room']) ?></td>
          <td><span class="badge badge-blue">Day <?= $c['billing_day'] ?></span></td>
          <td><span class="badge badge-green mono"><?= floatval($c['monthly_fee']?:30) ?> SAR</span></td>
          <td><button class="btn btn-whatsapp btn-xs" onclick="sendReminderSimple('<?= $c['mobile'] ?>','<?= htmlspecialchars(addslashes($c['name'])) ?>')"><i class="fab fa-whatsapp"></i></button></td>
          <td style="white-space:nowrap">
            <button class="btn btn-secondary btn-xs" onclick="editCust(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>','<?= $c['mobile'] ?>','<?= $c['building'] ?>','<?= $c['apartment'] ?>','<?= $c['room'] ?>',<?= $c['billing_day'] ?>,'<?= $c['billing_start_date'] ?>',<?= floatval($c['monthly_fee']?:30) ?>)"><i class="fas fa-edit"></i></button>
            <a href="?delete_customer=<?= $c['id'] ?>&page=customers" class="btn btn-danger btn-xs" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($c['name'])) ?>? This removes all their collection records too.')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
      <?php }?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="custModal">
  <div class="modal-box">
    <div class="modal-header-custom">
      <h5><i class="fas fa-user-plus" style="color:var(--success)"></i> <span id="custModalTitle">Add Customer</span></h5>
      <button class="modal-close" onclick="closeModal('custModal')">×</button>
    </div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="save_customer">
        <input type="hidden" name="id" id="cust_id">
        <div class="input-group" style="margin-bottom:12px">
          <div style="flex:2"><label class="form-label">Full Name</label><input type="text" name="name" id="cust_name" class="form-control" placeholder="Customer full name" required></div>
          <div style="flex:1"><label class="form-label">Mobile</label><input type="text" name="mobile" id="cust_mobile" class="form-control" placeholder="e.g. 555xxx" required></div>
        </div>
        <div class="input-group" style="margin-bottom:12px">
          <div style="flex:1"><label class="form-label">Building</label><input type="text" name="building" id="cust_building" class="form-control" placeholder="Building name"></div>
          <div style="flex:1"><label class="form-label">Apartment</label><input type="text" name="apartment" id="cust_apartment" class="form-control" placeholder="Apt #"></div>
          <div style="flex:1"><label class="form-label">Room</label><input type="text" name="room" id="cust_room" class="form-control" placeholder="Room #"></div>
        </div>
        <div class="input-group" style="margin-bottom:12px">
          <div style="flex:1"><label class="form-label">Billing Day (1-31)</label><input type="number" name="billing_day" id="cust_billing_day" class="form-control" min="1" max="31" placeholder="e.g. 25" required></div>
          <div style="flex:1"><label class="form-label">Monthly Fee (SAR)</label><input type="number" name="monthly_fee" id="cust_fee" class="form-control" min="1" step="0.5" value="30" required></div>
        </div>
        <div style="margin-bottom:16px">
          <label class="form-label">Date Connection Started <small style="font-weight:400;text-transform:none">(actual date, e.g. 2025-04-25)</small></label>
          <input type="date" name="billing_start_date" id="cust_start" class="form-control" required>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><i class="fas fa-info-circle"></i> If joined after billing day, first bill automatically starts the following month.</div>
        </div>
        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Customer</button>
      </form>
    </div>
  </div>
</div>
<div class="modal-overlay" id="histModal">
  <div class="modal-box">
    <div class="modal-header-custom">
      <h5><i class="fas fa-history" style="color:var(--info)"></i> Payment History</h5>
      <button class="modal-close" onclick="closeModal('histModal')">×</button>
    </div>
    <div class="modal-body-custom" id="histContent"></div>
  </div>
</div>

<?php elseif ($page === 'report'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-file-invoice"></i> All Collections</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Customer</th><th>Month</th><th>Amount</th><th>Collected Date</th><th>Collected By</th><?php if(isMaster()):?><th>Actions</th><?php endif;?></tr></thead>
      <tbody>
      <?php while($col=$all_collections->fetchArray(SQLITE3_ASSOC)): ?>
        <tr>
          <td class="mono" style="color:var(--text-muted)"><?= $col['id'] ?></td>
          <td><strong><?= htmlspecialchars($col['cname']) ?></strong></td>
          <td class="mono"><?= $col['month_year'] ?></td>
          <td><span class="badge badge-green mono"><?= $col['amount'] ?> SAR</span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= $col['collected_date'] ?></td>
          <td><?= htmlspecialchars($col['collector']) ?></td>
          <?php if(isMaster()): ?>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline-flex;gap:4px;align-items:center">
              <input type="hidden" name="action" value="edit_collection">
              <input type="hidden" name="collection_id" value="<?= $col['id'] ?>">
              <input type="number" name="amount" value="<?= $col['amount'] ?>" step="0.5" style="width:80px" class="form-control">
              <button type="submit" class="btn btn-primary btn-xs"><i class="fas fa-save"></i></button>
            </form>
            <a href="?delete_collection=<?= $col['id'] ?>&page=report" class="btn btn-danger btn-xs" onclick="return confirm('Delete this record?')"><i class="fas fa-trash"></i></a>
          </td>
          <?php endif; ?>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'files'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-upload"></i> Upload Movie / File</div></div>
  <div class="card-body">
    <form action="portal.php?page=files" method="post" enctype="multipart/form-data">
      <div class="input-group" style="flex-wrap:wrap;gap:8px">
        <div style="flex:1;min-width:140px"><label class="form-label">Title</label><input type="text" name="title" class="form-control" placeholder="Movie title" required></div>
        <div style="flex:1;min-width:140px"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="1" placeholder="Optional"></textarea></div>
        <div style="flex:1;min-width:140px"><label class="form-label">File</label><input type="file" name="upload_file" class="form-control" required></div>
        <div style="align-self:flex-end"><button type="submit" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> Upload</button></div>
      </div>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-film"></i> Movie Library</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Filename</th><th>Size (MB)</th><th>Downloads</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $files=$db->query("SELECT * FROM files ORDER BY id DESC");
      while($f=$files->fetchArray(SQLITE3_ASSOC)){$sz=round($f['size']/1048576,2);?>
        <tr>
          <td><strong><?= htmlspecialchars($f['title']) ?></strong><?php if($f['description']):?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($f['description']) ?></small><?php endif;?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($f['original_name']) ?></td>
          <td class="mono"><?= $sz ?></td>
          <td><span class="badge badge-gray"><?= $f['downloads'] ?></span></td>
          <td style="white-space:nowrap">
            <a href="?download=<?= $f['id'] ?>" class="btn btn-success btn-xs"><i class="fas fa-download"></i> Download</a>
            <?php if(isMaster()):?><a href="?delete_file=<?= $f['id'] ?>&page=files" class="btn btn-danger btn-xs" onclick="return confirm('Delete file?')"><i class="fas fa-trash"></i></a><?php endif;?>
          </td>
        </tr>
      <?php }?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'whatsapp'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="wa-grid">
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fab fa-whatsapp" style="color:#25D366"></i> Connection Status</div>
      <button class="btn btn-secondary btn-sm" onclick="checkWaStatus()"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
    <div class="card-body">
      <div id="wa-status-display"><div style="color:var(--text-muted);padding:20px 0;text-align:center"><i class="fas fa-spinner fa-spin"></i> Checking status…</div></div>
      <hr class="divider">
      <div style="font-size:12px;color:var(--text-muted)">
        <div><strong>OpenWA URL:</strong> <span class="mono"><?= htmlspecialchars(getSetting($db,'openwa_url')) ?></span></div>
        <div style="margin-top:4px"><strong>Session ID:</strong> <span class="mono" id="session-id-display"><?= htmlspecialchars(getSetting($db,'openwa_session_id')) ?></span></div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-qrcode" style="color:var(--accent)"></i> Scan QR / Reconnect</div></div>
    <div class="card-body">
      <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px">If WhatsApp is disconnected, click <strong>Reconnect</strong> to create a new session, then scan the QR code with your phone.</p>
      <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <?php if(isMaster()):?>
        <button class="btn btn-primary" onclick="doReconnect()"><i class="fas fa-sync-alt"></i> Reconnect WhatsApp</button>
        <?php endif;?>
        <button class="btn btn-secondary" onclick="fetchQR()"><i class="fas fa-qrcode"></i> Show QR Code</button>
      </div>
      <div class="qr-container" id="qr-container">
        <div style="color:var(--text-muted);text-align:center"><i class="fas fa-qrcode" style="font-size:40px;opacity:.3;display:block;margin-bottom:8px"></i><span style="font-size:12px">QR code will appear here</span></div>
      </div>
      <div id="wa-log" style="margin-top:12px;font-size:12px;color:var(--text-muted)"></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-paper-plane"></i> Send Test Message</div></div>
  <div class="card-body">
    <div class="input-group">
      <div style="flex:1"><label class="form-label">Phone (without leading 0)</label><input type="text" id="test-phone" class="form-control" placeholder="e.g. 555xxxxxxx"></div>
      <div style="flex:2"><label class="form-label">Message</label><input type="text" id="test-msg" class="form-control" value="Hello from CyberNet billing portal ✅"></div>
      <div style="align-self:flex-end"><button class="btn btn-whatsapp" onclick="sendTestMsg()"><i class="fab fa-whatsapp"></i> Send</button></div>
    </div>
    <div id="test-result" style="margin-top:10px;font-size:13px"></div>
  </div>
</div>

<?php elseif ($page === 'audit' && isMaster()): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-filter"></i> Filter by User</div></div>
  <div class="card-body">
    <form method="get" style="display:flex;gap:10px">
      <input type="hidden" name="page" value="audit">
      <select name="filter_user" class="form-select" style="width:220px">
        <option value="0">All Users</option>
        <?php $us=$db->query("SELECT id,fullname FROM users ORDER BY fullname");
        while($u=$us->fetchArray(SQLITE3_ASSOC)){$sel=($filter_user==$u['id'])?'selected':'';echo "<option value='{$u['id']}' $sel>".htmlspecialchars($u['fullname'])."</option>";}?>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
      <a href="?page=audit" class="btn btn-secondary">Clear</a>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-history"></i> Audit Log</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      <?php
      if($filter_user>0) $logs=$db->query("SELECT l.*,u.fullname FROM audit_log l JOIN users u ON l.user_id=u.id WHERE l.user_id=$filter_user ORDER BY l.timestamp DESC LIMIT 500");
      else $logs=$db->query("SELECT l.*,u.fullname FROM audit_log l JOIN users u ON l.user_id=u.id ORDER BY l.timestamp DESC LIMIT 500");
      while($log=$logs->fetchArray(SQLITE3_ASSOC)):?>
        <tr>
          <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= $log['timestamp'] ?></td>
          <td><?= htmlspecialchars($log['fullname']) ?></td>
          <td><span class="badge badge-blue"><?= htmlspecialchars($log['action']) ?></span></td>
          <td style="font-size:12px"><?= htmlspecialchars($log['details']) ?></td>
        </tr>
      <?php endwhile;?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'admins' && isMaster()): ?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-user-shield"></i> Admin Users</div>
    <button class="btn btn-success btn-sm" onclick="openModal('addAdminModal')"><i class="fas fa-plus"></i> Add Admin</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $admins=$db->query("SELECT id,fullname,username,role FROM users");
      while($a=$admins->fetchArray(SQLITE3_ASSOC)):?>
        <tr>
          <td><strong><?= htmlspecialchars($a['fullname']) ?></strong></td>
          <td class="mono"><?= htmlspecialchars($a['username']) ?></td>
          <td><span class="badge <?= $a['role']==='master'?'badge-yellow':'badge-blue' ?>"><?= $a['role'] ?></span></td>
          <td>
            <?php if($a['role']==='master'):?>
              <button class="btn btn-secondary btn-xs" onclick="editMaster(<?= $a['id'] ?>,'<?= htmlspecialchars(addslashes($a['fullname'])) ?>','<?= htmlspecialchars(addslashes($a['username'])) ?>')"><i class="fas fa-edit"></i> Edit</button>
            <?php else:?>
              <button class="btn btn-warning btn-xs" onclick="resetPass(<?= $a['id'] ?>)"><i class="fas fa-key"></i> Reset Password</button>
              <a href="?delete_admin=<?= $a['id'] ?>&page=admins" class="btn btn-danger btn-xs" onclick="return confirm('Delete this admin?')"><i class="fas fa-trash"></i></a>
            <?php endif;?>
          </td>
        </tr>
      <?php endwhile;?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="addAdminModal">
  <div class="modal-box">
    <div class="modal-header-custom"><h5><i class="fas fa-user-plus" style="color:var(--success)"></i> Add Admin</h5><button class="modal-close" onclick="closeModal('addAdminModal')">×</button></div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="save_admin">
        <div style="margin-bottom:10px"><label class="form-label">Full Name</label><input type="text" name="fullname" class="form-control" required></div>
        <div style="margin-bottom:10px"><label class="form-label">Username</label><input type="text" name="username" class="form-control" required></div>
        <div style="margin-bottom:14px"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Create Admin</button>
      </form>
    </div>
  </div>
</div>
<div class="modal-overlay" id="editMasterModal">
  <div class="modal-box">
    <div class="modal-header-custom"><h5><i class="fas fa-user-cog" style="color:var(--info)"></i> Edit Master Account</h5><button class="modal-close" onclick="closeModal('editMasterModal')">×</button></div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="edit_master">
        <input type="hidden" name="master_id" id="master_id">
        <div style="margin-bottom:10px"><label class="form-label">Full Name</label><input type="text" name="fullname" id="master_fn" class="form-control" required></div>
        <div style="margin-bottom:10px"><label class="form-label">Username</label><input type="text" name="username" id="master_un" class="form-control" required></div>
        <div style="margin-bottom:14px"><label class="form-label">New Password <small style="font-weight:400">(leave blank to keep)</small></label><input type="password" name="new_password" class="form-control"></div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
      </form>
    </div>
  </div>
</div>
<div class="modal-overlay" id="resetPassModal">
  <div class="modal-box">
    <div class="modal-header-custom"><h5><i class="fas fa-key" style="color:var(--warning)"></i> Reset Password</h5><button class="modal-close" onclick="closeModal('resetPassModal')">×</button></div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="reset_uid">
        <div style="margin-bottom:14px"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required></div>
        <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Reset</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<script>
const savedTheme=localStorage.getItem('theme')||'light';
document.documentElement.setAttribute('data-theme',savedTheme);
function updateThemeIcon(){const t=document.documentElement.getAttribute('data-theme');const ic=document.getElementById('theme-icon');if(ic)ic.className=t==='dark'?'fas fa-sun':'fas fa-moon';}
updateThemeIcon();
function toggleTheme(){const cur=document.documentElement.getAttribute('data-theme');const next=cur==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',next);localStorage.setItem('theme',next);updateThemeIcon();}
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
document.addEventListener('click',function(e){if(e.target.classList.contains('modal-overlay'))e.target.classList.remove('show');});
setTimeout(()=>{const a=document.getElementById('top-alert');if(a)a.remove()},5000);
function clearCustForm(){['cust_id','cust_name','cust_mobile','cust_building','cust_apartment','cust_room','cust_start'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});const bd=document.getElementById('cust_billing_day');if(bd)bd.value='';const fee=document.getElementById('cust_fee');if(fee)fee.value='30';const t=document.getElementById('custModalTitle');if(t)t.textContent='Add Customer';}
function editCust(id,name,mobile,building,apartment,room,day,start,fee){document.getElementById('cust_id').value=id;document.getElementById('cust_name').value=name;document.getElementById('cust_mobile').value=mobile;document.getElementById('cust_building').value=building;document.getElementById('cust_apartment').value=apartment;document.getElementById('cust_room').value=room;document.getElementById('cust_billing_day').value=day;document.getElementById('cust_start').value=start;document.getElementById('cust_fee').value=fee;document.getElementById('custModalTitle').textContent='Edit Customer';openModal('custModal');}
const custSearch=document.getElementById('custSearch');
if(custSearch){custSearch.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('#custTbody tr').forEach(row=>{const n=(row.dataset.n||'').toLowerCase();const m=(row.dataset.m||'').toLowerCase();const b=(row.dataset.b||'').toLowerCase();const r=(row.dataset.r||'').toLowerCase();row.style.display=(n+m+b+r).includes(q)?'':'none';});});}
const collSearch=document.getElementById('collSearch');
if(collSearch){let debounce;collSearch.addEventListener('input',function(){clearTimeout(debounce);const q=this.value.trim();const res=document.getElementById('collResults');if(q.length<2){res.style.display='none';return;}debounce=setTimeout(()=>{fetch('search_customers.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(data=>{if(!data.length){res.innerHTML='<div style="padding:12px;text-align:center;color:var(--text-muted)">No results</div>';res.style.display='block';return;}res.innerHTML=data.map(c=>`<div class="search-result-item"><div><strong>${c.name}</strong><br><span style="font-size:11px;color:var(--text-muted)">${c.mobile} · ${c.building} R${c.room}</span></div><div style="display:flex;gap:6px"><button class="btn btn-primary btn-xs" onclick="openCollectModal(${c.id},'${c.name.replace(/'/g,"\\'")}');document.getElementById('collResults').style.display='none'"><i class='fas fa-money-bill'></i> Collect</button><button class="btn btn-whatsapp btn-xs" onclick="sendReminderSimple('${c.mobile}','${c.name.replace(/'/g,"\\'")}')"><i class='fab fa-whatsapp'></i></button></div></div>`).join('');res.style.display='block';});},300);});document.addEventListener('click',e=>{if(!collSearch.contains(e.target))document.getElementById('collResults').style.display='none';});}
function openCollectModal(cid,name){document.getElementById('coll_cid').value=cid;document.getElementById('coll_name').textContent='Customer: '+name;document.getElementById('unpaidList').innerHTML='<div style="padding:20px;text-align:center;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';openModal('collectModal');fetch('get_unpaid.php?cid='+cid).then(r=>r.json()).then(data=>{if(!data.length){document.getElementById('unpaidList').innerHTML='<div class="alert alert-success"><i class="fas fa-check-circle"></i> All bills are paid for this customer!</div>';document.getElementById('totalAmt').textContent='0';return;}let html='<div class="table-wrap"><table><thead><tr><th>Select</th><th>Month</th><th>Standard Due</th><th>Amount to Collect (SAR)</th><th>Settle Month</th></tr></thead><tbody>';data.forEach((m,i)=>{html+=`<tr><td><input type="checkbox" name="months[]" value="${m.month}" class="month-cb" checked onchange="updateTotal()"></td><td><strong>${new Date(m.month+'-02').toLocaleDateString('en',{year:'numeric',month:'long'})}</strong></td><td><span class="badge badge-red mono">${m.due} SAR</span></td><td><input type="number" name="amounts[]" class="form-control amt-input" value="${m.due}" min="0" step="0.5" style="width:120px" onchange="updateTotal()"></td></tr>`;});html+='</tbody></table></div>';document.getElementById('unpaidList').innerHTML=html;updateTotal();});}
function updateTotal(){let total=0;document.querySelectorAll('.amt-input').forEach(inp=>{const row=inp.closest('tr');const cb=row?.querySelector('.month-cb');if(!cb||cb.checked)total+=parseFloat(inp.value)||0;});document.getElementById('totalAmt').textContent=total.toFixed(2);}
function showHistory(cid,name){document.getElementById('histContent').innerHTML='<div style="text-align:center;padding:20px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';openModal('histModal');fetch('get_history.php?cid='+cid).then(r=>r.text()).then(html=>{document.getElementById('histContent').innerHTML=html;});}
function getWaConfig(){return{url:'<?= getSetting($db,"openwa_url") ?>',key:'<?= getSetting($db,"openwa_api_key") ?>',sid:document.getElementById('session-id-display')?.textContent||'<?= getSetting($db,"openwa_session_id") ?>'};}
function sendWaMsg(mobile,message){const cfg=getWaConfig();let phone=mobile.replace(/^0+/,'');if(!/^966/.test(phone))phone='966'+phone;const chatId=phone+'@c.us';const url=`${cfg.url}/api/sessions/${cfg.sid}/messages/send-text`;return fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':cfg.key},body:JSON.stringify({chatId,text:message})});}
function sendReminder(mobile,name,totalDue,monthsList){let lines='';monthsList.forEach(m=>lines+=`  - ${m.month_name}: ${m.due} SAR\n`);const msg=`Dear ${name},\n\nYou have unpaid bills:\n${lines}\nTotal due: ${totalDue} SAR\nPlease pay soon.\n\n🎬 Movies: <?= getSetting($db,"movie_server") ?>\n\n📞 Support:\n<?= getSetting($db,"support_1_name") ?>: <?= getSetting($db,"support_1_phone") ?>\n<?= getSetting($db,"support_2_name") ?>: <?= getSetting($db,"support_2_phone") ?>`;sendWaMsg(mobile,msg).then(r=>r.ok?alert('✅ Reminder sent to '+name):alert('❌ Failed. Check OpenWA status.')).catch(()=>alert('❌ Network error'));}
function sendReminderSimple(mobile,name){const msg=`Dear ${name},\n\nYour internet bill is due. Please pay on time.\n\n🎬 Movies: <?= getSetting($db,"movie_server") ?>\n\n📞 Support:\n<?= getSetting($db,"support_1_name") ?>: <?= getSetting($db,"support_1_phone") ?>`;sendWaMsg(mobile,msg).then(r=>r.ok?alert('✅ Reminder sent to '+name):alert('❌ Failed. Check OpenWA status.')).catch(()=>alert('❌ Network error'));}
function checkWaStatus(){const el=document.getElementById('wa-status-display');if(!el)return;el.innerHTML='<div style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Checking…</div>';fetch('portal.php?action=wa_status').then(r=>r.json()).then(d=>{const s=d.session;const status=s?.status||'unknown';const dotClass=status==='CONNECTED'||status==='ready'?'connected':(status==='WAITING_QR'?'waiting':'disconnected');el.innerHTML=`<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><span class="wa-status-dot ${dotClass}"></span><strong style="font-size:16px">${status}</strong></div><div style="font-size:12px;color:var(--text-muted)">${s?.name?'<div>Session name: '+s.name+'</div>':''}${s?.phoneNumber?'<div>Phone: '+s.phoneNumber+'</div>':''}</div>`;}).catch(()=>{el.innerHTML='<div style="color:var(--danger)"><i class="fas fa-exclamation-triangle"></i> Could not reach OpenWA</div>';});}
function fetchQR(){document.getElementById('qr-container').innerHTML='<div style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Fetching QR…</div>';fetch('portal.php?action=wa_qr').then(r=>r.json()).then(d=>{if(d.qrCode){document.getElementById('qr-container').innerHTML=`<div><img src="${d.qrCode}" style="max-width:240px;border-radius:8px"><p style="font-size:12px;color:var(--text-muted);margin-top:8px">Scan with WhatsApp</p></div>`;}else{document.getElementById('qr-container').innerHTML='<div style="color:var(--text-muted)">No QR available. Session may already be connected, or try Reconnect first.</div>';}}).catch(()=>{document.getElementById('qr-container').innerHTML='<div style="color:var(--danger)">Error fetching QR</div>';});}
function doReconnect(){if(!confirm('This will disconnect the current WhatsApp session and create a new one. Proceed?'))return;const logEl=document.getElementById('wa-log');logEl.innerHTML='<i class="fas fa-spinner fa-spin"></i> Reconnecting…';document.getElementById('qr-container').innerHTML='<div style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Please wait…</div>';fetch('portal.php?action=wa_reconnect',{method:'POST'}).then(r=>r.json()).then(d=>{if(d.success){logEl.innerHTML=d.log.map(l=>`<div>✓ ${l}</div>`).join('');document.getElementById('session-id-display').textContent=d.new_session_id;setTimeout(fetchQR,2000);setTimeout(checkWaStatus,4000);}else{logEl.innerHTML='<span style="color:var(--danger)">Error: '+(d.error||'Unknown')+'</span>';}}).catch(()=>{logEl.innerHTML='<span style="color:var(--danger)">Network error</span>';});}
function sendTestMsg(){const phone=document.getElementById('test-phone').value.trim();const msg=document.getElementById('test-msg').value.trim();const res=document.getElementById('test-result');if(!phone||!msg){res.innerHTML='<span style="color:var(--danger)">Enter phone and message</span>';return;}res.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending…';sendWaMsg(phone,msg).then(r=>r.json()).then(d=>{res.innerHTML=`<span style="color:var(--success)">✅ Sent!</span>`;}).catch(e=>{res.innerHTML=`<span style="color:var(--danger)">❌ Error: ${e}</span>`;});}
if(document.getElementById('wa-status-display'))checkWaStatus();
function editMaster(id,fn,un){document.getElementById('master_id').value=id;document.getElementById('master_fn').value=fn;document.getElementById('master_un').value=un;openModal('editMasterModal');}
function resetPass(id){document.getElementById('reset_uid').value=id;openModal('resetPassModal');}
</script>
</body>
</html>

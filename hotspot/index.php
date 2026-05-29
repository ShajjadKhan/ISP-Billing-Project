<?php
date_default_timezone_set('Asia/Riyadh');
define('DB_PATH', '/home/tserver/cybernet/cybernet.db');

$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$db->exec("PRAGMA journal_mode=WAL");

$error  = '';
$step   = 'enter_phone';

$client_mac = strtoupper(preg_replace('/[^a-fA-F0-9:]/', '', $_GET['mac'] ?? ''));
$client_ip  = $_GET['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$link_orig  = $_GET['link-orig'] ?? 'http://google.com';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone']);
    if (strlen($phone) < 9) {
        $error = 'Please enter a valid phone number.';
    } else {
        if ($client_mac) {
            $dev = $db->querySingle("SELECT d.customer_id FROM devices d
                JOIN packages p ON d.customer_id=p.customer_id
                WHERE d.mac_address='".SQLite3::escapeString($client_mac)."'
                AND d.status='active' AND p.status='active'
                AND date(p.end_date) >= date('now') LIMIT 1", true);
            if ($dev) { header('Location: '.$link_orig); exit; }
        }
        $existing = $db->querySingle("SELECT id FROM pending_approvals
            WHERE mac_address='".SQLite3::escapeString($client_mac)."'
            AND status='pending' LIMIT 1");
        if ($existing) {
            $step = 'waiting';
        } else {
            $p   = SQLite3::escapeString($phone);
            $mac = SQLite3::escapeString($client_mac);
            $ip  = SQLite3::escapeString($client_ip);
            $db->exec("INSERT INTO pending_approvals (phone,mac_address,ip_address,status)
                VALUES ('$p','$mac','$ip','pending')");
            $step = 'waiting';
        }
    }
}

if ($step === 'waiting' && $client_mac) {
    $approved = $db->querySingle("SELECT d.customer_id FROM devices d
        JOIN packages p ON d.customer_id=p.customer_id
        WHERE d.mac_address='".SQLite3::escapeString($client_mac)."'
        AND d.status='active' AND p.status='active'
        AND date(p.end_date) >= date('now') LIMIT 1");
    if ($approved) { header('Location: '.$link_orig); exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<?php if($step==='waiting'): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
<title>CyberNet WiFi</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#0f172a 100%);font-family:'IBM Plex Sans',sans-serif;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:rgba(255,255,255,.07);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.12);border-radius:24px;padding:36px 28px;width:100%;max-width:380px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.logo{width:72px;height:72px;background:linear-gradient(135deg,#0ea5e9,#0284c7);border-radius:20px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;box-shadow:0 8px 24px rgba(14,165,233,.4);font-size:32px}
h1{color:#fff;font-size:22px;font-weight:700;margin-bottom:6px}
.sub{color:#94a3b8;font-size:13px;margin-bottom:28px;line-height:1.5}
.form-label{display:block;color:#94a3b8;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;text-align:left}
.form-input{width:100%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:13px 16px;color:#fff;font-size:16px;font-family:inherit;outline:none;transition:border-color .2s;margin-bottom:14px}
.form-input:focus{border-color:#0ea5e9}
.form-input::placeholder{color:#475569}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
.btn:hover{filter:brightness(1.1)}
.btn-outline{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);margin-top:10px}
.error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#fca5a5;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:14px;text-align:left}
.wait-box{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:16px;padding:20px;margin-bottom:20px}
.wait-box h3{color:#fbbf24;font-size:15px;font-weight:600;margin-bottom:6px}
.wait-box p{color:#94a3b8;font-size:12px;line-height:1.6}
.spinner{width:36px;height:36px;border:3px solid rgba(255,255,255,.1);border-top-color:#0ea5e9;border-radius:50%;animation:spin 1s linear infinite;margin:16px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.mac{font-size:11px;color:#334155;margin-top:18px;font-family:monospace}
.terms{color:#475569;font-size:11px;margin-top:16px;line-height:1.5}
</style>
</head>
<body>
<div class="card">
 <div class="logo">&#x1F4F6;</div>

<?php if($step==='enter_phone'): ?>
 <h1>CyberNet WiFi</h1>
 <p class="sub">Enter your phone number to request internet access.</p>
 <?php if($error): ?><div class="error">&#9888; <?= htmlspecialchars($error) ?></div><?php endif; ?>
 <form method="post">
  <label class="form-label">Phone Number</label>
  <input type="tel" name="phone" class="form-input" placeholder="05xxxxxxxx" required autofocus inputmode="numeric">
  <button type="submit" class="btn">Request Access</button>
 </form>
 <p class="terms">By connecting you agree to use this network lawfully and in accordance with Saudi regulations.</p>

<?php else: ?>
 <h1>Request Sent!</h1>
 <p class="sub">Your request has been submitted. Waiting for admin approval.</p>
 <div class="wait-box">
  <h3>&#9203; Waiting for Approval</h3>
  <p>An admin will review and approve your connection shortly. This page checks automatically every 10 seconds.</p>
 </div>
 <div class="spinner"></div>
 <p style="color:#64748b;font-size:13px">Checking approval status...</p>
 <form method="post">
  <input type="hidden" name="phone" value="check">
  <button type="submit" class="btn btn-outline">Refresh Now</button>
 </form>
<?php endif; ?>

 <?php if($client_mac): ?><div class="mac">Device: <?= htmlspecialchars($client_mac) ?></div><?php endif; ?>
</div>
</body>
</html>

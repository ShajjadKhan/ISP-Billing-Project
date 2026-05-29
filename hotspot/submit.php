<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

date_default_timezone_set('Asia/Riyadh');
define('DB_PATH', '/home/tserver/cybernet/cybernet.db');

$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$db->exec("PRAGMA journal_mode=WAL");

$phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? $_GET['phone'] ?? '');
$mac   = strtoupper(preg_replace('/[^a-fA-F0-9:]/', '', $_POST['mac'] ?? $_GET['mac'] ?? ''));
$ip    = preg_replace('/[^0-9.]/', '', $_POST['ip'] ?? $_GET['ip'] ?? '');

if (!$phone || strlen($phone) < 9) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid phone number']);
    exit;
}

// Check if already has active package
if ($mac) {
    $active = $db->querySingle("SELECT d.customer_id FROM devices d
        JOIN packages p ON d.customer_id = p.customer_id
        WHERE d.mac_address = '" . SQLite3::escapeString($mac) . "'
        AND d.status = 'active' AND p.status = 'active'
        AND date(p.end_date) >= date('now') LIMIT 1");
    if ($active) {
        echo json_encode(['status' => 'approved', 'message' => 'Already approved']);
        exit;
    }
}

// Check if already pending
$existing = $db->querySingle("SELECT id FROM pending_approvals
    WHERE mac_address = '" . SQLite3::escapeString($mac) . "'
    AND status = 'pending' LIMIT 1");

if ($existing) {
    echo json_encode(['status' => 'pending', 'message' => 'Already submitted, waiting for approval']);
    exit;
}

// Insert new pending request
$p   = SQLite3::escapeString($phone);
$m   = SQLite3::escapeString($mac);
$i   = SQLite3::escapeString($ip);
$db->exec("INSERT INTO pending_approvals (phone, mac_address, ip_address, status)
    VALUES ('$p', '$m', '$i', 'pending')");

echo json_encode(['status' => 'pending', 'message' => 'Request submitted successfully']);
?>

<?php
// CyberNet ISP Management System - Database Setup
$db = new SQLite3('/home/tserver/cybernet/cybernet.db');
$db->exec("PRAGMA journal_mode=WAL");

// Users table
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT UNIQUE,
    password TEXT,
    fullname TEXT,
    role TEXT DEFAULT 'admin',
    created_at TEXT DEFAULT (datetime('now'))
)");

// Customers table
$db->exec("CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY,
    name TEXT,
    phone TEXT,
    room TEXT,
    building TEXT,
    nationality TEXT,
    iqama_number TEXT,
    photo TEXT,
    status TEXT DEFAULT 'pending',
    device_limit INTEGER DEFAULT 1,
    monthly_fee REAL DEFAULT 30,
    notes TEXT,
    created_by INTEGER,
    created_at TEXT DEFAULT (datetime('now'))
)");

// Devices table (multiple MACs per customer)
$db->exec("CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER,
    mac_address TEXT UNIQUE,
    device_name TEXT,
    status TEXT DEFAULT 'active',
    added_at TEXT DEFAULT (datetime('now'))
)");

// Packages table
$db->exec("CREATE TABLE IF NOT EXISTS packages (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER,
    days INTEGER,
    start_date TEXT,
    end_date TEXT,
    fee REAL,
    status TEXT DEFAULT 'active',
    created_by INTEGER,
    created_at TEXT DEFAULT (datetime('now'))
)");

// Pending approvals (from captive portal)
$db->exec("CREATE TABLE IF NOT EXISTS pending_approvals (
    id INTEGER PRIMARY KEY,
    phone TEXT,
    mac_address TEXT,
    ip_address TEXT,
    requested_at TEXT DEFAULT (datetime('now')),
    status TEXT DEFAULT 'pending'
)");

// Connection logs (legal compliance)
$db->exec("CREATE TABLE IF NOT EXISTS connection_logs (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER,
    device_id INTEGER,
    mac_address TEXT,
    ip_address TEXT,
    event TEXT,
    data_used_mb REAL DEFAULT 0,
    timestamp TEXT DEFAULT (datetime('now'))
)");

// Audit log
$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    action TEXT,
    details TEXT,
    timestamp TEXT DEFAULT (datetime('now'))
)");

// Settings
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");

// Default settings
$defaults = [
    'system_name'       => 'CyberNet ISP',
    'mikrotik_ip'       => '10.12.14.1',
    'mikrotik_user'     => 'admin',
    'mikrotik_pass'     => '',
    'mikrotik_port'     => '8728',
    'radius_secret'     => 'cybernet2026',
    'openwa_url'        => 'http://10.12.14.16:2785',
    'openwa_api_key'    => 'dev-admin-key',
    'openwa_session_id' => '84ecd217-e6bc-4b7e-ac92-1d09cb3f0be7',
    'whatsapp_welcome'  => 'Welcome to CyberNet! Your internet is now active.',
    'whatsapp_expiry'   => 'Dear {name}, your internet package expires in {days} days. Please renew.',
    'whatsapp_expired'  => 'Dear {name}, your internet package has expired. Please contact us to renew.',
];

foreach ($defaults as $k => $v) {
    $ek = SQLite3::escapeString($k);
    $ev = SQLite3::escapeString($v);
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('$ek','$ev')");
}

// Create default master admin if not exists
$existing = $db->querySingle("SELECT id FROM users WHERE username='admin'");
if (!$existing) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username,password,fullname,role) VALUES ('admin','$hash','Administrator','master')");
}

echo "Database setup complete!\n";
echo "Tables created: users, customers, devices, packages, pending_approvals, connection_logs, audit_log, settings\n";
echo "Default admin: username=admin password=admin123\n";
echo "Please change the password after first login.\n";
?>


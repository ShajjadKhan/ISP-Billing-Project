<?php
// ============================================================
// CyberNet RADIUS Manager
// Controls FreeRADIUS users via the users file
// Called by portal when customers are approved/suspended/expired
// ============================================================

define('RADIUS_USERS_FILE', '/etc/freeradius/3.0/users');
define('RADIUS_SECRET', 'cybernet2026');
define('RADIUS_SERVER', '10.20.30.40');
define('RADIUS_PORT', 1812);

// Add a MAC address to RADIUS (allow access)
function radius_add_user($mac) {
    // Normalize to uppercase with colons: AA:BB:CC:DD:EE:FF
    $mac = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    $mac = implode(':', str_split($mac, 2));
    $mac_formatted = $mac;
    
    $entry = "\n# CyberNet customer MAC\n";
    $entry .= "$mac\tAuth-Type := Accept\n";
    $entry .= "\tFall-Through = No\n";
    
    // Check if already exists
    $content = file_get_contents(RADIUS_USERS_FILE);
    if (strpos($content, $mac) !== false) {
        return ['success' => true, 'message' => 'MAC already exists'];
    }
    
    // Add before the DEFAULT line
    $content = str_replace(
        "DEFAULT Auth-Type := Reject",
        $entry . "\nDEFAULT Auth-Type := Reject",
        $content
    );
    
    if (file_put_contents(RADIUS_USERS_FILE, $content) !== false) {
        radius_reload();
        return ['success' => true, 'message' => "MAC $mac_formatted added"];
    }
    return ['success' => false, 'message' => 'Failed to write users file'];
}

// Remove a MAC address from RADIUS (block access)
function radius_remove_user($mac) {
    $mac = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    $mac = implode(':', str_split($mac, 2));
    
    $content = file_get_contents(RADIUS_USERS_FILE);
    
    // Remove the MAC entry (3 lines: comment, auth line, fall-through)
    $pattern = '/\n# CyberNet customer MAC\n' . preg_quote($mac, '/') . '\t+Auth-Type := Accept\n\tFall-Through = No\n/';
    $new_content = preg_replace($pattern, "\n", $content);
    
    if ($new_content !== $content) {
        file_put_contents(RADIUS_USERS_FILE, $new_content);
        radius_reload();
        return ['success' => true, 'message' => "MAC $mac removed"];
    }
    return ['success' => false, 'message' => 'MAC not found'];
}

// Check if MAC is in RADIUS
function radius_user_exists($mac) {
    $mac = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    $mac = implode(':', str_split($mac, 2));
    $content = file_get_contents(RADIUS_USERS_FILE);
    return strpos($content, $mac) !== false;
}

// Reload FreeRADIUS to apply changes
function radius_reload() {
    exec('sudo systemctl reload freeradius 2>&1', $output, $code);
    return $code === 0;
}

// Test RADIUS connection
function radius_test() {
    exec('sudo radtest test test ' . RADIUS_SERVER . ' 0 ' . RADIUS_SECRET . ' 2>&1', $output, $code);
    $response = implode("\n", $output);
    return [
        'success'  => strpos($response, 'Access-Accept') !== false,
        'response' => $response
    ];
}

// List all CyberNet MACs in RADIUS
function radius_list_users() {
    $content = file_get_contents(RADIUS_USERS_FILE);
    preg_match_all('/# CyberNet customer MAC\n([a-f0-9]+)\s+Auth-Type/', $content, $matches);
    $macs = [];
    foreach ($matches[1] as $mac) {
        $macs[] = implode(':', str_split($mac, 2));
    }
    return $macs;
}

// ============================================================
// If called directly via CLI or web - handle actions
// ============================================================
if (php_sapi_name() === 'cli' || isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $argv[1] ?? '';
    $mac    = $_GET['mac']    ?? $argv[2] ?? '';
    
    switch ($action) {
        case 'add':
            echo json_encode(radius_add_user($mac));
            break;
        case 'remove':
            echo json_encode(radius_remove_user($mac));
            break;
        case 'exists':
            echo json_encode(['exists' => radius_user_exists($mac)]);
            break;
        case 'list':
            echo json_encode(['macs' => radius_list_users()]);
            break;
        case 'test':
            echo json_encode(radius_test());
            break;
        default:
            echo json_encode(['error' => 'Unknown action']);
    }
}
?>

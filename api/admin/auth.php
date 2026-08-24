<?php
// api/admin/auth.php
require_once __DIR__ . '/../storage/vercel_helpers.php';

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none';");

// Session Validation
function validate_session() {
    $sess = get_stateless_session();
    if (!$sess || !isset($sess['admin_logged_in'])) return false;
    
    $time = time();
    if ($time - $sess['session_start_time'] > 43200) return false;
    if ($time - $sess['last_activity'] > 1800) return false;
    
    $sess['last_activity'] = $time;
    set_stateless_session($sess); // Update cookie
    return true;
}

function require_login() {
    if (!validate_session()) {
        destroy_stateless_session();
        header("Location: /api/admin/login.php");
        exit;
    }
    
    $auth_data = kv_get('auth_json') ?: [];
    if (!empty($auth_data['must_change_password']) && basename($_SERVER['PHP_SELF']) !== 'settings.php' && basename($_SERVER['PHP_SELF']) !== 'logout.php') {
        header("Location: /api/admin/settings.php");
        exit;
    }
}

// CSRF Protection
function generate_csrf_token() {
    $sess = get_stateless_session();
    if (empty($sess['csrf_token'])) {
        $sess['csrf_token'] = bin2hex(random_bytes(32));
        set_stateless_session($sess);
    }
    return $sess['csrf_token'];
}

function verify_csrf_token($token) {
    $sess = get_stateless_session();
    if (empty($sess['csrf_token']) || !hash_equals($sess['csrf_token'], $token)) {
        die("CSRF token validation failed.");
    }
}

// Throttling Logic via KV
function check_throttle() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $data = kv_get('throttle_json') ?: [];
    
    if (isset($data[$ip])) {
        $locked_until = $data[$ip]['locked_until'] ?? 0;
        if (time() < $locked_until) {
            return false;
        }
    }
    return true;
}

function record_failed_login() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $data = kv_get('throttle_json') ?: [];
    
    if (!isset($data[$ip])) {
        $data[$ip] = ['failed' => 0, 'last_attempt' => time(), 'locked_until' => 0];
    }
    
    $data[$ip]['failed']++;
    $data[$ip]['last_attempt'] = time();
    $f = $data[$ip]['failed'];
    
    if ($f >= 20) $data[$ip]['locked_until'] = time() + 1800;
    else if ($f >= 11) $data[$ip]['locked_until'] = time() + 300;
    else if ($f >= 6) $data[$ip]['locked_until'] = time() + 30;
    
    kv_set('throttle_json', $data);
}

function clear_throttle() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $data = kv_get('throttle_json') ?: [];
    if (isset($data[$ip])) {
        unset($data[$ip]);
        kv_set('throttle_json', $data);
    }
}

// Ensure default auth exists in KV
if (empty(kv_get('auth_json'))) {
    kv_set('auth_json', [
        "username" => "admin",
        "password_hash" => "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi",
        "must_change_password" => true,
        "password_changed_at" => gmdate('Y-m-d\TH:i:s\Z'),
        "password_history" => []
    ]);
}
?>

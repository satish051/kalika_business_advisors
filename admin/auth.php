<?php
// admin/auth.php
require_once __DIR__ . '/../storage/config.php';

// Emit Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none';");

// Configure secure sessions
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'), // Secure if HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Structured JSON Audit Logger with Daily Rotation
function audit_log($action, $details = '') {
    $date = date('Y-m-d');
    $log_file = __DIR__ . '/../storage/audit-' . $date . '.jsonl';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user = $_SESSION['admin_logged_in'] ?? false ? 'admin' : 'guest';
    
    $log_entry = json_encode([
        'time' => gmdate('Y-m-d\TH:i:s\Z'),
        'event' => $action,
        'user' => $user,
        'ip' => $ip,
        'details' => $details
    ]) . PHP_EOL;
    
    // Write with exclusive lock
    $fp = fopen($log_file, 'a');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $log_entry);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

// Session Validation
function validate_session() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }

    $time = time();

    if (!isset($_SESSION['session_start_time']) || ($time - $_SESSION['session_start_time']) > SESSION_ABSOLUTE_TIMEOUT) {
        return false;
    }

    if (!isset($_SESSION['last_activity']) || ($time - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        return false;
    }

    $_SESSION['last_activity'] = $time;
    return true;
}

function require_login() {
    if (!validate_session()) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }
    
    // Check if password change is required
    $auth_data = json_decode(file_get_contents(AUTH_FILE), true);
    if ($auth_data['must_change_password'] && basename($_SERVER['PHP_SELF']) !== 'settings.php' && basename($_SERVER['PHP_SELF']) !== 'logout.php') {
        header("Location: settings.php");
        exit;
    }
}

// CSRF Protection
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        audit_log("csrf_failed", "Invalid or missing CSRF token.");
        die("CSRF token validation failed.");
    }
}

// Enhanced Throttling Logic
function check_throttle() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    if (!file_exists(THROTTLE_FILE)) return true;
    
    $fp = fopen(THROTTLE_FILE, 'c+');
    if (!$fp) return true;
    
    $is_allowed = true;
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $data = $content ? json_decode($content, true) : [];
        
        if (isset($data[$ip])) {
            $locked_until = $data[$ip]['locked_until'] ?? 0;
            if (time() < $locked_until) {
                $is_allowed = false; // Still locked
            } else if ($locked_until > 0 && time() >= $locked_until) {
                // Lock expired, reset but keep some history? For now just reset lock
                $data[$ip]['locked_until'] = 0;
                $data[$ip]['failed'] = 0; // Or partial decay
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data));
            }
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $is_allowed;
}

function record_failed_login() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $fp = fopen(THROTTLE_FILE, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $data = $content ? json_decode($content, true) : [];
        
        if (!isset($data[$ip])) {
            $data[$ip] = ['failed' => 0, 'last_attempt' => time(), 'locked_until' => 0];
        }
        
        $data[$ip]['failed']++;
        $data[$ip]['last_attempt'] = time();
        $f = $data[$ip]['failed'];
        
        if ($f >= 20) {
            $data[$ip]['locked_until'] = time() + 1800; // 30 mins
        } else if ($f >= 11) {
            $data[$ip]['locked_until'] = time() + 300;  // 5 mins
        } else if ($f >= 6) {
            $data[$ip]['locked_until'] = time() + 30;   // 30 secs
        }
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

function clear_throttle() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $fp = fopen(THROTTLE_FILE, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $data = $content ? json_decode($content, true) : [];
        if (isset($data[$ip])) {
            unset($data[$ip]);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
        }
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

function is_ip_allowed() {
    $allowlist_raw = getenv('ADMIN_IP_ALLOWLIST');
    if (empty($allowlist_raw)) return true; // Disabled if empty
    
    $allowed_ips = array_map('trim', explode(',', $allowlist_raw));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, $allowed_ips);
}
?>

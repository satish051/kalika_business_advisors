<?php
// admin/login.php
require_once 'auth.php';

if (!is_ip_allowed()) {
    audit_log("login_rejected", "IP not in allowlist.");
    http_response_code(403);
    die("Access Denied");
}

if (validate_session()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_throttle()) {
        $error = "Too many failed attempts. Please try again later.";
        audit_log("login_throttled", "IP locked out due to too many attempts.");
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $auth_data = json_decode(file_get_contents(AUTH_FILE), true);
        
        if ($username === $auth_data['username'] && password_verify($password, $auth_data['password_hash'])) {
            // Success
            clear_throttle();
            session_regenerate_id(true);
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['session_start_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Rehash if necessary
            if (password_needs_rehash($auth_data['password_hash'], PASSWORD_DEFAULT)) {
                $auth_data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                file_put_contents(AUTH_FILE, json_encode($auth_data, JSON_PRETTY_PRINT));
            }
            
            audit_log("login_success", "User authenticated.");
            
            if ($auth_data['must_change_password']) {
                header("Location: settings.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            // Failure
            record_failed_login();
            audit_log("login_failed", "Failed attempt for username: $username");
            $error = "Invalid username or password."; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <style>
        body { font-family: sans-serif; background: #080C11; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #121A25; padding: 40px; border-radius: 8px; border: 1px solid #D4AF37; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 100%; max-width: 400px; }
        h2 { color: #D4AF37; margin-top: 0; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 0.85rem; color: #aaa; text-transform: uppercase; }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #333; background: #000; color: white; border-radius: 4px; box-sizing: border-box; }
        input[type="text"]:focus, input[type="password"]:focus { outline: none; border-color: #D4AF37; }
        .btn { width: 100%; padding: 12px; background: #D4AF37; color: #080C11; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; text-transform: uppercase; }
        .btn:hover { background: #C5A028; }
        .error { color: #ff4444; margin-bottom: 20px; font-size: 0.9rem; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Admin Portal</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>
    </div>
</body>
</html>

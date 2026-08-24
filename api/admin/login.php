<?php
// api/admin/login.php
require_once 'auth.php';

if (validate_session()) {
    header("Location: /api/admin/dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_throttle()) {
        $error = "Too many failed attempts. Please try again later.";
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $auth_data = kv_get('auth_json') ?: [];
        
        if ($username === ($auth_data['username']??'') && password_verify($password, ($auth_data['password_hash']??''))) {
            clear_throttle();
            
            $sess = [
                'admin_logged_in' => true,
                'session_start_time' => time(),
                'last_activity' => time()
            ];
            set_stateless_session($sess);
            
            if (password_needs_rehash($auth_data['password_hash'], PASSWORD_DEFAULT)) {
                $auth_data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                kv_set('auth_json', $auth_data);
            }
            
            if (!empty($auth_data['must_change_password'])) {
                header("Location: /api/admin/settings.php");
            } else {
                header("Location: /api/admin/dashboard.php");
            }
            exit;
        } else {
            record_failed_login();
            $error = "Invalid username or password."; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - Vercel</title>
    <style>
        body { font-family: sans-serif; background: #080C11; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #121A25; padding: 40px; border-radius: 8px; border: 1px solid #D4AF37; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 100%; max-width: 400px; }
        h2 { color: #D4AF37; margin-top: 0; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 0.85rem; color: #aaa; text-transform: uppercase; }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #333; background: #000; color: white; border-radius: 4px; box-sizing: border-box; }
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
        <form method="POST" action="/api/admin/login.php">
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

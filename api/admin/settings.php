<?php
// api/admin/settings.php
require_once 'auth.php';
require_login();

$error_msg = '';
$success_msg = '';

$auth_data = kv_get('auth_json') ?: [];
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if (!password_verify($current, $auth_data['password_hash'] ?? '')) {
            throw new Exception("Current password is incorrect.");
        }
        
        if ($new !== $confirm) {
            throw new Exception("New passwords do not match.");
        }
        
        if (strlen($new) < 12) throw new Exception("Password must be at least 12 characters.");
        if (!preg_match('/[A-Z]/', $new)) throw new Exception("Password must contain at least one uppercase letter.");
        if (!preg_match('/[a-z]/', $new)) throw new Exception("Password must contain at least one lowercase letter.");
        if (!preg_match('/[0-9]/', $new)) throw new Exception("Password must contain at least one number.");
        if (!preg_match('/[^A-Za-z0-9]/', $new)) throw new Exception("Password must contain at least one special character.");
        
        foreach ($auth_data['password_history'] ?? [] as $old_hash) {
            if (password_verify($new, $old_hash)) {
                throw new Exception("You cannot reuse any of your last 5 passwords.");
            }
        }
        
        $auth_data['password_history'][] = $auth_data['password_hash'];
        if (count($auth_data['password_history']) > 5) {
            array_shift($auth_data['password_history']);
        }
        
        $auth_data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        $auth_data['must_change_password'] = false;
        $auth_data['password_changed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        
        if (kv_set('auth_json', $auth_data)) {
            $success_msg = "Password updated successfully.";
        } else {
            throw new Exception("Failed to save new password to KV.");
        }
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Settings</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f4f5; color: #333; margin: 0; }
        .sidebar { width: 250px; background: #080C11; color: white; position: fixed; height: 100vh; padding: 20px; box-sizing: border-box; }
        .sidebar h2 { color: #D4AF37; margin-top: 0; font-size: 1.2rem; margin-bottom: 40px; }
        .sidebar a { color: #ccc; text-decoration: none; display: block; margin-bottom: 15px; }
        .sidebar a:hover { color: #fff; }
        .main-content { margin-left: 250px; padding: 40px; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-width: 500px;}
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; }
        input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 12px 24px; background: #D4AF37; color: #080C11; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%;}
        .btn:hover { background: #C5A028; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        ul.policy { font-size: 0.85rem; color: #666; margin-top: 5px; padding-left: 20px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>Kalika Admin</h2>
        <?php if (!($auth_data['must_change_password']??true)): ?>
            <a href="/api/admin/dashboard.php">Dashboard</a>
            <a href="/api/admin/settings.php">Security Settings</a>
        <?php endif; ?>
        <a href="/api/admin/logout.php" style="color:#ff4444; margin-top: 50px;">Logout</a>
    </div>

    <div class="main-content">
        <h2>Security Settings</h2>
        
        <?php if ($auth_data['must_change_password']): ?>
            <div class="alert alert-danger">You must change your password before continuing.</div>
        <?php endif; ?>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required>
                    <ul class="policy">
                        <li>Minimum 12 characters</li>
                        <li>Uppercase & lowercase letters</li>
                        <li>At least one number & special character</li>
                        <li>Cannot be your last 5 passwords</li>
                    </ul>
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit" class="btn">Update Password</button>
            </form>
        </div>
    </div>

</body>
</html>

<?php
// admin/dashboard.php
require_once 'auth.php';
require_login();

$uploads_dir = __DIR__ . '/../uploads/';

// --- Helpers for Image Hardening ---
function process_image_upload($file_input_name) {
    global $uploads_dir;
    
    if (!isset($_FILES[$file_input_name]) || $_FILES[$file_input_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    
    $file = $_FILES[$file_input_name];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload failed: " . $file['error']);
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception("File too large. Max 5MB.");
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    
    $img_info = getimagesize($file['tmp_name']);
    if ($img_info === false) {
        throw new Exception("File is not a valid image.");
    }
    
    $width = $img_info[0];
    $height = $img_info[1];
    
    if ($width > 2500 || $height > 2500) {
        throw new Exception("Image exceeds maximum dimensions (2500x2500).");
    }
    
    $allowed_mimes = [
        'image/jpeg' => ['ext' => 'jpg', 'create' => 'imagecreatefromjpeg', 'save' => 'imagejpeg'],
        'image/png'  => ['ext' => 'png', 'create' => 'imagecreatefrompng', 'save' => 'imagepng'],
        'image/webp' => ['ext' => 'webp', 'create' => 'imagecreatefromwebp', 'save' => 'imagewebp']
    ];
    
    if (!array_key_exists($mime_type, $allowed_mimes)) {
        throw new Exception("Only JPEG, PNG, and WebP are allowed.");
    }
    
    $info = $allowed_mimes[$mime_type];
    
    $create_func = $info['create'];
    $save_func = $info['save'];
    
    $source_img = @$create_func($file['tmp_name']);
    if (!$source_img) {
        throw new Exception("Image is corrupt or cannot be processed.");
    }
    
    $clean_img = imagecreatetruecolor($width, $height);
    
    if ($mime_type === 'image/png' || $mime_type === 'image/webp') {
        imagealphablending($clean_img, false);
        imagesavealpha($clean_img, true);
        $transparent = imagecolorallocatealpha($clean_img, 255, 255, 255, 127);
        imagefilledrectangle($clean_img, 0, 0, $width, $height, $transparent);
    }
    
    imagecopyresampled($clean_img, $source_img, 0, 0, 0, 0, $width, $height, $width, $height);
    
    $new_filename = bin2hex(random_bytes(16)) . '.' . $info['ext'];
    $destination = $uploads_dir . $new_filename;
    
    $success = false;
    if ($mime_type === 'image/jpeg') {
        $success = $save_func($clean_img, $destination, 90);
    } else {
        $success = $save_func($clean_img, $destination);
    }
    
    imagedestroy($source_img);
    imagedestroy($clean_img);
    unlink($file['tmp_name']);
    
    if (!$success) {
        throw new Exception("Failed to save processed image.");
    }
    
    audit_log("image_upload", "Uploaded new image: $new_filename");
    return 'uploads/' . $new_filename;
}

// --- Helpers for Backups ---
function rotate_backups() {
    if (!file_exists(DATA_FILE)) return;
    
    $timestamp = date('Ymd_His');
    $backup_file = BACKUP_DIR . 'data_' . $timestamp . '.json';
    $checksum_file = $backup_file . '.sha256';
    
    copy(DATA_FILE, $backup_file);
    file_put_contents($checksum_file, hash_file('sha256', $backup_file));
    
    $files = glob(BACKUP_DIR . 'data_*.json');
    if (count($files) > MAX_BACKUPS) {
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $to_delete = array_slice($files, MAX_BACKUPS);
        foreach ($to_delete as $f) {
            unlink($f);
            if (file_exists($f . '.sha256')) unlink($f . '.sha256');
        }
    }
}

// --- Validation Helpers ---
function validate_url($url) {
    $url = filter_var($url, FILTER_SANITIZE_URL);
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $host = parse_url($url, PHP_URL_HOST);
        if (preg_match('/(youtube\.com|youtu\.be|vimeo\.com)$/i', $host)) {
            return $url;
        }
    }
    return false;
}

function truncate_str($str, $max_len) {
    $str = trim((string)$str);
    return mb_substr($str, 0, $max_len);
}

$success_msg = '';
$error_msg = '';

$default_data = [
    "hero_title" => "A consulting firm for everything.",
    "hero_description" => "Chartered accountants, lawyers, policy drafters, environmental specialists, former senior officials, and veteran bankers.",
    "hero_bg" => "amazing-panorama-from-gokyo-ri-viewpoint-mount-everest-lho-la-nuptse-lhotse-peaks-sagarmatha-national-park-nepalgolden-sunrise-with-clear-blue-sky-mt-everest-peak-view.jpg",
    "founder_img" => "Gemini_Generated_Image_mebqh2mebqh2mebq.jpg",
    "video_url" => "https://www.youtube-nocookie.com/embed/ScMzIvxBSi4?controls=0&rel=0&autoplay=0&mute=1&loop=1&playlist=ScMzIvxBSi4",
    "notice" => [
        "enabled" => false,
        "title" => "Important Notice",
        "message" => "Welcome to our newly updated platform.",
        "button_text" => "Acknowledge"
    ]
];

$fp = fopen(DATA_FILE, 'c+');
if (!$fp) die("Cannot open data file.");

if (flock($fp, LOCK_EX)) {
    
    $json_content = stream_get_contents($fp);
    $data = $default_data;
    if (!empty($json_content)) {
        $parsed = json_decode($json_content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $data = array_merge($default_data, $parsed);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            verify_csrf_token($_POST['csrf_token'] ?? '');
            
            if (isset($_POST['action']) && $_POST['action'] === 'restore') {
                // Restore Backup Logic
                $backup_target = basename($_POST['backup_file'] ?? '');
                $auth_data = json_decode(file_get_contents(AUTH_FILE), true);
                
                if (!password_verify($_POST['confirm_password'] ?? '', $auth_data['password_hash'])) {
                    throw new Exception("Invalid password for restore operation.");
                }
                
                $b_file = BACKUP_DIR . $backup_target;
                $c_file = $b_file . '.sha256';
                
                if (!file_exists($b_file) || !file_exists($c_file)) {
                    throw new Exception("Backup file or checksum missing.");
                }
                
                $expected_hash = trim(file_get_contents($c_file));
                $actual_hash = hash_file('sha256', $b_file);
                
                if (!hash_equals($expected_hash, $actual_hash)) {
                    audit_log("restore_failed", "Checksum mismatch on $backup_target");
                    throw new Exception("Backup integrity check failed! File may be corrupted.");
                }
                
                rotate_backups(); // Backup current before restore
                
                $temp_file = DATA_FILE . '.tmp.' . uniqid();
                if (copy($b_file, $temp_file) && rename($temp_file, DATA_FILE)) {
                    $success_msg = "Backup restored securely.";
                    audit_log("backup_restored", "Restored $backup_target");
                    // Reload data
                    rewind($fp);
                    $data = json_decode(stream_get_contents($fp), true) ?: $default_data;
                } else {
                    throw new Exception("Failed to apply backup.");
                }
                
            } else {
                // Normal Save Logic
                $data['hero_title'] = truncate_str($_POST['hero_title'] ?? '', 100);
                $data['hero_description'] = truncate_str($_POST['hero_description'] ?? '', 500);
                
                $valid_url = validate_url($_POST['video_url'] ?? '');
                if ($valid_url) {
                    $data['video_url'] = $valid_url;
                } else {
                    throw new Exception("Invalid Video URL. Only YouTube or Vimeo are allowed.");
                }
                
                $data['notice']['enabled'] = isset($_POST['notice_enabled']) ? true : false;
                $data['notice']['title'] = truncate_str($_POST['notice_title'] ?? '', 100);
                $data['notice']['message'] = truncate_str($_POST['notice_message'] ?? '', 1000);
                $data['notice']['button_text'] = truncate_str($_POST['notice_button_text'] ?? '', 50);
                
                $new_hero_bg = process_image_upload('hero_bg_file');
                if ($new_hero_bg) $data['hero_bg'] = $new_hero_bg;
                
                $new_founder_img = process_image_upload('founder_img_file');
                if ($new_founder_img) $data['founder_img'] = $new_founder_img;
                
                rotate_backups();
                
                $json_out = json_encode($data, JSON_PRETTY_PRINT);
                $temp_file = DATA_FILE . '.tmp.' . uniqid();
                if (file_put_contents($temp_file, $json_out) !== false) {
                    if (rename($temp_file, DATA_FILE)) {
                        $success_msg = "Settings updated securely.";
                        audit_log("content_update", "Dashboard settings updated.");
                    } else {
                        unlink($temp_file);
                        throw new Exception("Failed to rename temporary file.");
                    }
                } else {
                    throw new Exception("Failed to write to temporary file.");
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            audit_log("update_error", $error_msg);
        }
    }
    flock($fp, LOCK_UN);
} else {
    $error_msg = "Could not acquire lock on data file. Please try again.";
}
fclose($fp);

$csrf_token = generate_csrf_token();

// Load Security Overview Data
$auth_data = json_decode(file_get_contents(AUTH_FILE), true);
$throttle_data = file_exists(THROTTLE_FILE) ? json_decode(file_get_contents(THROTTLE_FILE), true) : [];
$total_fails = 0;
foreach ($throttle_data as $ip => $t) $total_fails += $t['failed'] ?? 0;
$backups = glob(BACKUP_DIR . 'data_*.json');
rsort($backups);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f4f5; color: #333; margin: 0; }
        .sidebar { width: 250px; background: #080C11; color: white; position: fixed; height: 100vh; padding: 20px; box-sizing: border-box; }
        .sidebar h2 { color: #D4AF37; margin-top: 0; font-size: 1.2rem; margin-bottom: 40px; }
        .sidebar a { color: #ccc; text-decoration: none; display: block; margin-bottom: 15px; }
        .sidebar a:hover { color: #fff; }
        .main-content { margin-left: 250px; padding: 40px; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 30px; }
        h3 { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; }
        input[type="text"], input[type="url"], input[type="password"], textarea, select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        .btn { padding: 12px 24px; background: #080C11; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn:hover { background: #D4AF37; color: #080C11; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 4px solid #D4AF37; }
        .stat-value { font-size: 1.5rem; font-weight: bold; margin-top: 10px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>Kalika Admin</h2>
        <a href="dashboard.php">Dashboard</a>
        <a href="settings.php">Security Settings</a>
        <a href="../" target="_blank">View Website</a>
        <a href="logout.php" style="color:#ff4444; margin-top: 50px;">Logout</a>
    </div>

    <div class="main-content">
        <h2>Dashboard</h2>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-box">
                <div>Failed Login Attempts (Total)</div>
                <div class="stat-value"><?php echo $total_fails; ?></div>
            </div>
            <div class="stat-box">
                <div>Last Password Change</div>
                <div class="stat-value" style="font-size: 1rem;"><?php echo htmlspecialchars($auth_data['password_changed_at']); ?></div>
            </div>
            <div class="stat-box">
                <div>Available Backups</div>
                <div class="stat-value"><?php echo count($backups); ?></div>
            </div>
        </div>

        <form method="POST" action="dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="save">
            
            <div class="card">
                <h3>Content Settings</h3>
                <div class="form-group">
                    <label>Hero Title (Max 100 chars)</label>
                    <input type="text" name="hero_title" maxlength="100" value="<?php echo htmlspecialchars($data['hero_title']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Hero Description (Max 500 chars)</label>
                    <textarea name="hero_description" rows="3" maxlength="500" required><?php echo htmlspecialchars($data['hero_description']); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Hero Background Image (JPEG/PNG/WebP, Max 5MB/2500px)</label>
                    <input type="file" name="hero_bg_file" accept="image/jpeg, image/png, image/webp">
                </div>
                <div class="form-group">
                    <label>Founder Image (JPEG/PNG/WebP, Max 5MB/2500px)</label>
                    <input type="file" name="founder_img_file" accept="image/jpeg, image/png, image/webp">
                </div>
                <div class="form-group">
                    <label>Video URL (YouTube/Vimeo)</label>
                    <input type="url" name="video_url" value="<?php echo htmlspecialchars($data['video_url']); ?>" required>
                </div>
            </div>

            <button type="submit" class="btn">Save Changes</button>
        </form>
        
        <form method="POST" action="dashboard.php" style="margin-top: 40px;">
            <div class="card" style="border-left: 4px solid #dc3545;">
                <h3 style="color: #dc3545;">Disaster Recovery (Restore Backup)</h3>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="restore">
                
                <div class="form-group">
                    <label>Select Backup</label>
                    <select name="backup_file" required>
                        <?php foreach($backups as $b): ?>
                            <option value="<?php echo htmlspecialchars(basename($b)); ?>"><?php echo htmlspecialchars(basename($b)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Confirm Your Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn btn-danger">Restore Backup</button>
            </div>
        </form>
    </div>

</body>
</html>

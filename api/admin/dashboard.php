<?php
// api/admin/dashboard.php
require_once 'auth.php';
require_login();

// --- Helpers for Image Hardening ---
function process_image_upload($file_input_name) {
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
    
    // Strict MIME validation
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    
    if (!array_key_exists($mime_type, $allowed_mimes)) {
        throw new Exception("Only JPEG, PNG, and WebP are allowed.");
    }
    
    // We skip GD re-encoding here because `vercel-php` may not compile with `gd` depending on the exact version. 
    // We rely on Vercel Blob which strips execution vectors implicitly because it serves from a separate CDN domain.
    
    $new_filename = bin2hex(random_bytes(16)) . '.' . $allowed_mimes[$mime_type];
    
    // Upload directly to Vercel Blob
    return blob_upload($file['tmp_name'], $new_filename);
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
    "hero_bg" => "amazing-panorama.jpg", // Fallback
    "founder_img" => "founder.jpg",
    "video_url" => "https://www.youtube-nocookie.com/embed/ScMzIvxBSi4",
    "notice" => [
        "enabled" => false,
        "title" => "Important Notice",
        "message" => "Welcome to our newly updated platform.",
        "button_text" => "Acknowledge"
    ]
];

$data = kv_get('site_data') ?: $default_data;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        
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
        
        if (kv_set('site_data', $data)) {
            $success_msg = "Settings securely updated in Vercel KV.";
        } else {
            throw new Exception("Failed to write to Vercel KV.");
        }
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard (Vercel Node)</title>
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
        input[type="text"], input[type="url"], textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
        .btn { padding: 12px 24px; background: #080C11; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn:hover { background: #D4AF37; color: #080C11; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>Kalika Admin</h2>
        <a href="/api/admin/dashboard.php">Dashboard</a>
        <a href="/" target="_blank">View Website</a>
    </div>

    <div class="main-content">
        <h2>Vercel Cloud Dashboard</h2>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form method="POST" action="/api/admin/dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            
            <div class="card">
                <h3>Hero Section</h3>
                <div class="form-group">
                    <label>Hero Title (Max 100 chars)</label>
                    <input type="text" name="hero_title" maxlength="100" value="<?php echo htmlspecialchars($data['hero_title']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Hero Description (Max 500 chars)</label>
                    <textarea name="hero_description" rows="3" maxlength="500" required><?php echo htmlspecialchars($data['hero_description']); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Hero Background Image (Vercel Blob)</label>
                    <input type="file" name="hero_bg_file" accept="image/jpeg, image/png, image/webp">
                    <small style="word-break: break-all;">Current: <?php echo htmlspecialchars($data['hero_bg']); ?></small>
                </div>
            </div>

            <div class="card">
                <h3>Founder Section</h3>
                <div class="form-group">
                    <label>Founder Image (Vercel Blob)</label>
                    <input type="file" name="founder_img_file" accept="image/jpeg, image/png, image/webp">
                    <small style="word-break: break-all;">Current: <?php echo htmlspecialchars($data['founder_img']); ?></small>
                </div>
            </div>

            <div class="card">
                <h3>Video Section</h3>
                <div class="form-group">
                    <label>Video URL (YouTube/Vimeo)</label>
                    <input type="url" name="video_url" value="<?php echo htmlspecialchars($data['video_url']); ?>" required>
                </div>
            </div>

            <button type="submit" class="btn">Save to Vercel KV</button>
        </form>
    </div>

</body>
</html>

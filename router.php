<?php
// router.php
// For local testing: php -S localhost:8000 router.php

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$ext = pathinfo($path, PATHINFO_EXTENSION);

// Block access to protected directories and files
if (preg_match('#^/(storage|admin/storage)#i', $path) || in_array($ext, ['json', 'log', 'ini'])) {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
}

// Block PHP execution in uploads
if (preg_match('#^/uploads/.*\.php$#i', $path)) {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
}

// Serve standard files
if (file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
    return false; // Let the built-in server handle it
}

// Fallback to index.php
if ($path === '/' || empty($path)) {
    require __DIR__ . '/index.php';
} else {
    http_response_code(404);
    echo "404 Not Found";
}
?>

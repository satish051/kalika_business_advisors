<?php
// api/storage/vercel_helpers.php

// Vercel KV Helpers
function kv_get($key) {
    $url = rtrim(getenv('KV_REST_API_URL'), '/') . '/get/' . $key;
    $token = getenv('KV_REST_API_TOKEN');
    if (!$token) return null;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    
    $json = json_decode($res, true);
    if (isset($json['result'])) {
        return is_string($json['result']) ? json_decode($json['result'], true) : $json['result'];
    }
    return null;
}

function kv_set($key, $value) {
    $url = rtrim(getenv('KV_REST_API_URL'), '/') . '/set/' . $key;
    $token = getenv('KV_REST_API_TOKEN');
    if (!$token) return false;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(json_encode($value))); // Double encode for Vercel KV value string
    $res = curl_exec($ch);
    curl_close($ch);
    return true;
}

// Vercel Blob Helpers
function blob_upload($file_tmp_path, $filename) {
    $token = getenv('BLOB_READ_WRITE_TOKEN');
    if (!$token) throw new Exception("Vercel Blob token is missing.");
    
    $url = 'https://blob.vercel-storage.com/' . $filename;
    $file_content = file_get_contents($file_tmp_path);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "x-api-version: 7"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
    $res = curl_exec($ch);
    curl_close($ch);
    
    $json = json_decode($res, true);
    if (isset($json['url'])) {
        return $json['url'];
    }
    throw new Exception("Failed to upload to Vercel Blob.");
}

// Stateless Session Helpers
define('SECRET_KEY', getenv('APP_SECRET') ?: 'temporary_secret_change_in_production');

function set_stateless_session($data) {
    $payload = base64_encode(json_encode($data));
    $signature = hash_hmac('sha256', $payload, SECRET_KEY);
    $cookie = $payload . '.' . $signature;
    
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie('vercel_session', $cookie, [
        'expires' => time() + 43200,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

function get_stateless_session() {
    if (!isset($_COOKIE['vercel_session'])) return null;
    $parts = explode('.', $_COOKIE['vercel_session']);
    if (count($parts) !== 2) return null;
    list($payload, $signature) = $parts;
    
    if (hash_equals(hash_hmac('sha256', $payload, SECRET_KEY), $signature)) {
        return json_decode(base64_decode($payload), true);
    }
    return null;
}

function destroy_stateless_session() {
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie('vercel_session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}
?>

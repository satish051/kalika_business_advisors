<?php
// storage/config.php

// Simple .env parser
function load_env() {
    $env_file = __DIR__ . '/../.env';
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
load_env();

// Application Constants
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN));
define('DATA_FILE', __DIR__ . '/data.json');
define('AUTH_FILE', __DIR__ . '/auth.json');
define('AUDIT_LOG', __DIR__ . '/audit.log');
define('THROTTLE_FILE', __DIR__ . '/throttle.json');
define('BACKUP_DIR', __DIR__ . '/backups/');
define('MAX_BACKUPS', 10);

// Security Settings
define('SESSION_IDLE_TIMEOUT', (int)(getenv('SESSION_IDLE_TIMEOUT') ?: 1800));
define('SESSION_ABSOLUTE_TIMEOUT', (int)(getenv('SESSION_ABSOLUTE_TIMEOUT') ?: 43200));

// Production Error Handling
if (APP_ENV === 'production' || !APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/php-errors.log');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/_app/api/index.php'));
$apiDir = rtrim(dirname($script), '/');
$basePath = preg_replace('#/(?:_app/)?api(?:/index\.php)?$#', '', $apiDir) ?: '';
$baseUrl = (string)env('APP_URL', $host !== '' ? $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') : ($basePath !== '' ? $basePath : '/'));

$dbHost = (string)env('DB_HOST', 'localhost');
$dbPort = (int)env('DB_PORT', '3306');
$dbName = (string)env('DB_DATABASE', '');
$dbUser = (string)env('DB_USERNAME', '');
$dbPass = (string)env('DB_PASSWORD', '');
$dbCharset = (string)env('DB_CHARSET', 'utf8mb4');
$sessionName = (string)env('SESSION_COOKIE_NAME', 'cogs_admin_session');

return [
    'app' => [
        'name' => (string)env('APP_NAME', 'COGS Admin'),
        'env' => (string)env('APP_ENV', 'production'),
        'base_url' => $baseUrl,
        'session_name' => $sessionName,
        'session_secret' => (string)env('SESSION_SECRET', 'change-this-session-secret'),
    ],
    'db' => [
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => $dbName,
        'username' => $dbUser,
        'password' => $dbPass,
        'charset' => $dbCharset,
    ],
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_name' => $dbName,
    'db_user' => $dbUser,
    'db_pass' => $dbPass,
    'db_charset' => $dbCharset,
    'session_name' => $sessionName,
    'session_cookie_secure' => filter_var(
        (string)env('SESSION_COOKIE_SECURE', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'seed_admin_reset_password' => (string)env('SEED_ADMIN_RESET_PASSWORD', 'change-this-admin-bootstrap-password'),
    'auth' => [
        'admin_email' => (string)env('ADMIN_EMAIL', 'admin@cogsaustralia.org'),
        'bootstrap_token' => (string)env('ADMIN_BOOTSTRAP_TOKEN', ''),
        'totp_issuer' => (string)env('ADMIN_TOTP_ISSUER', 'COGS Admin'),
    ],
];

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
$config = require __DIR__ . '/../_app/api/config/app.php';
$sessionName = (string)($config['app']['session_name'] ?? $config['session_name'] ?? 'cogs_admin_session');
if ($sessionName !== '') { session_name($sessionName); }
// Match the cookie params used in ops_start_admin_php_session() so the expiry
// cookie issued below shares the same Secure/HttpOnly/SameSite attributes
// as the cookie that originally set the session.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?: '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
}
session_destroy();
header('Location: ' . admin_url('index.php'));
exit;

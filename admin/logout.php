<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
$config = require __DIR__ . '/../_app/api/config/app.php';
$sessionName = (string)($config['app']['session_name'] ?? $config['session_name'] ?? 'cogs_admin_session');
if ($sessionName !== '') { session_name($sessionName); }
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?: '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
}
session_destroy();
header('Location: ' . admin_url('index.php'));
exit;

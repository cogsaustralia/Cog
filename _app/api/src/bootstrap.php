<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden session cookie flags explicitly rather than relying on php.ini defaults.
    // Mirrors cookieOptions() in _app/api/helpers.php for the API session cookie.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../../../admin/includes/ops_workflow.php';

function api_current_admin_user_id(PDO $pdo): ?int {
    return ops_current_admin_user_id($pdo);
}

function api_current_legacy_admin_id(PDO $pdo): ?int {
    return ops_current_legacy_admin_id($pdo);
}

<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../admin/includes/ops_workflow.php';

function api_current_admin_user_id(PDO $pdo): ?int {
    return ops_current_admin_user_id($pdo);
}

function api_current_legacy_admin_id(PDO $pdo): ?int {
    return ops_current_legacy_admin_id($pdo);
}

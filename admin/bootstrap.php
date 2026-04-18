<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$adminUserId = ops_current_admin_user_id($pdo);
$legacyAdminId = ops_current_legacy_admin_id($pdo);

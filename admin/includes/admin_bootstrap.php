<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_paths.php';
require_once __DIR__ . '/ops_workflow.php';

ops_require_admin();

function admin_get_pdo(): PDO {
    return ops_db();
}

<?php
declare(strict_types=1);

/**
 * governance_cron.php
 * COGS of Australia Foundation — Governance Cron
 *
 * Phase 1 stub. Logs a heartbeat run.
 * Phase 2 will add Members Poll Minute auto-generation here.
 * Phase 3 will add Board Meeting deadline checks.
 *
 * Intended to run every 5 minutes via cPanel cron:
 *   * /5 * * * * php /home4/cogsaust/public_html/_app/cron/governance_cron.php >> /home4/cogsaust/governance_cron.log 2>&1
 */

require_once dirname(__DIR__) . '/api/config/bootstrap.php';
require_once dirname(__DIR__) . '/api/config/database.php';

$phase = 'phase_1_stub';
$nowDb = gmdate('Y-m-d H:i:s');

try {
    $db = getDB();
    $db->prepare(
        "INSERT INTO governance_cron_log (run_at, phase, action, result, detail)
         VALUES (UTC_TIMESTAMP(), ?, 'heartbeat', 'ok', ?)"
    )->execute([$phase, 'Governance cron Phase 1 stub — ' . $nowDb]);

    echo "[{$nowDb}] governance_cron OK (Phase 1 stub)\n";
} catch (\Throwable $e) {
    echo "[{$nowDb}] governance_cron ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

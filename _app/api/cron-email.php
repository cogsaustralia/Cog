<?php
declare(strict_types=1);

/**
 * COG$ of Australia Foundation — Email Queue Cron Processor
 *
 * Processes the email_queue table independently of web requests.
 * Solves the client-disconnect problem where PHP-FPM kills the worker
 * before inline processEmailQueue() can complete an SMTP send.
 *
 * CRON SETUP (cPanel Cron Jobs — every 5 minutes):
 *   * /5 * * * * php /home4/cogsaust/public_html/_app/api/cron-email.php >> /home4/cogsaust/logs/email-cron.log 2>&1
 *
 * SECURITY: Only callable from CLI or localhost. Any other caller gets a 403.
 * This file must NOT be in a publicly-browsable directory without .htaccess protection.
 */

ignore_user_abort(true);
set_time_limit(120);

// ── Access guard ─────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '127.0.0.1' && $ip !== '::1') {
        http_response_code(403);
        exit('Forbidden');
    }
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/mailer.php';

// ── Process ──────────────────────────────────────────────────────────────────
$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s T');

try {
    $db     = getDB();
    $result = processEmailQueue($db, 50);

    $elapsed   = round(microtime(true) - $startTime, 2);
    $processed = (int)($result['processed'] ?? 0);
    $enabled   = $result['enabled'] ?? false;
    $provider  = $result['provider'] ?? 'unknown';

    if (!$enabled) {
        echo "[{$timestamp}] [cron-email] SKIPPED — mailer not enabled (provider={$provider})\n";
    } elseif ($processed === 0) {
        echo "[{$timestamp}] [cron-email] Queue empty — nothing to send ({$elapsed}s)\n";
    } else {
        echo "[{$timestamp}] [cron-email] Processed {$processed} email(s) in {$elapsed}s\n";
    }

} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[{$timestamp}] [cron-email] ERROR after {$elapsed}s: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
declare(strict_types=1);

/**
 * COG$ of Australia Foundation — Error Digest Cron
 *
 * Sends a daily summary of unacknowledged application errors to the admin
 * email address. Runs once per day at 07:00 AEST. Also runs an hourly
 * check and sends an urgent alert if NEW unacknowledged errors appeared
 * in the last 60 minutes that were not already emailed by the real-time
 * beacon alert in client-error.php.
 *
 * Two modes, controlled by the --mode argument:
 *
 *   --mode=daily   (07:00 AEST) — full 24h digest, even if no new errors.
 *                                 Sends a clean bill of health if zero errors.
 *   --mode=hourly  (every hour) — sends only if NEW errors arrived in the
 *                                 last 65 minutes (5-min overlap guards gaps).
 *                                 Silent if nothing new.
 *
 * CRON SETUP (cPanel Cron Jobs):
 *
 *   Daily digest at 07:00 AEST (= 21:00 UTC):
 *   0 21 * * * php /home4/cogsaust/public_html/_app/api/cron-error-digest.php --mode=daily >> /home4/cogsaust/logs/error-digest.log 2>&1
 *
 *   Hourly new-error check:
 *   5 * * * * php /home4/cogsaust/public_html/_app/api/cron-error-digest.php --mode=hourly >> /home4/cogsaust/logs/error-digest.log 2>&1
 *
 * SECURITY: CLI-only. Any web request gets a 403.
 */

ignore_user_abort(true);
set_time_limit(60);

// ── CLI-only guard ────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ── Parse --mode argument ─────────────────────────────────────────────────────
$mode = 'daily';
foreach ($argv ?? [] as $arg) {
    if ($arg === '--mode=hourly') { $mode = 'hourly'; break; }
    if ($arg === '--mode=daily')  { $mode = 'daily';  break; }
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/mailer.php';

$timestamp = date('Y-m-d H:i:s T');
$startTime = microtime(true);

// ── Abort if mailer not configured ───────────────────────────────────────────
if (!mailerEnabled()) {
    echo "[{$timestamp}] [cron-error-digest/{$mode}] SKIPPED — mailer not enabled\n";
    exit(0);
}

$adminEmail = MAIL_ADMIN_EMAIL ?: 'admin@cogsaustralia.org';
$alertEmail = 'admin@cogsaustralia.org';  // always CC this regardless of env config

try {
    $db = getDB();

    // ── Check table exists ────────────────────────────────────────────────────
    $tableExists = (bool)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_error_log'"
    )->fetchColumn();

    if (!$tableExists) {
        echo "[{$timestamp}] [cron-error-digest/{$mode}] SKIPPED — app_error_log table does not exist\n";
        exit(0);
    }

    // ── HOURLY MODE: only fire if new errors in last 65 minutes ──────────────
    if ($mode === 'hourly') {
        $newCount = (int)$db->query(
            "SELECT COUNT(*) FROM app_error_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 65 MINUTE)
                AND acknowledged = 0"
        )->fetchColumn();

        if ($newCount === 0) {
            $elapsed = round(microtime(true) - $startTime, 2);
            echo "[{$timestamp}] [cron-error-digest/hourly] OK — no new errors ({$elapsed}s)\n";
            exit(0);
        }

        // Fetch the new errors for the alert
        $newErrors = $db->query(
            "SELECT route, http_status, LEFT(error_message, 300) AS msg,
                    area_key, created_at
               FROM app_error_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 65 MINUTE)
                AND acknowledged = 0
              ORDER BY id DESC
              LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalUnack = (int)$db->query(
            "SELECT COUNT(*) FROM app_error_log WHERE acknowledged = 0"
        )->fetchColumn();

        sendHourlyAlert($adminEmail, $alertEmail, $newCount, $totalUnack, $newErrors, $timestamp);

        $elapsed = round(microtime(true) - $startTime, 2);
        echo "[{$timestamp}] [cron-error-digest/hourly] Sent alert — {$newCount} new error(s) in last 65min ({$elapsed}s)\n";
        exit(0);
    }

    // ── DAILY MODE: full 24h digest regardless of count ──────────────────────
    $last24hTotal = (int)$db->query(
        "SELECT COUNT(*) FROM app_error_log
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )->fetchColumn();

    $totalUnack = (int)$db->query(
        "SELECT COUNT(*) FROM app_error_log WHERE acknowledged = 0"
    )->fetchColumn();

    // Error class breakdown for the last 24h
    $breakdown = $db->query(
        "SELECT route,
                http_status,
                LEFT(error_message, 120) AS snippet,
                COUNT(*) AS hits,
                SUM(acknowledged = 0) AS unack,
                MIN(created_at) AS first_seen,
                MAX(created_at) AS last_seen
           FROM app_error_log
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          GROUP BY route, http_status, LEFT(error_message, 120)
          ORDER BY hits DESC
          LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Most recent 10 unacknowledged entries for context
    $recentUnack = $db->query(
        "SELECT route, http_status, LEFT(error_message, 300) AS msg,
                area_key, created_at
           FROM app_error_log
          WHERE acknowledged = 0
          ORDER BY id DESC
          LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    sendDailyDigest(
        $adminEmail, $alertEmail,
        $last24hTotal, $totalUnack,
        $breakdown, $recentUnack,
        $timestamp
    );

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[{$timestamp}] [cron-error-digest/daily] Digest sent — {$last24hTotal} errors in 24h, {$totalUnack} unack ({$elapsed}s)\n";

} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[{$timestamp}] [cron-error-digest/{$mode}] ERROR after {$elapsed}s: " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// EMAIL BUILDERS
// ─────────────────────────────────────────────────────────────────────────────

function sendHourlyAlert(
    string $to, string $cc,
    int $newCount, int $totalUnack,
    array $errors,
    string $timestamp
): void {
    $subject = "[COGS ALERT] {$newCount} new error(s) detected — action required";

    $rowsHtml = '';
    $rowsText = '';
    foreach ($errors as $i => $e) {
        $label  = (int)$e['http_status'] === 0 ? 'JS' : (string)$e['http_status'];
        $color  = (int)$e['http_status'] === 0 ? '#0ea5e9' : '#ef4444';
        $route  = htmlspecialchars((string)($e['route'] ?? ''));
        $msg    = htmlspecialchars(substr((string)($e['msg'] ?? ''), 0, 200));
        $time   = htmlspecialchars(substr((string)($e['created_at'] ?? ''), 0, 16));
        $rowsHtml .= "<tr>"
            . "<td style='padding:6px 10px;border-bottom:1px solid #e2e8f0;font-weight:700;color:{$color};'>{$label}</td>"
            . "<td style='padding:6px 10px;border-bottom:1px solid #e2e8f0;font-family:monospace;font-size:0.85em;color:#64748b;'>{$route}</td>"
            . "<td style='padding:6px 10px;border-bottom:1px solid #e2e8f0;'>{$msg}</td>"
            . "<td style='padding:6px 10px;border-bottom:1px solid #e2e8f0;color:#94a3b8;white-space:nowrap;font-size:0.85em;'>{$time}</td>"
            . "</tr>";
        $rowsText .= ($i + 1) . ". [{$label}] {$e['route']} — " . substr((string)($e['msg'] ?? ''), 0, 120) . " ({$e['created_at']})\n";
    }

    $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1e293b;max-width:700px;margin:0 auto;padding:20px;">'
        . '<div style="background:#ef4444;color:#fff;padding:14px 20px;border-radius:8px 8px 0 0;">'
        . '<strong>COGS Error Alert</strong> &mdash; ' . htmlspecialchars($timestamp)
        . '</div>'
        . '<div style="border:1px solid #e2e8f0;border-top:none;padding:20px;border-radius:0 0 8px 8px;">'
        . "<p><strong>{$newCount} new unacknowledged error(s)</strong> appeared on cogsaustralia.org in the last hour.</p>"
        . "<p>Total unacknowledged in log: <strong>{$totalUnack}</strong></p>"
        . '<table style="width:100%;border-collapse:collapse;font-size:0.9em;">'
        . '<thead><tr>'
        . '<th style="text-align:left;padding:6px 10px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Type</th>'
        . '<th style="text-align:left;padding:6px 10px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Route / Page</th>'
        . '<th style="text-align:left;padding:6px 10px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Message</th>'
        . '<th style="text-align:left;padding:6px 10px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Time</th>'
        . '</tr></thead>'
        . "<tbody>{$rowsHtml}</tbody>"
        . '</table>'
        . '<p style="margin-top:20px;"><a href="https://cogsaustralia.org/admin/errors.php" style="background:#1e293b;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold;">Acknowledge in Admin Panel</a></p>'
        . '<p style="color:#94a3b8;font-size:0.82em;">Sent by cron-error-digest. Hourly checks run at :05 past each hour.</p>'
        . '</div></body></html>';

    $text = "COGS ERROR ALERT — {$timestamp}\n"
        . str_repeat('=', 60) . "\n"
        . "{$newCount} new error(s) in the last hour. Total unacknowledged: {$totalUnack}\n\n"
        . $rowsText
        . "\nAcknowledge at: https://cogsaustralia.org/admin/errors.php\n";

    smtpSendEmail($to, $subject, $html, $text);
    if ($cc !== $to) {
        try { smtpSendEmail($cc, $subject, $html, $text); } catch (Throwable $e) {}
    }
}

function sendDailyDigest(
    string $to, string $cc,
    int $last24h, int $totalUnack,
    array $breakdown, array $recentUnack,
    string $timestamp
): void {
    $subject = $last24h === 0
        ? '[COGS Daily] Error log — clean (0 errors in 24h)'
        : "[COGS Daily] Error log — {$last24h} error(s) in 24h, {$totalUnack} unacknowledged";

    $statusColor = $totalUnack === 0 ? '#22c55e' : ($totalUnack < 5 ? '#f59e0b' : '#ef4444');
    $statusLabel = $totalUnack === 0 ? 'All clear' : "{$totalUnack} need attention";

    // Breakdown table HTML
    $bRowsHtml = '';
    $bRowsText = '';
    foreach ($breakdown as $i => $r) {
        $label = (int)$r['http_status'] === 0 ? 'JS' : (string)$r['http_status'];
        $color = (int)$r['http_status'] === 0 ? '#0ea5e9' : ((int)$r['http_status'] >= 500 ? '#ef4444' : '#f59e0b');
        $unackBadge = (int)$r['unack'] > 0
            ? "<span style='background:#ef4444;color:#fff;font-size:0.7em;padding:1px 5px;border-radius:3px;margin-left:4px;'>{$r['unack']} unack</span>"
            : '';
        $route   = htmlspecialchars((string)($r['route'] ?? ''));
        $snippet = htmlspecialchars(substr((string)($r['snippet'] ?? ''), 0, 100));
        $bRowsHtml .= "<tr>"
            . "<td style='padding:5px 8px;border-bottom:1px solid #f1f5f9;font-weight:700;color:{$color};'>{$label}</td>"
            . "<td style='padding:5px 8px;border-bottom:1px solid #f1f5f9;font-family:monospace;font-size:0.82em;color:#64748b;'>{$route}</td>"
            . "<td style='padding:5px 8px;border-bottom:1px solid #f1f5f9;font-size:0.85em;'>{$snippet}{$unackBadge}</td>"
            . "<td style='padding:5px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;'>{$r['hits']}</td>"
            . "<td style='padding:5px 8px;border-bottom:1px solid #f1f5f9;color:#94a3b8;font-size:0.78em;white-space:nowrap;'>" . substr((string)($r['last_seen'] ?? ''), 0, 16) . "</td>"
            . "</tr>";
        $bRowsText .= "  [{$label}] {$r['route']} — " . substr((string)($r['snippet'] ?? ''), 0, 80)
            . " | hits={$r['hits']} unack={$r['unack']} last=" . substr((string)($r['last_seen'] ?? ''), 0, 16) . "\n";
    }

    $noBreakdown = $last24h === 0
        ? '<p style="color:#22c55e;font-weight:bold;">No errors recorded in the last 24 hours.</p>'
        : '';

    $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1e293b;max-width:700px;margin:0 auto;padding:20px;">'
        . "<div style='background:#1e293b;color:#fff;padding:14px 20px;border-radius:8px 8px 0 0;'>"
        . "<strong>COGS Daily Error Digest</strong> &mdash; " . htmlspecialchars($timestamp)
        . "</div>"
        . "<div style='border:1px solid #e2e8f0;border-top:none;padding:20px;border-radius:0 0 8px 8px;'>"
        . "<div style='display:inline-block;background:{$statusColor};color:#fff;padding:6px 14px;border-radius:4px;font-weight:bold;margin-bottom:16px;'>{$statusLabel}</div>"
        . "<p>Errors in last 24h: <strong>{$last24h}</strong> &nbsp;|&nbsp; Total unacknowledged: <strong style='color:{$statusColor};'>{$totalUnack}</strong></p>"
        . $noBreakdown;

    if (!empty($breakdown)) {
        $html .= '<h3 style="font-size:0.9rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Error Classes (last 24h)</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:0.88em;">'
            . '<thead><tr>'
            . '<th style="text-align:left;padding:5px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Type</th>'
            . '<th style="text-align:left;padding:5px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Route / Page</th>'
            . '<th style="text-align:left;padding:5px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Error</th>'
            . '<th style="text-align:right;padding:5px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Hits</th>'
            . '<th style="text-align:left;padding:5px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;">Last seen</th>'
            . '</tr></thead>'
            . "<tbody>{$bRowsHtml}</tbody>"
            . '</table>';
    }

    $html .= '<p style="margin-top:20px;">'
        . '<a href="https://cogsaustralia.org/admin/errors.php" style="background:#1e293b;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold;">Open Error Log</a>'
        . '</p>'
        . '<p style="color:#94a3b8;font-size:0.82em;margin-top:16px;">COGS daily error digest. Sent every day at 07:00 AEST.</p>'
        . '</div></body></html>';

    $text  = "COGS DAILY ERROR DIGEST — {$timestamp}\n"
        . str_repeat('=', 60) . "\n"
        . "Errors in last 24h: {$last24h}  |  Unacknowledged: {$totalUnack}\n\n";
    $text .= $last24h === 0
        ? "No errors in the last 24 hours.\n"
        : "Error classes:\n" . $bRowsText;
    $text .= "\nAcknowledge at: https://cogsaustralia.org/admin/errors.php\n";

    smtpSendEmail($to, $subject, $html, $text);
    if ($cc !== $to) {
        try { smtpSendEmail($cc, $subject, $html, $text); } catch (Throwable $e) {}
    }
}

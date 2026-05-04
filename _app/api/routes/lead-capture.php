<?php
declare(strict_types=1);
// Deliberately unauthenticated — cold visitors have no session.
// POST with {email} from /seat/ Page A.
// POST with {email, phone} from /seat/inside/ Page B.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
$email  = strtolower(trim((string)($body['email']    ?? '')));
$phone  = trim((string)($body['phone']               ?? ''));
$source = trim((string)($body['source']              ?? ''));
$page   = trim((string)($body['landing_page']        ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => "That email doesn't look right."]);
    exit;
}
if (strlen($email) > 190) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Email is too long.']);
    exit;
}

if ($phone !== '') {
    if (!preg_match('/^[0-9 +\-()]{6,20}$/', $phone)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "That phone number doesn't look right."]);
        exit;
    }
}

$source = substr(preg_replace('/[^a-z0-9_-]/i', '', $source), 0, 60);
$page   = substr(preg_replace('/[^a-z0-9_-]/i', '', $page),   0, 60);

$salt   = defined('APP_SALT') ? APP_SALT : (string)(getenv('APP_SALT') ?: 'cogs_2026');
$ipRaw  = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
$ipHash = $ipRaw !== '' ? hash('sha256', $salt . $ipRaw) : null;
$uaHash = !empty($_SERVER['HTTP_USER_AGENT']) ? hash('sha256', $salt . $_SERVER['HTTP_USER_AGENT']) : null;

try {
    $db = getDB();

    $stmt = $db->prepare(
        'INSERT INTO lead_captures
           (email, phone, source, landing_page, ip_hash, user_agent_hash)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           phone           = COALESCE(VALUES(phone), phone),
           source          = COALESCE(VALUES(source), source),
           landing_page    = COALESCE(VALUES(landing_page), landing_page),
           ip_hash         = COALESCE(VALUES(ip_hash), ip_hash),
           user_agent_hash = COALESCE(VALUES(user_agent_hash), user_agent_hash),
           updated_at      = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        $email,
        $phone !== '' ? $phone : null,
        $source !== '' ? $source : null,
        $page !== '' ? $page : null,
        $ipHash,
        $uaHash,
    ]);

    // Fetch the lead id explicitly — lastInsertId() unreliable after ON DUPLICATE KEY UPDATE.
    $leadRow = $db->prepare('SELECT id FROM lead_captures WHERE email = ? LIMIT 1');
    $leadRow->execute([$email]);
    $leadId = (int)($leadRow->fetchColumn() ?: 0);

    // Only send confirmation on first capture (fresh insert = rowCount 1).
    if ($stmt->rowCount() === 1 && $leadId > 0) {
        require_once __DIR__ . '/../integrations/mailer.php';
        queueEmail(
            $db,
            'lead_capture',
            $leadId,
            $email,
            'lead_magnet_confirmation',
            'Your free guide - a seat at the table',
            [
                'email'    => $email,
                'guide_url'=> 'https://cogsaustralia.org/seat/inside/',
            ]
        );

        // Instant alert to Thomas — fires only on genuine new leads.
        // Uses smtpSendEmail directly (not the queue) so Thomas is
        // notified in the same request, not delayed by cron.
        try {
            if (mailerEnabled()) {
                $hasPhone  = $phone !== '' ? 'Yes' : 'No';
                $srcLabel  = $source !== '' ? $source : 'direct';
                $pageLabel = $page   !== '' ? $page   : 'unknown';
                $alertTo   = 'ThomasC@cogsaustralia.org';
                $subject   = '[COGS] New lead #' . $leadId . ' — ' . $srcLabel;
                $html = '<p><strong>New lead captured on cogsaustralia.org/seat/</strong></p>'
                    . '<table style="font-family:Arial,sans-serif;font-size:0.9em;border-collapse:collapse;">'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Lead ID</td><td><strong>#' . $leadId . '</strong></td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Email</td><td>' . htmlspecialchars(substr($email, 0, 3)) . '***@' . htmlspecialchars(explode('@', $email)[1] ?? '') . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Phone</td><td>' . $hasPhone . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Source</td><td>' . htmlspecialchars($srcLabel) . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Page</td><td>' . htmlspecialchars($pageLabel) . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Time</td><td>' . date('Y-m-d H:i:s T') . '</td></tr>'
                    . '</table>'
                    . '<p style="margin-top:16px;"><a href="https://cogsaustralia.org/admin/monitor.php" style="background:#1e293b;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;font-weight:bold;">View all leads</a></p>';
                $text = "New lead captured on cogsaustralia.org/seat/\n"
                    . "Lead ID: #" . $leadId . "\n"
                    . "Email: " . substr($email, 0, 3) . "***@" . (explode('@', $email)[1] ?? '') . "\n"
                    . "Phone: " . $hasPhone . "\n"
                    . "Source: " . $srcLabel . "\n"
                    . "Page: " . $pageLabel . "\n"
                    . "Time: " . date('Y-m-d H:i:s T') . "\n"
                    . "View leads: https://cogsaustralia.org/admin/monitor.php";
                smtpSendEmail($alertTo, $subject, $html, $text);
            }
        } catch (Throwable $alertEx) {
            // Silent fail — lead is already saved. Alert failure must never
            // affect the lead capture response or the visitor experience.
            error_log('[lead-capture alert] ' . $alertEx->getMessage());
        }
    }

    echo json_encode(['success' => true, 'data' => ['captured' => true]]);
} catch (Throwable $e) {
    error_log('[lead-capture] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save. Please try again.']);
}
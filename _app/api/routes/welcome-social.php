<?php
declare(strict_types=1);
// Public — no auth required. Returns social proof for /welcome/ page.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../services/SimpleCache.php';
$cache    = new SimpleCache('/tmp/cogs_cache');
$cacheKey = 'welcome_social_proof_v1';
$cached   = $cache->get($cacheKey);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

try {
    $db = getDB();

    // ── Approved voice quotes (up to 5, text submissions only) ───────────────
    $quotes = $db->query(
        "SELECT
            COALESCE(mvs.display_name_first, m.first_name) AS display_name,
            UPPER(COALESCE(mvs.display_state, m.state_code, ''))  AS display_state,
            mvs.text_content
         FROM member_voice_submissions mvs
         JOIN partners p ON p.id    = mvs.partner_id
         JOIN members  m ON m.id    = p.member_id
         WHERE mvs.compliance_status = 'cleared_for_use'
           AND mvs.submission_type   = 'text'
           AND mvs.text_content      IS NOT NULL
           AND mvs.withdrawn_at      IS NULL
         ORDER BY mvs.compliance_reviewed_at DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    // ── Recent joiners — last 5 within 48 h ──────────────────────────────────
    // First name + state only. Privacy posture: no member count, no surname.
    $rows = $db->query(
        "SELECT
            m.first_name AS display_name,
            UPPER(COALESCE(m.state_code, '')) AS display_state,
            m.created_at
         FROM members m
         JOIN snft_memberships sm ON sm.member_id = m.id
         WHERE sm.signup_payment_status IN ('paid','pending')
           AND m.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY m.created_at DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    $now     = time();
    $joiners = [];
    foreach ($rows as $r) {
        $diffSec = $now - strtotime((string)$r['created_at']);
        if ($diffSec < 3600) {
            $ago = max(1, (int)round($diffSec / 60)) . ' min ago';
        } elseif ($diffSec < 86400) {
            $h   = (int)round($diffSec / 3600);
            $ago = $h . ' hour' . ($h !== 1 ? 's' : '') . ' ago';
        } else {
            $ago = 'today';
        }
        $joiners[] = [
            'display_name'  => (string)$r['display_name'],
            'display_state' => (string)$r['display_state'],
            'joined_ago'    => $ago,
        ];
    }

    $result = [
        'success' => true,
        'data'    => [
            'quotes'  => $quotes,
            'joiners' => $joiners,
        ],
    ];
    $cache->set($cacheKey, $result, 300);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('[welcome-social] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load social proof.']);
}
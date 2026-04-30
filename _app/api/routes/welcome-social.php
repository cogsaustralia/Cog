<?php
declare(strict_types=1);
// Public — no auth required. Returns social proof for /welcome/ page.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../services/SimpleCache.php';
$cache    = new SimpleCache('/tmp/cogs_cache');
$cacheKey = 'welcome_social_proof_v3';
$cached   = $cache->get($cacheKey);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

try {
    $db = getDB();

    $rows = $db->query(
        "SELECT
            mvs.id,
            mvs.submission_type,
            mvs.text_content,
            COALESCE(mvs.display_name_first, m.first_name) AS display_name,
            UPPER(COALESCE(mvs.display_state, m.state_code, '')) AS display_state
         FROM member_voice_submissions mvs
         JOIN partners p ON p.id  = mvs.partner_id
         JOIN members  m ON m.id  = p.member_id
         WHERE mvs.compliance_status = 'cleared_for_use'
           AND mvs.withdrawn_at      IS NULL
           AND (
             (mvs.submission_type = 'text'  AND mvs.text_content IS NOT NULL)
             OR mvs.submission_type IN ('audio','video')
           )
         ORDER BY mvs.compliance_reviewed_at DESC
         LIMIT 6"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $quotes = [];
    foreach ($rows as $r) {
        $item = [
            'id'            => (int)$r['id'],
            'type'          => (string)$r['submission_type'],
            'display_name'  => (string)$r['display_name'],
            'display_state' => (string)$r['display_state'],
            'text_content'  => $r['text_content'] ?? null,
        ];
        if (in_array($r['submission_type'], ['audio', 'video'], true)) {
            $item['media_url'] = '/_app/api/welcome-media/' . (int)$r['id'];
        }
        $quotes[] = $item;
    }

    $joinRows = $db->query(
        "SELECT
            CONCAT(
              UPPER(LEFT(COALESCE(m.first_name, '?'), 1)), '.',
              CASE WHEN m.last_name IS NOT NULL AND m.last_name != ''
                   THEN CONCAT(UPPER(LEFT(m.last_name, 1)), '.') ELSE '' END
            ) AS display_name,
            UPPER(COALESCE(m.state_code, '')) AS display_state,
            m.created_at
         FROM members m
         JOIN snft_memberships sm ON sm.member_number = m.member_number
         WHERE sm.signup_payment_status IN ('paid','pending')
           AND m.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY m.created_at DESC
         LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $now     = time();
    $joiners = [];
    foreach ($joinRows as $r) {
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
        'data'    => ['quotes' => $quotes, 'joiners' => $joiners],
    ];
    $cache->set($cacheKey, $result, 300);
    echo json_encode($result);

} catch (\Throwable $e) {
    error_log('[welcome-social] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Could not load social proof.',
        'detail'  => $e->getMessage(),
    ]);
}
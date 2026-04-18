<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $db = getDB();
    $w = "signup_payment_status IN ('paid','pending')";

    // ── Core counts ──
    $members = (int)$db->query("SELECT COUNT(*) FROM snft_memberships WHERE $w")->fetchColumn();

    $businesses = 0;
    try { $businesses = (int)$db->query("SELECT COUNT(*) FROM bnft_memberships")->fetchColumn(); }
    catch (Throwable $e) {}

    // ── Token class breakdown ──
    // Read individual live token columns — NOT tokens_total or reservation_value,
    // which are stale stored columns that P2P transfers do not reliably update.
    // total_cogs = sum of all individual token classes (live).
    // total_value = live token counts × price per class ($4 most; $1 kids S-NFT).
    $tok = $db->query("SELECT
        COALESCE(SUM(reserved_tokens), 0)                                            AS snft,
        COALESCE(SUM(kids_tokens), 0)                                                AS ksnft,
        COALESCE(SUM(investment_tokens), 0)                                          AS asx,
        COALESCE(SUM(donation_tokens), 0)                                            AS donation,
        COALESCE(SUM(pay_it_forward_tokens), 0)                                      AS pif,
        COALESCE(SUM(landholder_tokens), 0)                                          AS landholder,
        COALESCE(SUM(rwa_tokens), 0)                                                 AS rwa,
        COALESCE(SUM(lr_tokens), 0)                                                  AS lr,
        COALESCE(SUM(
            reserved_tokens + investment_tokens + kids_tokens +
            donation_tokens + pay_it_forward_tokens +
            landholder_tokens + rwa_tokens + lr_tokens
        ), 0)                                                                        AS total,
        COALESCE(SUM(
            (reserved_tokens + investment_tokens +
             donation_tokens + pay_it_forward_tokens +
             landholder_tokens + rwa_tokens + lr_tokens) * 4
            + kids_tokens * 1
        ), 0)                                                                        AS total_value
        FROM snft_memberships WHERE $w")->fetch(PDO::FETCH_ASSOC);

    // ── Geographic spread ──
    $stateRows = $db->query("SELECT UPPER(COALESCE(state_code,'')) AS st, COUNT(*) AS cnt
        FROM snft_memberships WHERE $w AND state_code IS NOT NULL AND state_code != ''
        GROUP BY UPPER(state_code) ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);

    $states = [];
    foreach ($stateRows as $r) { $states[$r['st']] = (int)$r['cnt']; }

    $postcodes = (int)$db->query("SELECT COUNT(DISTINCT postcode) FROM snft_memberships WHERE $w AND postcode IS NOT NULL AND postcode != ''")->fetchColumn();
    $suburbs = (int)$db->query("SELECT COUNT(DISTINCT LOWER(suburb)) FROM snft_memberships WHERE $w AND suburb IS NOT NULL AND suburb != ''")->fetchColumn();

    // ── BNFT token totals — P2P transfers move tokens into business wallets ──
    $btok = ['b_invest'=>0,'b_rwa'=>0,'b_reserved'=>0,'b_donation'=>0,'b_pif'=>0,'b_landholder'=>0];
    try {
        $btok = $db->query("SELECT
            COALESCE(SUM(invest_tokens),        0) AS b_invest,
            COALESCE(SUM(rwa_tokens),           0) AS b_rwa,
            COALESCE(SUM(reserved_tokens),      0) AS b_reserved,
            COALESCE(SUM(donation_tokens),      0) AS b_donation,
            COALESCE(SUM(pay_it_forward_tokens),0) AS b_pif,
            COALESCE(SUM(landholder_tokens),    0) AS b_landholder
            FROM bnft_memberships")->fetch(PDO::FETCH_ASSOC) ?: $btok;
    } catch (Throwable $e) {}

    $total_cogs  = (int)$tok['total']
                 + (int)$btok['b_invest']  + (int)$btok['b_rwa']
                 + (int)$btok['b_reserved']+ (int)$btok['b_donation']
                 + (int)$btok['b_pif']     + (int)$btok['b_landholder'];
    $total_value = (float)$tok['total_value']
                 + ((int)$btok['b_invest']  + (int)$btok['b_rwa']
                  + (int)$btok['b_reserved']+ (int)$btok['b_donation']
                  + (int)$btok['b_pif']     + (int)$btok['b_landholder']) * 4.0;

    echo json_encode([
        'success' => true,
        'data' => [
            'founding_members' => $members + $businesses,
            'personal_members' => $members,
            'businesses'       => $businesses,
            'kids_registered'  => (int)$tok['ksnft'],
            'total_cogs'       => $total_cogs,
            'total_value'      => round($total_value, 2),
            'classes' => [
                'snft'       => (int)$tok['snft'],
                'ksnft'      => (int)$tok['ksnft'],
                'asx'        => (int)$tok['asx']       + (int)$btok['b_invest'],
                'donation'   => (int)$tok['donation']   + (int)$btok['b_donation'],
                'pif'        => (int)$tok['pif']        + (int)$btok['b_pif'],
                'landholder' => (int)$tok['landholder'] + (int)$btok['b_landholder'],
                'rwa'        => (int)$tok['rwa']        + (int)$btok['b_rwa'],
                'lr'         => (int)$tok['lr'],
            ],
            'geo' => [
                'states'          => $states,
                'states_count'    => count($states),
                'postcodes_count' => $postcodes,
                'suburbs_count'   => $suburbs,
            ],
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

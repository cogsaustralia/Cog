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

// ── Member-only gate ─────────────────────────────────────────────────────────
// Member counts are not public information. This endpoint is consumed only by
// authenticated surfaces — wallets/member.html, partners/index.html, and
// hubs/mainspring/index.html — which run inside an SNFT member, BNFT business,
// or admin session. Any role is acceptable here; any unauthenticated request
// gets 401 before any DB work or cache read.
//
// Why getAuthPrincipal() and not requireAuth('snft'): the consumers span all
// three roles (member/business/admin) and we want a single non-discriminating
// gate that just checks "is this someone with a valid session". Differentiating
// by role would lock out BNFT users from their own legitimate stats view.
$principal = getAuthPrincipal();
if (!$principal) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

// ── Cache layer (5-minute TTL) ────────────────────────────────────────────────
require_once __DIR__ . '/../services/SimpleCache.php';
$cache     = new SimpleCache('/tmp/cogs_cache');
$cacheKey  = 'community_stats';
$cached    = $cache->get($cacheKey);
if ($cached !== null) {
    echo json_encode($cached);
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
        COALESCE(SUM(community_tokens), 0)                                           AS community,
        COALESCE(SUM(
            reserved_tokens + investment_tokens + kids_tokens +
            donation_tokens + pay_it_forward_tokens +
            landholder_tokens + rwa_tokens + lr_tokens + community_tokens
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
    $btok = ['b_invest'=>0,'b_rwa'=>0,'b_reserved'=>0,'b_donation'=>0,'b_pif'=>0,'b_landholder'=>0,'b_bus_prop'=>0,'b_community'=>0];
    $bnft_count = 0;
    try {
        $btok = $db->query("SELECT
            COALESCE(SUM(invest_tokens),        0) AS b_invest,
            COALESCE(SUM(rwa_tokens),           0) AS b_rwa,
            COALESCE(SUM(reserved_tokens),      0) AS b_reserved,
            COALESCE(SUM(donation_tokens),      0) AS b_donation,
            COALESCE(SUM(pay_it_forward_tokens),0) AS b_pif,
            COALESCE(SUM(landholder_tokens),    0) AS b_landholder,
            COALESCE(SUM(bus_prop_tokens),      0) AS b_bus_prop,
            COALESCE(SUM(community_tokens),     0) AS b_community
            FROM bnft_memberships")->fetch(PDO::FETCH_ASSOC) ?: $btok;
        $bnft_count = (int)$db->query("SELECT COUNT(*) FROM bnft_memberships")->fetchColumn();
    } catch (Throwable $e) {}

    $total_cogs  = (int)$tok['total']
                 + (int)$btok['b_invest']  + (int)$btok['b_rwa']
                 + (int)$btok['b_reserved']+ (int)$btok['b_donation']
                 + (int)$btok['b_pif']     + (int)$btok['b_landholder'];
    $total_value = (float)$tok['total_value']
                 + ((int)$btok['b_invest']  + (int)$btok['b_rwa']
                  + (int)$btok['b_reserved']+ (int)$btok['b_donation']
                  + (int)$btok['b_pif']     + (int)$btok['b_landholder']) * 4.0;

    // ── JV Asset Pool ──────────────────────────────────────────────────────────
    // Component 1: ASX book value — total_cost_cents is DECIMAL(14,4) cents
    $asx_book_cents = 0;
    try {
        $s = $db->query("SELECT COALESCE(SUM(total_cost_cents),0) FROM asx_holdings");
        $asx_book_cents = (float)$s->fetchColumn();
    } catch (Throwable $e) {}

    // Component 2: Sub-Trust A Partners Asset Pool cash balance
    // Live read from ledger_entries via STA-PARTNERS-POOL account (id=3)
    $sta_cash_cents = 0;
    try {
        $s = $db->query(
            "SELECT COALESCE(SUM(
                CASE WHEN le.entry_type = 'debit' THEN le.amount_cents
                     ELSE -le.amount_cents END
             ), 0)
             FROM ledger_entries le
             JOIN stewardship_accounts sa ON sa.id = le.stewardship_account_id
             WHERE sa.account_key = 'STA-PARTNERS-POOL'"
        );
        $sta_cash_cents = (float)$s->fetchColumn();
    } catch (Throwable $e) {}

    $founding_total = $members + $businesses;

    // Component 3: RWA verified valuations — from v_foundation_rwa_assets_live
    // Reads the most-recent verified_valuation_cents per active RWA asset (view handles this)
    $rwa_val_cents = 0;
    try {
        $s = $db->query("SELECT COALESCE(SUM(verified_valuation_cents), 0) FROM v_foundation_rwa_assets_live");
        $rwa_val_cents = (float)$s->fetchColumn();
    } catch (Throwable $e) {}

    // Component 4: IP & infrastructure — Trustee-adopted valuation formula
    // V = $475,000 + ($250 × N)  [Valuation Report v1.0, §2]
    // Component A (baseline IP replacement): $475,000
    // Component B+C (per-active-member multiplier): $250 per member
    $ip_infra_cents = 47500000 + (25000 * $founding_total); // cents

    $asset_pool_cents = $asx_book_cents + $sta_cash_cents + $rwa_val_cents + $ip_infra_cents;
    $per_member_cents = $founding_total > 0
        ? round($asset_pool_cents / $founding_total, 0)
        : 0;

    // Reservation value per member (beta-phase option values ÷ member count)
    // total_value is in dollars; convert to cents for consistency
    $reservation_per_member_cents = $founding_total > 0
        ? round(($total_value * 100) / $founding_total, 0)
        : 0;

    // Pending members — temp placeholder: founding_members × 3
    // TODO: replace with actual pipeline/pending status query when field confirmed
    $pending_members = ($members + $businesses) * 3;

    $result = [
        'success' => true,
        'data' => [
            'founding_members' => $members + $businesses,
            'pending_members'  => $pending_members,
            'personal_members' => $members,
            'businesses'       => $businesses,
            'kids_registered'  => (int)$tok['ksnft'],
            'total_cogs'       => $total_cogs,
            'total_value'      => round($total_value, 2),
            'asset_pool_cents'                  => $asset_pool_cents,
            'asset_pool_per_member_cents'       => $per_member_cents,
            'reservation_per_member_cents'      => $reservation_per_member_cents,
            'asset_pool_components'             => [
                'asx_book_cents'   => $asx_book_cents,
                'rwa_val_cents'    => $rwa_val_cents,
                'sta_cash_cents'   => $sta_cash_cents,
                'ip_infra_cents'   => $ip_infra_cents,
            ],
            'classes' => [
                'snft'       => (int)$tok['snft'],
                'ksnft'      => (int)$tok['ksnft'],
                'bnft'       => $bnft_count,
                'asx'        => (int)$tok['asx']       + (int)$btok['b_invest'],
                'donation'   => (int)$tok['donation']   + (int)$btok['b_donation'],
                'pif'        => (int)$tok['pif']        + (int)$btok['b_pif'],
                'landholder' => (int)$tok['landholder'] + (int)$btok['b_landholder'],
                'rwa'        => (int)$tok['rwa']        + (int)$btok['b_rwa'],
                'lr'         => (int)$tok['lr'],
                'community'  => (int)$tok['community']  + (int)$btok['b_community'],
                'bp'         => (int)$btok['b_bus_prop'],
            ],
            'geo' => [
                'states'          => $states,
                'states_count'    => count($states),
                'postcodes_count' => $postcodes,
                'suburbs_count'   => $suburbs,
            ],
        ]
    ];
    $cache->set($cacheKey, $result, 300); // cache for 5 minutes
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

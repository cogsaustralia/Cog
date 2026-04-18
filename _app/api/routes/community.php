<?php
declare(strict_types=1);

$action = trim((string)($id ?? ''), '/');
if ($action === 'members') {
    communityMembers();
}

requireMethod('GET');
$db = getDB();

$snft = (int)($db->query('SELECT COUNT(*) FROM snft_memberships')->fetchColumn() ?: 0);
$bnft = (int)($db->query('SELECT COUNT(*) FROM bnft_memberships')->fetchColumn() ?: 0);
$wallets = (int)($db->query('SELECT COUNT(*) FROM vault_wallets WHERE wallet_status IN ("pending_setup", "active")')->fetchColumn() ?: 0);
$partnersTotal = 0; $businessPartners = 0;
if (api_table_exists($db, 'partners')) {
    try { $partnersTotal = (int)($db->query("SELECT COUNT(*) FROM partners WHERE status IN ('active','pending')")->fetchColumn() ?: 0); } catch (Throwable $e) {}
    try { $businessPartners = (int)($db->query("SELECT COUNT(*) FROM partners WHERE partner_kind = 'business' AND status IN ('active','pending')")->fetchColumn() ?: 0); } catch (Throwable $e) {}
}

$snftTokenRow = $db->query('SELECT 
    COALESCE(SUM(reserved_tokens), 0)        AS reserved_tokens,
    COALESCE(SUM(investment_tokens), 0)      AS investment_tokens,
    COALESCE(SUM(donation_tokens), 0)        AS donation_tokens,
    COALESCE(SUM(pay_it_forward_tokens), 0)  AS pay_it_forward_tokens,
    COALESCE(SUM(kids_tokens), 0)            AS kids_tokens,
    COALESCE(SUM(landholder_hectares), 0)    AS landholder_hectares,
    COALESCE(SUM(landholder_tokens), 0)      AS landholder_tokens,
    COALESCE(SUM(rwa_tokens), 0)             AS rwa_tokens,
    COALESCE(SUM(lr_tokens), 0)              AS lr_tokens,
    COALESCE(SUM(
        reserved_tokens + investment_tokens + kids_tokens +
        donation_tokens + pay_it_forward_tokens +
        landholder_tokens + rwa_tokens + lr_tokens
    ), 0)                                    AS total_tokens,
    COALESCE(SUM(
        (reserved_tokens + investment_tokens +
         donation_tokens + pay_it_forward_tokens +
         landholder_tokens + rwa_tokens + lr_tokens) * 4
        + kids_tokens * 1
    ), 0)                                    AS total_value
  FROM snft_memberships')->fetch() ?: [];

$bnftTokenRow = $db->query('SELECT 
    COALESCE(SUM(reserved_tokens), 0)        AS reserved_tokens,
    COALESCE(SUM(invest_tokens), 0)          AS investment_tokens,
    COALESCE(SUM(rwa_tokens), 0)             AS rwa_tokens,
    COALESCE(SUM(donation_tokens), 0)        AS donation_tokens,
    COALESCE(SUM(pay_it_forward_tokens), 0)  AS pay_it_forward_tokens,
    COALESCE(SUM(landholder_hectares), 0)    AS landholder_hectares,
    COALESCE(SUM(landholder_tokens), 0)      AS landholder_tokens
  FROM bnft_memberships')->fetch() ?: [];

$snftMix = tokenBreakdownFromRow($snftTokenRow, 'snft');
$bnftMix = tokenBreakdownFromRow($bnftTokenRow, 'bnft');
$allMix = [
    'reserved_tokens' => $snftMix['reserved_tokens'] + $bnftMix['reserved_tokens'],
    'investment_tokens' => $snftMix['investment_tokens'] + $bnftMix['investment_tokens'],
    'donation_tokens' => $snftMix['donation_tokens'] + $bnftMix['donation_tokens'],
    'pay_it_forward_tokens' => $snftMix['pay_it_forward_tokens'] + $bnftMix['pay_it_forward_tokens'],
    'kids_tokens' => $snftMix['kids_tokens'],
    'landholder_hectares' => $snftMix['landholder_hectares'] + $bnftMix['landholder_hectares'],
    'landholder_tokens' => $snftMix['landholder_tokens'] + $bnftMix['landholder_tokens'],
    'total_tokens' => $snftMix['total_tokens'] + $bnftMix['total_tokens'],
];

$snftValue = (float)($snftTokenRow['total_value'] ?? 0);
$bnftValue = (float)($bnftTokenRow['total_value'] ?? 0);
$totalReservation = $snftValue + $bnftValue;

$events = $db->query('SELECT subject_type, subject_ref, event_type, created_at FROM wallet_events ORDER BY id DESC LIMIT 10')->fetchAll();
$announcements = $db->query('SELECT id, audience, title, body, created_at FROM announcements ORDER BY id DESC LIMIT 6')->fetchAll();


$memberFeed = $db->query('
    SELECT rt.created_at,
           CASE WHEN rt.subject_type = "snft_member" THEN "SNFT" ELSE "BNFT" END AS class_label,
           rt.subject_ref AS member_number,
           COALESCE(sm.full_name, bm.legal_name, rt.subject_ref) AS display_name,
           rt.reserved_after AS reserved_tokens,
           rt.investment_after AS investment_tokens,
           rt.donation_after AS donation_tokens,
           rt.pay_it_forward_after AS pay_it_forward_tokens,
           rt.kids_after AS kids_tokens,
           rt.landholder_hectares_after AS landholder_hectares,
           rt.landholder_after AS landholder_tokens,
           rt.total_units_after AS token_units,
           rt.total_value_after AS total_value
      FROM reservation_transactions rt
      LEFT JOIN snft_memberships sm
        ON rt.subject_type = "snft_member" AND sm.id = rt.subject_id
      LEFT JOIN bnft_memberships bm
        ON rt.subject_type = "bnft_business" AND bm.id = rt.subject_id
      ORDER BY rt.id DESC
      LIMIT 12
')->fetchAll();

foreach ($memberFeed as &$item) {
    $item['token_mix_summary'] = formatTokenBreakdownNote([
        'reserved_tokens' => (int)($item['reserved_tokens'] ?? 0),
        'investment_tokens' => (int)($item['investment_tokens'] ?? 0),
        'donation_tokens' => (int)($item['donation_tokens'] ?? 0),
        'pay_it_forward_tokens' => (int)($item['pay_it_forward_tokens'] ?? 0),
        'kids_tokens' => (int)($item['kids_tokens'] ?? 0),
        'landholder_hectares' => (float)($item['landholder_hectares'] ?? 0),
        'landholder_tokens' => (int)($item['landholder_tokens'] ?? 0),
    ]);
}
unset($item);

$proposals = [];
try {
    $proposalRows = $db->query(
        "SELECT vp.id, vp.proposal_key, vp.audience_scope AS audience, vp.title, vp.summary, vp.status, vp.starts_at, vp.closes_at, pr.status AS bridge_status, pr.id AS bridge_id
         FROM vote_proposals vp
         LEFT JOIN proposal_register pr ON pr.proposal_key = vp.proposal_key
         WHERE vp.status IN ('open','closed','draft')
         ORDER BY vp.id DESC LIMIT 6"
    )->fetchAll();
    if ($proposalRows) {
        $ids = array_map(static fn(array $item): int => (int)$item['id'], $proposalRows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tallyStmt = $db->prepare(
            'SELECT proposal_id, response_value AS choice_value, COUNT(*) AS votes
             FROM vote_proposal_responses
             WHERE proposal_id IN (' . $placeholders . ')
             GROUP BY proposal_id, response_value'
        );
        $tallyStmt->execute($ids);
        $tallies = [];
        foreach ($tallyStmt->fetchAll() as $row) {
            $tallies[(int)$row['proposal_id']][(string)$row['choice_value']] = (int)$row['votes'];
        }
        foreach ($proposalRows as $item) {
            $options = ['yes', 'maybe', 'no'];
            $counts = [];
            $total = 0;
            foreach ($options as $option) {
                $votes = (int)($tallies[(int)$item['id']][$option] ?? 0);
                $counts[] = ['label' => $option, 'votes' => $votes];
                $total += $votes;
            }
            $item['options'] = $options;
            $item['tally'] = $counts;
            $item['total_votes'] = $total;
            $proposals[] = $item;
        }
    }
} catch (Throwable $propErr) {
    error_log('[community] proposals load failed: ' . $propErr->getMessage());
}

apiSuccess([
    'snft_members' => $snft,
    'bnft_businesses' => $bnft,
    'partners_total' => $partnersTotal ?: ($snft + $bnft),
    'business_partners' => $businessPartners ?: $bnft,
    'active_wallets' => $wallets,
    'total_reservation_value' => $totalReservation,
    'snft_tokens_total' => $snftMix['total_tokens'],
    'bnft_tokens_total' => $bnftMix['total_tokens'],
    'all_class_tokens_total' => $allMix['total_tokens'],
    'snft_value_total' => $snftValue,
    'bnft_value_total' => $bnftValue,
    'token_mix' => [
        'snft' => $snftMix,
        'bnft' => $bnftMix,
        'all' => $allMix,
    ],
    'recent_member_numbers' => $memberFeed,
    'recent_events' => $events,
    'announcements' => $announcements,
    'proposals' => $proposals,
    'binding_status' => 'beta_non_binding',
    'intent_status' => 'proposed_only',
    'entitlement_status' => 'inactive',
    'refreshed_at' => nowUtc(),
]);


function communityMembers(): void {
    requireMethod('GET');
    $principal = requireAnyAuth(['snft', 'bnft']);
    $db = getDB();

    $snft = (int)($db->query('SELECT COUNT(*) FROM snft_memberships')->fetchColumn() ?: 0);
    $bnft = (int)($db->query('SELECT COUNT(*) FROM bnft_memberships')->fetchColumn() ?: 0);
    $wallets = (int)($db->query('SELECT COUNT(*) FROM vault_wallets WHERE wallet_status IN ("pending_setup", "active")')->fetchColumn() ?: 0);

    $snftTokenRow = $db->query('SELECT 
        COALESCE(SUM(reserved_tokens), 0)        AS reserved_tokens,
        COALESCE(SUM(investment_tokens), 0)      AS investment_tokens,
        COALESCE(SUM(donation_tokens), 0)        AS donation_tokens,
        COALESCE(SUM(pay_it_forward_tokens), 0)  AS pay_it_forward_tokens,
        COALESCE(SUM(kids_tokens), 0)            AS kids_tokens,
        COALESCE(SUM(landholder_hectares), 0)    AS landholder_hectares,
        COALESCE(SUM(landholder_tokens), 0)      AS landholder_tokens,
        COALESCE(SUM(rwa_tokens), 0)             AS rwa_tokens,
        COALESCE(SUM(lr_tokens), 0)              AS lr_tokens,
        COALESCE(SUM(
            reserved_tokens + investment_tokens + kids_tokens +
            donation_tokens + pay_it_forward_tokens +
            landholder_tokens + rwa_tokens + lr_tokens
        ), 0)                                    AS total_tokens,
        COALESCE(SUM(
            (reserved_tokens + investment_tokens +
             donation_tokens + pay_it_forward_tokens +
             landholder_tokens + rwa_tokens + lr_tokens) * 4
            + kids_tokens * 1
        ), 0)                                    AS total_value
      FROM snft_memberships')->fetch() ?: [];

    $bnftTokenRow = $db->query('SELECT 
        COALESCE(SUM(reserved_tokens), 0)        AS reserved_tokens,
        COALESCE(SUM(invest_tokens), 0)          AS investment_tokens,
        COALESCE(SUM(rwa_tokens), 0)             AS rwa_tokens,
        COALESCE(SUM(donation_tokens), 0)        AS donation_tokens,
        COALESCE(SUM(pay_it_forward_tokens), 0)  AS pay_it_forward_tokens,
        COALESCE(SUM(landholder_hectares), 0)    AS landholder_hectares,
        COALESCE(SUM(landholder_tokens), 0)      AS landholder_tokens
      FROM bnft_memberships')->fetch() ?: [];

    $snftMix = tokenBreakdownFromRow($snftTokenRow, 'snft');
    $bnftMix = tokenBreakdownFromRow($bnftTokenRow, 'bnft');
    $allMix = [
        'reserved_tokens' => $snftMix['reserved_tokens'] + $bnftMix['reserved_tokens'],
        'investment_tokens' => $snftMix['investment_tokens'] + $bnftMix['investment_tokens'],
        'donation_tokens' => $snftMix['donation_tokens'] + $bnftMix['donation_tokens'],
        'pay_it_forward_tokens' => $snftMix['pay_it_forward_tokens'] + $bnftMix['pay_it_forward_tokens'],
        'kids_tokens' => $snftMix['kids_tokens'],
        'landholder_hectares' => $snftMix['landholder_hectares'] + $bnftMix['landholder_hectares'],
        'landholder_tokens' => $snftMix['landholder_tokens'] + $bnftMix['landholder_tokens'],
        'total_tokens' => $snftMix['total_tokens'] + $bnftMix['total_tokens'],
    ];

    $snftValue = (float)($snftTokenRow['total_value'] ?? 0);
    $bnftValue = (float)($bnftTokenRow['total_value'] ?? 0);
    $totalReservation = $snftValue + $bnftValue;

    $events = $db->query('SELECT subject_type, subject_ref, event_type, created_at FROM wallet_events ORDER BY id DESC LIMIT 20')->fetchAll();
    $announcements = $db->query('SELECT id, audience, title, body, created_at FROM announcements ORDER BY id DESC LIMIT 12')->fetchAll();

    $memberFeed = $db->query('
        SELECT rt.created_at,
               CASE WHEN rt.subject_type = "snft_member" THEN "SNFT" ELSE "BNFT" END AS class_label,
               rt.subject_ref AS member_number,
               COALESCE(sm.full_name, bm.legal_name, rt.subject_ref) AS display_name,
               rt.reserved_after AS reserved_tokens,
               rt.investment_after AS investment_tokens,
               rt.donation_after AS donation_tokens,
               rt.pay_it_forward_after AS pay_it_forward_tokens,
               rt.kids_after AS kids_tokens,
               rt.landholder_hectares_after AS landholder_hectares,
               rt.landholder_after AS landholder_tokens,
               rt.total_units_after AS token_units,
               rt.total_value_after AS total_value
          FROM reservation_transactions rt
          LEFT JOIN snft_memberships sm
            ON rt.subject_type = "snft_member" AND sm.id = rt.subject_id
          LEFT JOIN bnft_memberships bm
            ON rt.subject_type = "bnft_business" AND bm.id = rt.subject_id
          ORDER BY rt.id DESC
          LIMIT 24
    ')->fetchAll();

    foreach ($memberFeed as &$item) {
        $item['token_mix_summary'] = formatTokenBreakdownNote([
            'reserved_tokens' => (int)($item['reserved_tokens'] ?? 0),
            'investment_tokens' => (int)($item['investment_tokens'] ?? 0),
            'donation_tokens' => (int)($item['donation_tokens'] ?? 0),
            'pay_it_forward_tokens' => (int)($item['pay_it_forward_tokens'] ?? 0),
            'kids_tokens' => (int)($item['kids_tokens'] ?? 0),
            'landholder_hectares' => (float)($item['landholder_hectares'] ?? 0),
            'landholder_tokens' => (int)($item['landholder_tokens'] ?? 0),
        ]);
    }
    unset($item);

    $proposals = [];
    try {
        $proposalRows = $db->query(
            "SELECT vp.id, vp.proposal_key, vp.audience_scope AS audience, vp.title, vp.summary, vp.status, vp.starts_at, vp.closes_at, pr.status AS bridge_status, pr.id AS bridge_id
             FROM vote_proposals vp
             LEFT JOIN proposal_register pr ON pr.proposal_key = vp.proposal_key
             WHERE vp.status IN ('open','closed','draft')
             ORDER BY vp.id DESC LIMIT 12"
        )->fetchAll();
        if ($proposalRows) {
            $ids = array_map(static fn(array $item): int => (int)$item['id'], $proposalRows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $tallyStmt = $db->prepare(
                'SELECT proposal_id, response_value AS choice_value, COUNT(*) AS votes
                 FROM vote_proposal_responses
                 WHERE proposal_id IN (' . $placeholders . ')
                 GROUP BY proposal_id, response_value'
            );
            $tallyStmt->execute($ids);
            $tallies = [];
            foreach ($tallyStmt->fetchAll() as $row) {
                $tallies[(int)$row['proposal_id']][(string)$row['choice_value']] = (int)$row['votes'];
            }
            foreach ($proposalRows as $item) {
                $options = ['yes', 'maybe', 'no'];
                $counts = [];
                $total = 0;
                foreach ($options as $option) {
                    $votes = (int)($tallies[(int)$item['id']][$option] ?? 0);
                    $counts[] = ['label' => $option, 'votes' => $votes];
                    $total += $votes;
                }
                $item['options'] = $options;
                $item['tally'] = $counts;
                $item['total_votes'] = $total;
                $proposals[] = $item;
            }
        }
    } catch (Throwable $propErr) {
        error_log('[community/members] proposals load failed: ' . $propErr->getMessage());
    }

    apiSuccess([
        'access_scope' => 'members_only',
        'viewer_role' => (string)$principal['user_type'],
        'viewer_ref' => (string)$principal['subject_ref'],
        'snft_members' => $snft,
        'bnft_businesses' => $bnft,
        'active_wallets' => $wallets,
        'total_reservation_value' => $totalReservation,
        'snft_tokens_total' => $snftMix['total_tokens'],
        'bnft_tokens_total' => $bnftMix['total_tokens'],
        'all_class_tokens_total' => $allMix['total_tokens'],
        'snft_value_total' => $snftValue,
        'bnft_value_total' => $bnftValue,
        'token_mix' => [
            'snft' => $snftMix,
            'bnft' => $bnftMix,
            'all' => $allMix,
        ],
        'recent_member_numbers' => $memberFeed,
        'recent_events' => $events,
        'announcements' => $announcements,
        'proposals' => $proposals,
        'binding_status' => 'members_only_beta_feed',
        'intent_status' => 'proposed_only',
        'entitlement_status' => 'inactive',
        'refreshed_at' => nowUtc(),
    ]);
}

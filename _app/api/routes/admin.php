<?php
declare(strict_types=1);

$action = trim((string)($id ?? 'summary'), '/');
if ($action === '' || $action === 'summary') {
    adminSummary();
}
if ($action === 'crm-sync') {
    adminCrmSync();
}
if ($action === 'news-push') {
    adminNewsPush();
}
if ($action === 'news-feed') {
    adminNewsFeed();
}
if ($action === 'vote-push') {
    adminVotePush();
}
if ($action === 'votes') {
    adminVotes();
}
if (preg_match('#^vote-close/(\d+)$#', $action, $matches)) {
    adminVoteClose((int)$matches[1]);
}
if ($action === 'disputes') {
    adminDisputes();
}
if (preg_match('#^dispute-status/(\d+)$#', $action, $matches)) {
    adminDisputeStatus((int)$matches[1]);
}
apiError('Unknown admin route', 404);

function adminSummary(): void {
    requireMethod('GET');
    $admin = requireAdminRole();
    $db = getDB();

    $snft = (int)($db->query('SELECT COUNT(*) FROM snft_memberships')->fetchColumn() ?: 0);
    $bnft = (int)($db->query('SELECT COUNT(*) FROM bnft_memberships')->fetchColumn() ?: 0);
    $partnersTotal = api_table_exists($db, 'partners') ? (int)($db->query("SELECT COUNT(*) FROM partners WHERE status IN ('active','pending')")->fetchColumn() ?: 0) : ($snft + $bnft);
    $wallets = (int)($db->query('SELECT COUNT(*) FROM vault_wallets WHERE wallet_status IN ("pending_setup", "active")')->fetchColumn() ?: 0);
    $pending = (int)($db->query('SELECT COUNT(*) FROM crm_sync_queue WHERE status IN ("pending","failed")')->fetchColumn() ?: 0);
    $announcements = (int)($db->query('SELECT COUNT(*) FROM announcements')->fetchColumn() ?: 0);
    $openVotes = (int)($db->query('SELECT COUNT(*) FROM vote_proposals WHERE status = "open"')->fetchColumn() ?: 0);
    $bridgeProposals = api_table_exists($db, 'proposal_register') ? (int)($db->query("SELECT COUNT(*) FROM proposal_register WHERE status IN ('submitted','sponsored','open')")->fetchColumn() ?: 0) : 0;
    $communityPolls = api_table_exists($db, 'community_polls') ? (int)($db->query("SELECT COUNT(*) FROM community_polls WHERE status IN ('deliberation','open','closed','declared')")->fetchColumn() ?: 0) : 0;
    // vote_disputes table may not exist yet — guard it
    $openDisputes = 0;
    try {
        $openDisputes = (int)($db->query('SELECT COUNT(*) FROM vote_disputes WHERE status = "open"')->fetchColumn() ?: 0);
    } catch (Throwable $e) {}
    $queue = $db->query('SELECT sync_entity, entity_id, status, attempts, last_error FROM crm_sync_queue ORDER BY id DESC LIMIT 20')->fetchAll();

    $snftMixRow = $db->query('SELECT COALESCE(SUM(reserved_tokens),0) AS reserved_tokens, COALESCE(SUM(investment_tokens),0) AS investment_tokens, COALESCE(SUM(donation_tokens),0) AS donation_tokens, COALESCE(SUM(pay_it_forward_tokens),0) AS pay_it_forward_tokens, COALESCE(SUM(kids_tokens),0) AS kids_tokens, COALESCE(SUM(landholder_hectares),0) AS landholder_hectares, COALESCE(SUM(landholder_tokens),0) AS landholder_tokens, COALESCE(SUM(tokens_total),0) AS total_tokens FROM snft_memberships')->fetch() ?: [];
    $bnftMixRow = $db->query('SELECT COALESCE(SUM(reserved_tokens),0) AS reserved_tokens, COALESCE(SUM(invest_tokens),0) AS investment_tokens, COALESCE(SUM(donation_tokens),0) AS donation_tokens, COALESCE(SUM(pay_it_forward_tokens),0) AS pay_it_forward_tokens, COALESCE(SUM(landholder_hectares),0) AS landholder_hectares, COALESCE(SUM(landholder_tokens),0) AS landholder_tokens FROM bnft_memberships')->fetch() ?: [];
    $snftMix = tokenBreakdownFromRow($snftMixRow, 'snft');
    $bnftMix = tokenBreakdownFromRow($bnftMixRow, 'bnft');
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

    $news = $db->query('SELECT id, audience, title, body, created_by, created_at FROM announcements ORDER BY id DESC LIMIT 20')->fetchAll();
    $reservationUpdates = $db->query('SELECT subject_type, subject_ref, action_type, source_context, total_units_before AS previous_units, total_units_after AS new_units, total_units_delta, total_value_before AS previous_value, total_value_after AS new_value, total_value_delta, investment_delta, donation_delta, pay_it_forward_delta, kids_delta, landholder_delta, landholder_hectares_delta, note, created_at FROM reservation_transactions ORDER BY id DESC LIMIT 20')->fetchAll();

    apiSuccess([
        'admin_profile' => [
            'display_name' => $admin['display_name'],
            'username' => $admin['username'],
            'role_name' => $admin['role_name'],
        ],
        'snft_members' => $snft,
        'bnft_businesses' => $bnft,
        'partners_total' => $partnersTotal,
        'active_wallets' => $wallets,
        'crm_pending' => $pending,
        'announcements_total' => $announcements,
        'open_votes' => $openVotes,
        'bridge_proposals' => $bridgeProposals,
        'community_polls_total' => $communityPolls,
        'open_disputes' => $openDisputes,
        'crm_queue' => $queue,
        'token_mix' => [
            'snft' => $snftMix,
            'bnft' => $bnftMix,
            'all' => $allMix,
        ],
        'news' => $news,
        'reservation_updates' => $reservationUpdates,
        'votes' => (static function() use ($db): array { try { return fetchVoteDashboard($db, 20); } catch (Throwable $e) { return []; } })(),
        'disputes' => (static function() use ($db): array { try { return fetchDisputeDashboard($db, 20); } catch (Throwable $e) { return []; } })(),
        'crm_provider' => CRM_PROVIDER,
        'binding_status' => 'beta_non_binding',
        'phase_c_prepared' => true,
        'refreshed_at' => nowUtc(),
    ]);
}

function adminCrmSync(): void {
    requireMethod('POST');
    requireAdminRole(['superadmin', 'operations_admin']);
    $db = getDB();
    apiSuccess(processCrmQueue($db, 25));
}

function adminNewsPush(): void {
    requireMethod('POST');
    $admin = requireAdminRole(['superadmin', 'content_admin', 'operations_admin']);
    $db = getDB();
    $body = jsonBody();

    $title = sanitize($body['title'] ?? '');
    $content = trim((string)($body['body'] ?? ''));
    $audience = validateAudience((string)($body['audience'] ?? 'all'));
    $createdBy = sanitize($body['created_by'] ?? '') ?: (string)$admin['display_name'];

    if ($title === '' || $content === '') {
        apiError('Title and announcement body are required.');
    }

    $stmt = $db->prepare('INSERT INTO announcements (audience, title, body, created_by) VALUES (?,?,?,?)');
    $stmt->execute([$audience, $title, $content, $createdBy]);
    $announcementId = (int)$db->lastInsertId();
    recordAudienceWalletEvent($db, $audience, 'news_push', 'Admin published: ' . $title);

    apiSuccess([
        'announcement_id' => $announcementId,
        'title' => $title,
        'audience' => $audience,
        'created_at' => nowUtc(),
    ], 201);
}

function adminNewsFeed(): void {
    requireMethod('GET');
    requireAdminRole();
    $db = getDB();
    $stmt = $db->query('SELECT id, audience, title, body, created_by, created_at FROM announcements ORDER BY id DESC LIMIT 50');
    apiSuccess(['items' => $stmt->fetchAll(), 'refreshed_at' => nowUtc()]);
}

function adminVotePush(): void {
    requireMethod('POST');
    $admin = requireAdminRole(['superadmin', 'governance_admin']);
    $db = getDB();
    $body = jsonBody();

    $title = sanitize($body['title'] ?? '');
    $summary = trim((string)($body['summary'] ?? ''));
    $content = trim((string)($body['body'] ?? ''));
    $createdBy = sanitize($body['created_by'] ?? '') ?: (string)$admin['display_name'];
    $audience = validateAudience((string)($body['audience'] ?? 'snft'));
    $options = $body['options'] ?? [];
    if (is_string($options)) {
        $options = preg_split('/\r\n|\r|\n/', $options) ?: [];
    }
    $options = cleanOptions(is_array($options) ? $options : []);
    if ($title === '' || count($options) < 2) {
        apiError('Vote title and at least two options are required.');
    }

    $closesAt = sanitize($body['closes_at'] ?? '');
    if ($closesAt !== '') {
        $timestamp = strtotime($closesAt);
        if ($timestamp === false) {
            apiError('Close time must be a valid datetime.');
        }
        $closesAt = gmdate('Y-m-d H:i:s', $timestamp);
    } else {
        $closesAt = null;
    }
    // Map legacy 'audience' to v4 audience_scope enum
    $audienceScope = match($audience) {
        'snft'  => 'personal',
        'bnft'  => 'business',
        default => 'all',
    };
    $proposalKey = 'vote-' . gmdate('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);

    // v4 schema: proposal_key, audience_scope — removed options_json, tally_status, dispute_window_closes_at
    $stmt = $db->prepare('INSERT INTO vote_proposals (proposal_key, audience_scope, title, summary, body, proposal_type, is_public, status, created_by, starts_at, closes_at, created_at, updated_at) VALUES (?,?,?,?,?,\'opinion\',1,\'open\',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $stmt->execute([
        $proposalKey,
        $audienceScope,
        $title,
        $summary !== '' ? $summary : null,
        $content !== '' ? $content : null,
        $createdBy,
        nowUtc(),
        $closesAt,
    ]);
    $proposalId = (int)$db->lastInsertId();
    if (api_table_exists($db, 'proposal_register')) {
        try {
            $db->prepare(
                "INSERT INTO proposal_register (proposal_key, title, proposal_type, summary, body, origin_type, status, created_at, updated_at)
                 VALUES (?, ?, 'governance', ?, ?, 'admin', 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), body = VALUES(body), status = 'open', updated_at = UTC_TIMESTAMP()"
            )->execute([$proposalKey, $title, $summary !== '' ? $summary : null, $content !== '' ? $content : null]);
        } catch (Throwable $bridgeErr) {
            error_log('[admin/vote-push] proposal_register bridge failed: ' . $bridgeErr->getMessage());
        }
    }
    recordAudienceWalletEvent($db, $audience, 'vote_opened', 'Beta vote opened: ' . $title);

    apiSuccess([
        'proposal_id' => $proposalId,
        'title' => $title,
        'audience' => $audience,
        'options' => $options,
        'created_at' => nowUtc(),
    ], 201);
}

function adminVotes(): void {
    requireMethod('GET');
    requireAdminRole();
    $db = getDB();
    apiSuccess(['items' => fetchVoteDashboard($db, 50), 'refreshed_at' => nowUtc()]);
}

function adminVoteClose(int $proposalId): void {
    requireMethod('POST');
    requireAdminRole(['superadmin', 'governance_admin']);
    $db = getDB();
    $stmt = $db->prepare('UPDATE vote_proposals SET status = \'closed\', closes_at = COALESCE(closes_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?');
    $stmt->execute([$proposalId]);
    if ($stmt->rowCount() < 1) {
        apiError('Vote proposal not found.', 404);
    }
    $titleStmt = $db->prepare('SELECT title, audience_scope AS audience, proposal_key FROM vote_proposals WHERE id = ? LIMIT 1');
    $titleStmt->execute([$proposalId]);
    $row = $titleStmt->fetch();
    if ($row) {
        recordAudienceWalletEvent($db, (string)$row['audience'], 'vote_closed', 'Beta vote closed: ' . (string)$row['title']);
        if (!empty($row['proposal_key']) && api_table_exists($db, 'proposal_register')) {
            try {
                $db->prepare("UPDATE proposal_register SET status = 'resolved', updated_at = UTC_TIMESTAMP() WHERE proposal_key = ?")->execute([(string)$row['proposal_key']]);
            } catch (Throwable $bridgeErr) {
                error_log('[admin/vote-close] proposal_register bridge failed: ' . $bridgeErr->getMessage());
            }
        }
    }
    refreshProposalDisputeState($db, $proposalId);
    apiSuccess(['proposal_id' => $proposalId, 'status' => 'closed']);
}

function adminDisputes(): void {
    requireMethod('GET');
    requireAdminRole(['superadmin', 'governance_admin', 'operations_admin']);
    $db = getDB();
    apiSuccess(['items' => fetchDisputeDashboard($db, 50), 'refreshed_at' => nowUtc()]);
}

function adminDisputeStatus(int $disputeId): void {
    requireMethod('POST');
    $admin = requireAdminRole(['superadmin', 'governance_admin']);
    $db = getDB();
    $body = jsonBody();
    $status = strtolower(sanitize($body['status'] ?? 'resolved'));
    $note = sanitize($body['resolution_note'] ?? '');
    if (!in_array($status, ['resolved', 'rejected'], true)) {
        apiError('Invalid dispute status.');
    }
    // vote_disputes table may not exist yet — guard both queries
    $proposalId = 0;
    try {
        $proposalStmt = $db->prepare('SELECT proposal_id FROM vote_disputes WHERE id = ? LIMIT 1');
        $proposalStmt->execute([$disputeId]);
        $proposalId = (int)($proposalStmt->fetch()['proposal_id'] ?? 0);
    } catch (Throwable $e) {}
    if ($proposalId < 1) {
        apiError('Dispute not found.', 404);
    }
    try {
        $stmt = $db->prepare('UPDATE vote_disputes SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$status, $note !== '' ? $note : null, (string)$admin['display_name'], $disputeId]);
    } catch (Throwable $e) {}
    refreshProposalDisputeState($db, $proposalId);
    apiSuccess(['dispute_id' => $disputeId, 'status' => $status]);
}

function fetchVoteDashboard(PDO $db, int $limit = 20): array {
    // v4 columns: proposal_key, audience_scope — no options_json, tally_status, dispute_window_closes_at
    $stmt = $db->prepare('SELECT id, proposal_key, audience_scope, title, summary, body, proposal_type, status, is_public, starts_at, closes_at, created_at, updated_at FROM vote_proposals ORDER BY id DESC LIMIT ' . (int)$limit);
    $stmt->execute();
    $items = $stmt->fetchAll();
    if (!$items) {
        return [];
    }
    $ids = array_map(static fn(array $item): int => (int)$item['id'], $items);
    $tallies = voteTalliesByProposal($db, $ids);
    $disputes = voteDisputesByProposal($db, $ids);

    foreach ($items as &$item) {
        $pid = (int)$item['id'];
        // v4: no options_json — tally is by response_value from vote_proposal_responses
        $tallyData = $tallies[$pid] ?? [];
        $counts = [];
        $total = 0;
        foreach ($tallyData as $choice => $count) {
            $counts[] = ['label' => $choice, 'votes' => (int)$count];
            $total += (int)$count;
        }
        $item['tally'] = $counts;
        $item['total_votes'] = $total;
        $item['open_disputes'] = (int)($disputes[$pid]['open'] ?? 0);
        $item['resolved_disputes'] = (int)($disputes[$pid]['resolved'] ?? 0);
        $item['rejected_disputes'] = (int)($disputes[$pid]['rejected'] ?? 0);
    }
    unset($item);
    return $items;
}

function fetchDisputeDashboard(PDO $db, int $limit = 20): array {
    try {
        $stmt = $db->prepare('SELECT d.id, d.proposal_id, d.subject_type, d.subject_ref, d.reason, d.status, d.resolution_note, d.resolved_by, d.created_at, p.title AS proposal_title FROM vote_disputes d INNER JOIN vote_proposals p ON p.id = d.proposal_id ORDER BY d.id DESC LIMIT ' . (int)$limit);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return []; // vote_disputes table may not exist yet
    }
}

function voteTalliesByProposal(PDO $db, array $proposalIds): array {
    if (!$proposalIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($proposalIds), '?'));
    // v4: vote_records → vote_proposal_responses, choice_value → response_value
    $stmt = $db->prepare('SELECT proposal_id, response_value AS choice_value, COUNT(*) AS votes FROM vote_proposal_responses WHERE proposal_id IN (' . $placeholders . ') GROUP BY proposal_id, response_value');
    $stmt->execute($proposalIds);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['proposal_id']][(string)$row['choice_value']] = (int)$row['votes'];
    }
    return $out;
}

function voteDisputesByProposal(PDO $db, array $proposalIds): array {
    if (!$proposalIds) {
        return [];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($proposalIds), '?'));
        $stmt = $db->prepare('SELECT proposal_id, status, COUNT(*) AS total FROM vote_disputes WHERE proposal_id IN (' . $placeholders . ') GROUP BY proposal_id, status');
        $stmt->execute($proposalIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['proposal_id']][(string)$row['status']] = (int)$row['total'];
        }
        return $out;
    } catch (Throwable $e) {
        return []; // vote_disputes table may not exist yet
    }
}

function refreshProposalDisputeState(PDO $db, int $proposalId): void {
    if ($proposalId < 1) {
        return;
    }
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM vote_disputes WHERE proposal_id = ? AND status = \'open\'');
        $stmt->execute([$proposalId]);
        // vote_disputes exists — nothing else to update in v4 schema
    } catch (Throwable $e) {
        // vote_disputes table doesn't exist yet — no-op
    }
}

function recordAudienceWalletEvent(PDO $db, string $audience, string $eventType, string $description): void {
    if (in_array($audience, ['all', 'snft'], true)) {
        $stmt = $db->query('SELECT member_number FROM snft_memberships');
        foreach ($stmt->fetchAll() as $row) {
            recordWalletEvent($db, 'snft_member', (string)$row['member_number'], $eventType, $description);
        }
    }
    if (in_array($audience, ['all', 'bnft'], true)) {
        $stmt = $db->query('SELECT abn FROM bnft_memberships');
        foreach ($stmt->fetchAll() as $row) {
            recordWalletEvent($db, 'bnft_business', (string)$row['abn'], $eventType, $description);
        }
    }
}

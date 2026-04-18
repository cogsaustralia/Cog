<?php
declare(strict_types=1);

$action = trim((string)($id ?? ''), '/');
if ($action === 'cast') {
    castVote();
}
if ($action === 'summary') {
    voteSummary();
}
if ($action === 'dispute') {
    submitVoteDispute();
}
apiError('Unknown vote route', 404);

function castVote(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $proposalId = (int)($body['proposal_id'] ?? 0);
    $choice = sanitize($body['choice'] ?? '');
    $note   = trim((string)($body['note'] ?? ''));
    if ($proposalId < 1 || $choice === '') {
        apiError('Proposal and vote choice are required.');
    }

    $proposal = fetchProposalRow($db, $proposalId);
    ensureProposalVisibleToUserType($proposal, 'snft');
    ensureProposalCanReceiveVotes($db, $proposal);

    $allowed = ['yes', 'maybe', 'no'];
    if (!in_array($choice, $allowed, true)) {
        apiError('Invalid choice. Must be yes, maybe, or no.');
    }

    // v4: vote_proposal_responses keyed by (proposal_id, member_id) — ON DUPLICATE allows updates while open.
    $voteWeight = calculateVoteWeight($db, (int)$principal['principal_id']);
    try {
        $stmt = $db->prepare(
            'INSERT INTO vote_proposal_responses (proposal_id, member_id, response_value, response_note, vote_weight, submitted_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE response_value = VALUES(response_value), response_note = VALUES(response_note), vote_weight = VALUES(vote_weight), submitted_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$proposalId, (int)$principal['principal_id'], $choice, $note ?: null, $voteWeight]);
    } catch (Throwable $e) {
        // vote_weight or response_note column may not exist — fall back without them
        $stmt = $db->prepare(
            'INSERT INTO vote_proposal_responses (proposal_id, member_id, response_value, submitted_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE response_value = VALUES(response_value), submitted_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$proposalId, (int)$principal['principal_id'], $choice]);
    }
    recordWalletEvent($db, 'snft_member', (string)$principal['subject_ref'], 'vote_cast', 'Response recorded for: ' . (string)$proposal['title'] . ' → ' . $choice);

    apiSuccess([
        'proposal_id' => $proposalId,
        'choice' => $choice,
        'recorded_at' => nowUtc(),
    ]);
}

function submitVoteDispute(): void {
    requireMethod('POST');
    $principal = requireAnyUserType(['snft', 'bnft']);
    $db = getDB();
    $body = jsonBody();

    $proposalId = (int)($body['proposal_id'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));
    if ($proposalId < 1 || strlen($reason) < 8) {
        apiError('A proposal and a brief dispute reason are required.');
    }

    $proposal = fetchProposalRow($db, $proposalId);
    ensureProposalVisibleToUserType($proposal, (string)$principal['user_type']);
    $subjectType = (string)$principal['user_type'] === 'snft' ? 'snft_member' : 'bnft_business';

    // vote_disputes table not present in v4 — record dispute as a wallet event for admin review.
    recordWalletEvent($db, $subjectType, (string)$principal['subject_ref'], 'vote_dispute_opened',
        'Tally dispute lodged for proposal #' . $proposalId . ': ' . (string)$proposal['title'] . ' — ' . $reason);

    apiSuccess([
        'proposal_id' => $proposalId,
        'status' => 'open',
        'recorded_at' => nowUtc(),
    ], 201);
}

function voteSummary(): void {
    requireMethod('GET');
    $db = getDB();
    $stmt = $db->query('SELECT vp.id, vp.proposal_key, vp.audience_scope AS audience, vp.title, vp.summary, vp.body, vp.status, vp.proposal_type, vp.starts_at, vp.closes_at, pr.status AS bridge_status, pr.id AS bridge_id FROM vote_proposals vp LEFT JOIN proposal_register pr ON pr.proposal_key = vp.proposal_key ORDER BY vp.id DESC LIMIT 20');
    $items = $stmt->fetchAll();
    if (!$items) {
        apiSuccess(['items' => [], 'refreshed_at' => nowUtc()]);
    }

    $ids = array_map(static fn(array $item): int => (int)$item['id'], $items);
    $tallies = talliesByProposal($db, $ids);

    foreach ($items as &$item) {
        // v4: fixed options are yes/maybe/no for all proposals
        $options = ['yes', 'maybe', 'no'];
        $counts = [];
        $total = 0;
        foreach ($options as $option) {
            $votes = (int)($tallies[(int)$item['id']][$option] ?? 0);
            $counts[] = ['label' => $option, 'votes' => $votes];
            $total += $votes;
        }
        $item['tally'] = $counts;
        $item['options'] = $options;
        $item['total_votes'] = $total;
    }
    unset($item);

    apiSuccess(['items' => $items, 'refreshed_at' => nowUtc()]);
}

function fetchProposalRow(PDO $db, int $proposalId): array {
    // v4 vote_proposals: uses audience_scope (not audience), has no options_json/tally_status/dispute_window_closes_at.
    $proposalStmt = $db->prepare('SELECT vp.id, vp.proposal_key, vp.audience_scope AS audience, vp.title, vp.status, vp.closes_at, vp.proposal_type, pr.status AS bridge_status, pr.id AS bridge_id FROM vote_proposals vp LEFT JOIN proposal_register pr ON pr.proposal_key = vp.proposal_key WHERE vp.id = ? LIMIT 1');
    $proposalStmt->execute([$proposalId]);
    $proposal = $proposalStmt->fetch();
    if (!$proposal) {
        apiError('Vote proposal not found.', 404);
    }
    return $proposal;
}

function ensureProposalVisibleToUserType(array $proposal, string $userType): void {
    // audience_scope uses 'personal'/'business' (v4). Also accepts legacy 'snft'/'bnft' values.
    $audience = (string)$proposal['audience'];
    if ($userType === 'snft' && !in_array($audience, ['all', 'snft', 'personal'], true)) {
        apiError('This proposal is not available to personal members.', 403);
    }
    if ($userType === 'bnft' && !in_array($audience, ['all', 'bnft', 'business'], true)) {
        apiError('This proposal is not available to business members.', 403);
    }
}

function ensureProposalCanReceiveVotes(PDO $db, array $proposal): void {
    if ((string)$proposal['status'] !== 'open') {
        apiError('Voting is closed for this proposal.', 400);
    }
    if ($proposal['closes_at'] && strtotime((string)$proposal['closes_at']) < time()) {
        $db->prepare('UPDATE vote_proposals SET status = "closed", updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$proposal['id']]);
        apiError('Voting is closed for this proposal.', 400);
    }
}

function talliesByProposal(PDO $db, array $proposalIds): array {
    if (!$proposalIds) return [];
    $placeholders = implode(',', array_fill(0, count($proposalIds), '?'));
    // v4 uses vote_proposal_responses with response_value (enum yes/maybe/no)
    $stmt = $db->prepare('SELECT proposal_id, response_value AS choice_value, COUNT(*) AS votes FROM vote_proposal_responses WHERE proposal_id IN (' . $placeholders . ') GROUP BY proposal_id, response_value');
    $stmt->execute($proposalIds);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['proposal_id']][(string)$row['choice_value']] = (int)$row['votes'];
    }
    return $out;
}

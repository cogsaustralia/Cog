<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$labels = ops_label_settings($pdo);
$partnerLabel = $labels['public_label_partner'] ?? 'Partner';
$adminUserId = ops_current_admin_user_id($pdo);
$legacyAdminId = ops_admin_id();
$canManage = ops_admin_can($pdo, 'execution.manage') || ops_admin_can($pdo, 'operations.manage') || ops_admin_can($pdo, 'admin.full');

function ex_rows(PDO $pdo, string $sql, array $params = []): array { try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; } }
function ex_row(PDO $pdo, string $sql, array $params = []): ?array { try { return ops_fetch_one($pdo, $sql, $params); } catch (Throwable $e) { return null; } }
function ex_val(PDO $pdo, string $sql, array $params = []): int { try { return (int)ops_fetch_val($pdo, $sql, $params); } catch (Throwable $e) { return 0; } }
function ex_now(): string { return date('Y-m-d H:i:s'); }
function ex_req_key(int $approvalId): string { return 'ERQ-APR-' . $approvalId; }
function ex_batch_key(): string { return 'EXB-' . date('YmdHis'); }
function ex_batch_code(): string { return 'MB-' . date('YmdHis'); }
function ex_quorum_key(int $batchId): string { return 'QRQ-' . $batchId . '-' . date('YmdHis'); }
function ex_handoff_code(int $legacyBatchId): string { return 'HO-' . $legacyBatchId . '-' . date('YmdHis'); }

$flash = null;
$flashType = 'ok';

function ex_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function ex_in_tx(PDO $pdo, callable $callback) {
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }
    try {
        $result = $callback();
        if ($started && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
function ex_batchable_statuses(): array { return ['approved','reviewed','drafted']; }
function ex_require_batch(PDO $pdo, int $batchId): array {
    $batch = ex_row($pdo, "SELECT * FROM execution_batches WHERE id = ? LIMIT 1", [$batchId]);
    if (!$batch) {
        throw new RuntimeException('Batch not found.');
    }
    return $batch;
}
function ex_latest_quorum(PDO $pdo, int $batchId): ?array {
    return ex_row($pdo, "SELECT * FROM quorum_requests WHERE execution_batch_id = ? ORDER BY id DESC LIMIT 1", [$batchId]);
}
function ex_latest_submission(PDO $pdo, int $batchId): ?array {
    return ex_row($pdo, "SELECT * FROM execution_submissions WHERE execution_batch_id = ? ORDER BY id DESC LIMIT 1", [$batchId]);
}
function ex_request_ids_for_batch(PDO $pdo, int $batchId): array {
    return array_map('intval', array_column(ex_rows($pdo, "SELECT execution_request_id FROM execution_batch_items WHERE execution_batch_id = ?", [$batchId]), 'execution_request_id'));
}
function ex_update_batch_request_state(PDO $pdo, int $batchId, string $requestStatus, string $itemStatus): void {
    $pdo->prepare("UPDATE execution_batch_items SET item_status = ? WHERE execution_batch_id = ?")->execute([$itemStatus, $batchId]);
    $pdo->prepare("UPDATE execution_requests er JOIN execution_batch_items ebi ON ebi.execution_request_id = er.id SET er.execution_status = ?, er.updated_at = NOW() WHERE ebi.execution_batch_id = ?")
        ->execute([$requestStatus, $batchId]);
}
function ex_ui_can_open_quorum(array $row): bool {
    return in_array((string)($row['batch_status'] ?? ''), ['prepared', 'reviewed'], true) && empty($row['quorum_status']);
}
function ex_ui_can_mark_quorum_met(array $row): bool {
    return (string)($row['batch_status'] ?? '') === 'quorum_requested' && (string)($row['quorum_status'] ?? '') === 'open';
}
function ex_ui_can_submit(array $row): bool {
    return (string)($row['batch_status'] ?? '') === 'reviewed'
        && (string)($row['quorum_status'] ?? '') === 'quorum_met'
        && !in_array((string)($row['submission_status'] ?? ''), ['submitted', 'accepted', 'finalised'], true);
}
function ex_ui_can_finalise(array $row): bool {
    return (string)($row['batch_status'] ?? '') === 'submitted'
        && in_array((string)($row['submission_status'] ?? ''), ['submitted', 'accepted'], true);
}
function ex_ui_can_publish(array $row): bool {
    return (string)($row['batch_status'] ?? '') === 'finalised'
        && (string)($row['submission_status'] ?? '') === 'finalised';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    if (!$canManage) {
        http_response_code(403);
        $flash = 'You do not have permission to manage execution actions.';
        $flashType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_request_from_approval') {
                ex_in_tx($pdo, function () use ($pdo, $adminUserId, &$flash): void {
                    $approvalId = (int)($_POST['approval_request_id'] ?? 0);
                    $approval = ex_row($pdo, "SELECT ar.*, mq.id AS mint_queue_id, mq.approved_units, mq.execution_request_id, mq.evidence_reference
                        FROM approval_requests ar
                        LEFT JOIN mint_queue mq ON mq.approval_request_id = ar.id
                        WHERE ar.id = ? LIMIT 1", [$approvalId]);
                    ex_assert((bool)$approval, 'Approval request not found.');
                    ex_assert((string)($approval['request_status'] ?? '') === 'approved', 'Only approved items can become execution requests.');
                    $existing = ex_row($pdo, "SELECT id FROM execution_requests WHERE approval_request_id = ? LIMIT 1", [$approvalId]);
                    if ($existing) {
                        $flash = 'Execution request already exists for this approval.';
                        return;
                    }
                    $partnerId = (int)(ex_val($pdo, "SELECT id FROM partners WHERE member_id = ? LIMIT 1", [(int)$approval['member_id']]) ?: 0);
                    $units = (float)($approval['approved_units'] ?? 0) ?: (float)($approval['requested_units'] ?? 0);
                    $stmt = $pdo->prepare("INSERT INTO execution_requests
                        (request_key, approval_request_id, legacy_mint_queue_id, request_type, partner_id, member_id, token_class_id, ledger_target, execution_status, units_requested, payload_json, evidence_bundle_ref, created_by_admin_user_id, reviewed_by_admin_user_id, created_at, updated_at)
                        VALUES (?, ?, ?, 'mint', ?, ?, ?, 'phase1-parallel', 'approved', ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([
                        ex_req_key($approvalId),
                        $approvalId,
                        $approval['mint_queue_id'] ? (int)$approval['mint_queue_id'] : null,
                        $partnerId ?: null,
                        (int)$approval['member_id'],
                        (int)$approval['token_class_id'],
                        $units > 0 ? $units : null,
                        null,
                        $approval['evidence_reference'] ?: null,
                        $adminUserId,
                        $adminUserId,
                    ]);
                    $executionRequestId = (int)$pdo->lastInsertId();
                    $backingAttach = function_exists('ops_asset_backing_attach_to_execution_request') ? ops_asset_backing_attach_to_execution_request($pdo, $approvalId, $executionRequestId) : ['required' => false, 'attached_ids' => []];
                    if (!empty($backingAttach['required'])) {
                        // Collect trade IDs from backing allocations for document hash lookup
                        $backingAllocIds = array_values($backingAttach['attached_ids'] ?? []);
                        $tradeIds = [];
                        if (!empty($backingAllocIds) && ops_has_table($pdo, 'stewardship_backing_allocations')) {
                            $placeholders = implode(',', array_fill(0, count($backingAllocIds), '?'));
                            $tradeIds = array_filter(array_column(
                                ops_fetch_all($pdo, "SELECT asx_trade_id FROM stewardship_backing_allocations WHERE id IN ($placeholders) AND asx_trade_id IS NOT NULL", $backingAllocIds),
                                'asx_trade_id'
                            ));
                        }
                        $docHashes = (!empty($tradeIds) && function_exists('ops_get_asx_trade_document_hashes'))
                            ? ops_get_asx_trade_document_hashes($pdo, array_map('intval', $tradeIds))
                            : [];
                        $payload = ['asset_backing_allocation_ids' => $backingAllocIds];
                        if (!empty($docHashes)) {
                            $payload['trade_document_hashes'] = $docHashes;
                        }
                        $pdo->prepare("UPDATE execution_requests SET payload_json = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $executionRequestId]);
                    }
                    if (!empty($approval['mint_queue_id'])) {
                        $pdo->prepare("UPDATE mint_queue SET execution_request_id = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$executionRequestId, (int)$approval['mint_queue_id']]);
                    }
                    ops_log_wallet_activity($pdo, (int)$approval['member_id'], (int)$approval['token_class_id'], 'execution_request_created', 'admin', $adminUserId, ['execution_request_id' => $executionRequestId, 'approval_request_id' => $approvalId]);
                    $flash = !empty($backingAttach['required']) ? 'Execution request created and linked to live asset backing.' : 'Execution request created.';
                });
            } elseif ($action === 'batch_requests') {
                ex_in_tx($pdo, function () use ($pdo, $adminUserId, $legacyAdminId, &$flash): void {
                    $requestIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['execution_request_ids'] ?? [])))));
                    ex_assert((bool)$requestIds, 'Select at least one execution request to batch.');
                    $place = implode(',', array_fill(0, count($requestIds), '?'));
                    $selected = ex_rows($pdo, "SELECT * FROM execution_requests WHERE id IN ($place) ORDER BY id ASC", $requestIds);
                    ex_assert((bool)$selected, 'No execution requests found for batching.');
                    ex_assert(count($selected) === count($requestIds), 'One or more execution requests could not be loaded.');

                    foreach ($selected as $s) {
                        ex_assert(in_array((string)($s['execution_status'] ?? ''), ex_batchable_statuses(), true), 'Only approved, reviewed, or drafted requests can be batched.');
                        $alreadyBatched = ex_val($pdo, 'SELECT COUNT(*) FROM execution_batch_items WHERE execution_request_id = ?', [(int)$s['id']]);
                        ex_assert($alreadyBatched === 0, 'One or more selected requests are already attached to a batch.');
                    }

                    $batchKey = ex_batch_key();
                    $legacyBatchId = null;
                    $hasLegacy = false;
                    foreach ($selected as $s) {
                        if (!empty($s['legacy_mint_queue_id'])) {
                            $hasLegacy = true;
                            break;
                        }
                    }
                    if ($hasLegacy) {
                        ex_assert(ops_has_table($pdo, 'mint_batches'), 'Legacy bridge table mint_batches is unavailable.');
                        ex_assert(ops_has_table($pdo, 'mint_batch_items'), 'Legacy bridge table mint_batch_items is unavailable.');
                        ex_assert(ops_has_table($pdo, 'mint_queue'), 'Legacy bridge table mint_queue is unavailable.');
                        $batchCode = ex_batch_code();
                        $stmt = $pdo->prepare("INSERT INTO mint_batches (batch_code, batch_label, chain_target, ledger_target, network_ref, shard_ref, batch_status, created_by_admin_id, reviewed_by_admin_id, notes, created_at, updated_at)
                            VALUES (?, ?, 'besu-prep', 'phase1-parallel', 'phase1-parallel', 'registry-main', 'prepared', ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([$batchCode, 'Phase 1 execution batch ' . $batchCode, $legacyAdminId, $legacyAdminId, 'Created from execution console']);
                        $legacyBatchId = (int)$pdo->lastInsertId();
                    }

                    $stmt = $pdo->prepare("INSERT INTO execution_batches (batch_key, legacy_mint_batch_id, ledger_target, batch_status, created_by_admin_user_id, reviewed_by_admin_user_id, notes, created_at, updated_at)
                        VALUES (?, ?, 'phase1-parallel', 'prepared', ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$batchKey, $legacyBatchId ?: null, $adminUserId, $adminUserId, 'Created from execution console']);
                    $executionBatchId = (int)$pdo->lastInsertId();

                    $insertItem = $pdo->prepare("INSERT INTO execution_batch_items (execution_batch_id, execution_request_id, item_status, created_at) VALUES (?, ?, 'queued', NOW())");
                    $updateReq = $pdo->prepare("UPDATE execution_requests SET execution_status='batched', updated_at = NOW() WHERE id = ?");
                    $insertLegacyItem = $hasLegacy ? $pdo->prepare("INSERT INTO mint_batch_items (batch_id, mint_queue_id, member_id, token_class_id, approval_request_id, queue_status_snapshot, lane_snapshot, notes_snapshot, evidence_reference_snapshot, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())") : null;
                    $updateMintQueue = $hasLegacy ? $pdo->prepare("UPDATE mint_queue SET batch_id = ?, updated_at = NOW() WHERE id = ?") : null;

                    foreach ($selected as $s) {
                        $insertItem->execute([$executionBatchId, (int)$s['id']]);
                        $updateReq->execute([(int)$s['id']]);
                        if ($legacyBatchId && $insertLegacyItem && $updateMintQueue && !empty($s['legacy_mint_queue_id'])) {
                            $mq = ex_row($pdo, "SELECT * FROM mint_queue WHERE id = ? LIMIT 1", [(int)$s['legacy_mint_queue_id']]);
                            ex_assert((bool)$mq, 'Legacy mint queue row missing for one or more selected requests.');
                            $insertLegacyItem->execute([
                                $legacyBatchId,
                                (int)$mq['id'],
                                (int)$mq['member_id'],
                                (int)$mq['token_class_id'],
                                (int)$mq['approval_request_id'],
                                (string)$mq['queue_status'],
                                (string)($mq['manual_signoff_lane'] ?? ''),
                                $mq['notes'] ?? null,
                                $mq['evidence_reference'] ?? null,
                            ]);
                            $updateMintQueue->execute([$legacyBatchId, (int)$mq['id']]);
                        }
                    }
                    $flash = 'Execution batch created.';
                });
            } elseif ($action === 'open_quorum') {
                ex_in_tx($pdo, function () use ($pdo, &$flash): void {
                    $batchId = (int)($_POST['execution_batch_id'] ?? 0);
                    $batch = ex_require_batch($pdo, $batchId);
                    $quorum = ex_latest_quorum($pdo, $batchId);
                    ex_assert(in_array((string)($batch['batch_status'] ?? ''), ['prepared', 'reviewed'], true), 'Only prepared or reviewed batches can open quorum.');
                    ex_assert(!$quorum || !in_array((string)($quorum['status'] ?? ''), ['open', 'quorum_met'], true), 'This batch already has an active or completed quorum request.');
                    $pdo->prepare("INSERT INTO quorum_requests (request_key, execution_batch_id, required_signatures, status, opened_at, notes) VALUES (?, ?, 3, 'open', NOW(), ?)")
                        ->execute([ex_quorum_key($batchId), $batchId, 'Opened from execution console']);
                    $pdo->prepare("UPDATE execution_batches SET batch_status='quorum_requested', updated_at = NOW() WHERE id = ?")->execute([$batchId]);
                    ex_update_batch_request_state($pdo, $batchId, 'quorum_requested', 'quorum_requested');
                    $flash = 'Quorum request opened.';
                });
            } elseif ($action === 'mark_quorum_met') {
                ex_in_tx($pdo, function () use ($pdo, &$flash): void {
                    $batchId = (int)($_POST['execution_batch_id'] ?? 0);
                    $batch = ex_require_batch($pdo, $batchId);
                    $quorum = ex_latest_quorum($pdo, $batchId);
                    ex_assert((string)($batch['batch_status'] ?? '') === 'quorum_requested', 'Only batches awaiting quorum can be marked as quorum met.');
                    ex_assert((string)($quorum['status'] ?? '') === 'open', 'There is no open quorum request for this batch.');
                    $pdo->prepare("UPDATE quorum_requests SET status='quorum_met', closed_at = NOW() WHERE id = ?")->execute([(int)$quorum['id']]);
                    $pdo->prepare("UPDATE execution_batches SET batch_status='reviewed', updated_at = NOW() WHERE id = ?")->execute([$batchId]);
                    ex_update_batch_request_state($pdo, $batchId, 'quorum_met', 'quorum_met');
                    $flash = 'Quorum marked as met.';
                });
            } elseif ($action === 'mark_submitted') {
                ex_in_tx($pdo, function () use ($pdo, $legacyAdminId, &$flash): void {
                    $batchId = (int)($_POST['execution_batch_id'] ?? 0);
                    $txHash = trim((string)($_POST['ledger_tx_hash'] ?? ''));
                    $batch = ex_require_batch($pdo, $batchId);
                    $quorum = ex_latest_quorum($pdo, $batchId);
                    $submission = ex_latest_submission($pdo, $batchId);
                    ex_assert((string)($batch['batch_status'] ?? '') === 'reviewed', 'A batch can only be submitted after quorum has been met and the batch is reviewed.');
                    ex_assert((string)($quorum['status'] ?? '') === 'quorum_met', 'A batch cannot be submitted before quorum is met.');
                    ex_assert(!$submission || !in_array((string)($submission['submission_status'] ?? ''), ['submitted', 'accepted', 'finalised'], true), 'This batch already has an active submission record.');

                    if ($submission) {
                        $pdo->prepare("UPDATE execution_submissions SET ledger_tx_hash = ?, submission_status='submitted', submitted_at = COALESCE(submitted_at, NOW()), notes = ? WHERE id = ?")
                            ->execute([$txHash !== '' ? $txHash : null, 'Submitted from execution console', (int)$submission['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO execution_submissions (execution_batch_id, ledger_tx_hash, submission_status, submitted_at, notes) VALUES (?, ?, 'submitted', NOW(), ?)")
                            ->execute([$batchId, $txHash !== '' ? $txHash : null, 'Submitted from execution console']);
                    }

                    $pdo->prepare("UPDATE execution_batches SET batch_status='submitted', updated_at = NOW() WHERE id = ?")->execute([$batchId]);
                    ex_update_batch_request_state($pdo, $batchId, 'submitted', 'submitted');

                    if (!empty($batch['legacy_mint_batch_id'])) {
                        ex_assert(ops_has_table($pdo, 'chain_handoffs'), 'Legacy bridge table chain_handoffs is unavailable.');
                        $handoff = ex_row($pdo, "SELECT id FROM chain_handoffs WHERE mint_batch_id = ? LIMIT 1", [(int)$batch['legacy_mint_batch_id']]);
                        if ($handoff) {
                            $pdo->prepare("UPDATE chain_handoffs SET handoff_status='submitted', submission_status='submitted', tx_reference = COALESCE(?, tx_reference), updated_at = NOW() WHERE id = ?")
                                ->execute([$txHash !== '' ? $txHash : null, (int)$handoff['id']]);
                        } else {
                            $pdo->prepare("INSERT INTO chain_handoffs (mint_batch_id, handoff_code, chain_target, ledger_target, network_ref, shard_ref, handoff_status, submission_status, tx_reference, prepared_by_admin_id, reviewed_by_admin_id, notes, created_at, updated_at)
                                VALUES (?, ?, 'besu-prep', 'phase1-parallel', 'phase1-parallel', 'registry-main', 'submitted', 'submitted', ?, ?, ?, ?, NOW(), NOW())")
                                ->execute([(int)$batch['legacy_mint_batch_id'], ex_handoff_code((int)$batch['legacy_mint_batch_id']), $txHash !== '' ? $txHash : null, $legacyAdminId, $legacyAdminId, 'Submitted from execution console']);
                        }
                    }
                    $flash = 'Batch marked submitted.';
                });
            } elseif ($action === 'mark_finalised') {
                ex_in_tx($pdo, function () use ($pdo, &$flash): void {
                    $batchId = (int)($_POST['execution_batch_id'] ?? 0);
                    $batch = ex_require_batch($pdo, $batchId);
                    $submission = ex_latest_submission($pdo, $batchId);
                    ex_assert((string)($batch['batch_status'] ?? '') === 'submitted', 'Only submitted batches can be finalised.');
                    ex_assert(in_array((string)($submission['submission_status'] ?? ''), ['submitted', 'accepted'], true), 'Only submitted or accepted batches can be finalised.');
                    $pdo->prepare("UPDATE execution_submissions SET submission_status='finalised', finalised_at = NOW() WHERE execution_batch_id = ?")->execute([$batchId]);
                    $pdo->prepare("UPDATE execution_batches SET batch_status='finalised', updated_at = NOW() WHERE id = ?")->execute([$batchId]);
                    ex_update_batch_request_state($pdo, $batchId, 'finalised', 'finalised');
                    if (!empty($batch['legacy_mint_batch_id'])) {
                        ex_assert(ops_has_table($pdo, 'chain_handoffs'), 'Legacy bridge table chain_handoffs is unavailable.');
                        $pdo->prepare("UPDATE chain_handoffs SET handoff_status='finalised', submission_status='finalised', finalised_at = NOW(), updated_at = NOW() WHERE mint_batch_id = ?")
                            ->execute([(int)$batch['legacy_mint_batch_id']]);
                    }
                    $flash = 'Batch marked finalised.';
                });
            } elseif ($action === 'publish_batch') {
                ex_in_tx($pdo, function () use ($pdo, $adminUserId, &$flash): void {
                    $batchId = (int)($_POST['execution_batch_id'] ?? 0);
                    $batch = ex_require_batch($pdo, $batchId);
                    $submission = ex_latest_submission($pdo, $batchId);
                    ex_assert((string)($batch['batch_status'] ?? '') === 'finalised', 'Only finalised batches can be published.');
                    ex_assert((string)($submission['submission_status'] ?? '') === 'finalised', 'A batch must have a finalised submission before publication.');
                    $reqs = ex_rows($pdo, "SELECT er.id, er.member_id, er.token_class_id, er.legacy_mint_queue_id FROM execution_requests er JOIN execution_batch_items ebi ON ebi.execution_request_id = er.id WHERE ebi.execution_batch_id = ?", [$batchId]);
                    ex_assert((bool)$reqs, 'No execution requests were found for this batch.');
                    $pubStmt = $pdo->prepare("INSERT INTO execution_publications (execution_request_id, published_to, publication_ref, published_at) VALUES (?, 'wallet_state', ?, NOW())");
                    $hasPub = $pdo->prepare("SELECT id FROM execution_publications WHERE execution_request_id = ? LIMIT 1");
                    $updateLegacyQueue = null;
                    foreach ($reqs as $r) {
                        if (!empty($r['legacy_mint_queue_id'])) {
                            ex_assert(ops_has_table($pdo, 'mint_queue'), 'Legacy bridge table mint_queue is unavailable.');
                            $updateLegacyQueue = $updateLegacyQueue ?: $pdo->prepare("UPDATE mint_queue SET live_status='live', queue_status='live', updated_at = NOW() WHERE id = ?");
                        }
                        $hasPub->execute([(int)$r['id']]);
                        if (!$hasPub->fetchColumn()) {
                            $pubStmt->execute([(int)$r['id'], 'PUB-' . (int)$r['id'] . '-' . date('YmdHis')]);
                        }
                        if (!empty($r['legacy_mint_queue_id']) && $updateLegacyQueue) {
                            $updateLegacyQueue->execute([(int)$r['legacy_mint_queue_id']]);
                        }
                        ops_log_wallet_activity($pdo, (int)$r['member_id'], (int)$r['token_class_id'], 'execution_published', 'admin', $adminUserId, ['execution_request_id' => (int)$r['id'], 'execution_batch_id' => $batchId]);
                    }
                    $pdo->prepare("UPDATE execution_batches SET batch_status='published', updated_at = NOW() WHERE id = ?")->execute([$batchId]);
                    ex_update_batch_request_state($pdo, $batchId, 'published', 'published');
                    if (function_exists('ops_asset_backing_mark_minted_for_batch')) { ops_asset_backing_mark_minted_for_batch($pdo, $batchId); }
                    $flash = 'Batch published to wallet state.';
                });
            }
        } catch (Throwable $e) {
            $flash = $e->getMessage();
            $flashType = 'error';
        }
    }
}

$rows = ops_has_table($pdo, 'v_phase1_execution_console') ? ex_rows($pdo, 'SELECT * FROM v_phase1_execution_console ORDER BY execution_request_id DESC LIMIT 100') : [];
$legacy = ops_has_table($pdo, 'v_phase1_legacy_execution_bridge') ? ex_rows($pdo, 'SELECT * FROM v_phase1_legacy_execution_bridge ORDER BY mint_queue_id DESC LIMIT 100') : [];
$counts = [
    'drafted' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='drafted'") : 0,
    'approved' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='approved'") : 0,
    'submitted' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='submitted'") : 0,
    'published' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='published'") : 0,
];
$readyApprovals = ex_rows($pdo, "SELECT ar.id, ar.member_id, m.member_number, COALESCE(m.full_name, m.email) AS member_name,
    tc.display_name AS token_class_name, tc.class_code, ar.requested_units, ar.request_status, ar.mint_status,
    mq.id AS mint_queue_id
    FROM approval_requests ar
    JOIN members m ON m.id = ar.member_id
    LEFT JOIN token_classes tc ON tc.id = ar.token_class_id
    LEFT JOIN mint_queue mq ON mq.approval_request_id = ar.id
    LEFT JOIN execution_requests er ON er.approval_request_id = ar.id
    WHERE ar.request_status = 'approved' AND er.id IS NULL
    ORDER BY ar.id DESC LIMIT 25");
$batchRows = ex_rows($pdo, "SELECT eb.*, q.id AS quorum_request_id, q.status AS quorum_status, q.required_signatures,
    es.submission_status, es.ledger_tx_hash, es.finalised_at, COUNT(ebi.id) AS item_count
    FROM execution_batches eb
    LEFT JOIN quorum_requests q ON q.execution_batch_id = eb.id AND q.status IN ('open','quorum_met')
    LEFT JOIN execution_submissions es ON es.execution_batch_id = eb.id
    LEFT JOIN execution_batch_items ebi ON ebi.execution_batch_id = eb.id
    GROUP BY eb.id, q.id, q.status, q.required_signatures, es.submission_status, es.ledger_tx_hash, es.finalised_at
    ORDER BY eb.id DESC LIMIT 30");
$csrf = admin_csrf_token();
$rows = ops_has_table($pdo, 'v_phase1_execution_console') ? ex_rows($pdo, 'SELECT * FROM v_phase1_execution_console ORDER BY execution_request_id DESC LIMIT 100') : [];
$legacy = ops_has_table($pdo, 'v_phase1_legacy_execution_bridge') ? ex_rows($pdo, 'SELECT * FROM v_phase1_legacy_execution_bridge ORDER BY mint_queue_id DESC LIMIT 100') : [];
$counts = [
    'drafted' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='drafted'") : 0,
    'approved' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='approved'") : 0,
    'submitted' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='submitted'") : 0,
    'published' => ops_has_table($pdo, 'execution_requests') ? ex_val($pdo, "SELECT COUNT(*) FROM execution_requests WHERE execution_status='published'") : 0,
];
$readyApprovals = ex_rows($pdo, "SELECT ar.id, ar.member_id, m.member_number, COALESCE(m.full_name, m.email) AS member_name,
    tc.display_name AS token_class_name, tc.class_code, ar.requested_units, ar.request_status, ar.mint_status,
    mq.id AS mint_queue_id
    FROM approval_requests ar
    JOIN members m ON m.id = ar.member_id
    LEFT JOIN token_classes tc ON tc.id = ar.token_class_id
    LEFT JOIN mint_queue mq ON mq.approval_request_id = ar.id
    LEFT JOIN execution_requests er ON er.approval_request_id = ar.id
    WHERE ar.request_status = 'approved' AND er.id IS NULL
    ORDER BY ar.id DESC LIMIT 25");
$batchRows = ex_rows($pdo, "SELECT eb.*, q.id AS quorum_request_id, q.status AS quorum_status, q.required_signatures,
    es.submission_status, es.ledger_tx_hash, es.finalised_at, COUNT(ebi.id) AS item_count
    FROM execution_batches eb
    LEFT JOIN quorum_requests q ON q.execution_batch_id = eb.id AND q.status IN ('open','quorum_met')
    LEFT JOIN execution_submissions es ON es.execution_batch_id = eb.id
    LEFT JOIN execution_batch_items ebi ON ebi.execution_batch_id = eb.id
    GROUP BY eb.id, q.id, q.status, q.required_signatures, es.submission_status, es.ledger_tx_hash, es.finalised_at
    ORDER BY eb.id DESC LIMIT 30");

ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<style>
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card{margin-bottom:18px}.actions{display:flex;gap:8px;flex-wrap:wrap}.mini-form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}.mini-input{width:200px;padding:.55rem .7rem;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#0c1319;color:#eef2f7}.small-btn{padding:.55rem .8rem;border-radius:10px;font-size:.9rem}.muted-note{font-size:.9rem;color:#9fb0c1}@media(max-width:760px){.stat-grid{grid-template-columns:1fr 1fr}}</style>
<div class="grid" style="margin-bottom:18px;gap:16px">
  <?= ops_admin_info_panel('Stage 5 · Execution workflow', 'What this page does', 'Execution is the authoritative operator workflow for moving approved items into controlled batches, confirming quorum, recording submission, marking finality, and publishing the resulting state. Use this page for live operator actions. Treat legacy mint and handoff pages as supporting bridge records unless you are checking compatibility or traceability.', [
    'Create execution requests only from approved items that are genuinely ready to move forward.',
    'Batch groups related execution requests into one governed processing bundle.',
    'Quorum and submission controls sit on the batch, not on each individual request.',
    'Publishing is the final operator step that exposes the settled result to downstream wallet state.'
  ]) ?>
  <?= ops_admin_workflow_panel('Typical workflow', 'A normal execution cycle moves left to right through the same operator sequence every time.', [
    ['title' => 'Create request', 'body' => 'Turn an approved intake/approval item into an execution request once it is ready for operational handling.'],
    ['title' => 'Batch', 'body' => 'Group one or more execution requests into a single processing bundle that will move through quorum, submission, finality, and publication together.'],
    ['title' => 'Open quorum', 'body' => 'Open the formal review/sign-off stage for that batch. This does not submit anything externally.'],
    ['title' => 'Quorum met', 'body' => 'Record that the required operator review/signature threshold has been met so the batch can move to submission.'],
    ['title' => 'Submitted → Finalised → Published', 'body' => 'Record the submission, then settlement, then publication. Publish only after the batch is genuinely complete and ready to appear as live state.' ],
  ]) ?>
  <?= ops_admin_guide_panel('How to read this page', 'There are four distinct working areas on the execution console.', [
    ['title' => 'Approvals ready', 'body' => 'Items that can become execution requests. Nothing has been batched yet.'],
    ['title' => 'Execution requests', 'body' => 'The request-level queue. Use this table to select items that should move into the same batch.'],
    ['title' => 'Execution batches', 'body' => 'The live operator controls. This is where Batch, Quorum, Submitted, Finalised, and Publish actions occur.'],
    ['title' => 'Legacy execution bridge', 'body' => 'Read-only compatibility and traceability view showing how the current execution state maps to older mint/handoff records.']
  ]) ?>
  <?= ops_admin_status_panel('Status guide', 'Use these meanings consistently when moving a batch through the operator workflow.', [
    ['label' => 'Approved / drafted / reviewed', 'body' => 'The request exists and can be prepared for batching or has been internally reviewed, but it is not yet submitted.'],
    ['label' => 'Batch created', 'body' => 'Requests are grouped into one processing bundle. This is the point where operators manage them together.'],
    ['label' => 'Quorum requested / met', 'body' => 'The batch is in the formal sign-off stage. Nothing should be submitted until quorum is actually met.'],
    ['label' => 'Submitted', 'body' => 'The batch has been recorded as submitted and may optionally carry a ledger transaction reference.'],
    ['label' => 'Finalised', 'body' => 'The submission is settled from an operator perspective and should no longer be treated as provisional.'],
    ['label' => 'Published', 'body' => 'The batch is complete and the resulting state has been pushed to downstream wallet/publication views.']
  ]) ?>
</div>
<div class="card">
  <h2 style="margin:0 0 8px">Execution console<?= ops_admin_help_button('Execution console', 'Use this page for live execution operations only. The normal operator flow is: create request, batch, open quorum, mark quorum met, mark submitted, mark finalised, then publish. Legacy bridge tables remain visible below for diagnostics and traceability, but they are not the primary operator workflow.') ?></h2>
  <p class="muted">This is the authoritative execution surface for batching, quorum, submission, finality, and publication. Legacy mint and chain-handoff pages remain available as bridge diagnostics and compatibility traces only.</p>
</div>
<div class="stat-grid">
  <?php foreach ($counts as $label => $val): ?>
    <div class="card"><div class="muted" style="text-transform:uppercase;font-size:.78rem"><?= ops_h($label) ?></div><div style="font-size:2rem;font-weight:800"><?= (int)$val ?></div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Approvals ready to become execution requests<?= ops_admin_help_button('Approvals ready', 'These rows are the intake items that are eligible to become execution requests. Creating an execution request does not batch, submit, or publish anything. It simply moves the approved item into the execution workflow so it can later be selected into a batch.') ?></h2><span class="muted">Bridge source: approval_requests → execution_requests</span></div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>Approval</th><th><?= ops_h($partnerLabel) ?></th><th>Member #</th><th>Class<?= ops_admin_help_button('Class', 'The token/class being moved into the execution workflow. Use this to confirm that the correct class is being advanced.') ?></th><th>Units<?= ops_admin_help_button('Units', 'The quantity approved for this execution item. Confirm this before creating the execution request.') ?></th><th>Action<?= ops_admin_help_button('Create execution request', 'This creates the request-level execution record. It does not yet create a batch or change live wallet state.') ?></th></tr></thead>
    <tbody>
      <?php if (!$readyApprovals): ?><tr><td colspan="6">No approved items are waiting for execution request creation.</td></tr><?php endif; ?>
      <?php foreach ($readyApprovals as $row): ?>
      <tr>
        <td>#<?= (int)$row['id'] ?></td>
        <td><?= ops_h($row['member_name'] ?? '') ?></td>
        <td><?= ops_h($row['member_number'] ?? '') ?></td>
        <td><?= ops_h($row['token_class_name'] ?? '') ?></td>
        <td><?= number_format((float)($row['requested_units'] ?? 0), 4) ?></td>
        <td>
          <?php $backing = function_exists('ops_asset_backing_status_for_approval') ? ops_asset_backing_status_for_approval($pdo, (int)$row['id']) : ['required' => false]; ?>
          <?php if (!empty($backing['required'])): ?>
            <span class="chip"><?= ops_h(!empty($backing['is_fully_backed']) ? 'Asset backed' : 'Awaiting backing') ?></span>
            <div class="muted-note" style="margin-top:6px">Remaining <?= number_format((float)($backing['remaining_units'] ?? 0), 4) ?> · <a href="./asset_backing.php#approval-<?= (int)$row['id'] ?>">Open backing</a></div>
          <?php else: ?>
            <span class="muted-note">Not required</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($canManage): ?>
          <form method="post" class="mini-form">
            <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
            <input type="hidden" name="action" value="create_request_from_approval">
            <input type="hidden" name="approval_request_id" value="<?= (int)$row['id'] ?>">
            <button class="small-btn" type="submit">Create execution request</button>
          </form>
          <?php else: ?><span class="muted-note">Read only</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><h2 style="margin:0">Execution requests<?= ops_admin_help_button('Execution requests', 'This is the request-level queue. Select compatible requests here and batch them together when they should move through quorum, submission, finality, and publication as one operator bundle.') ?></h2><span class="muted">Read source: v_phase1_execution_console · authoritative operator view</span></div>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
    <input type="hidden" name="action" value="batch_requests">
    <div class="table-wrap" style="margin-top:12px"><table>
      <thead><tr><th style="width:32px"></th><th>Request<?= ops_admin_help_button('Request key', 'The unique execution request reference. Use it to trace a single item through batching, submission, and publication.') ?></th><th><?= ops_h($partnerLabel) ?></th><th>Class</th><th>Status<?= ops_admin_help_button('Execution request status', 'Shows where the request itself currently sits before or after batching. The key point is whether it is still request-level, already batched, or already published.') ?></th><th>Batch<?= ops_admin_help_button('Batch', 'A batch is the governed processing bundle that groups selected execution requests together. Batch first, then manage quorum and submission at the batch level.') ?></th><th>Submission</th><th>Publication</th><th>Failure</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="9">No execution rows found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td>
            <?php if ($canManage && in_array((string)($row['execution_status'] ?? ''), ['approved','reviewed','drafted'], true) && empty($row['batch_key'])): ?>
              <input type="checkbox" name="execution_request_ids[]" value="<?= (int)($row['execution_request_id'] ?? 0) ?>">
            <?php endif; ?>
          </td>
          <td><?= ops_h($row['request_key'] ?? '') ?></td>
          <td><?= ops_h($row['partner_name'] ?? '') ?></td>
          <td><?= ops_h($row['token_class_name'] ?? ($row['token_class_code'] ?? '')) ?></td>
          <td><?= ops_h($row['execution_status'] ?? '') ?></td>
          <td><?= ops_h($row['batch_key'] ?? '—') ?></td>
          <td><?= ops_h($row['submission_status'] ?? '—') ?></td>
          <td><?= ops_h($row['publication_status'] ?? '—') ?></td>
          <td><?= ops_h($row['failure_status'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($canManage): ?><div class="actions" style="margin-top:12px"><button type="submit">Batch selected requests</button><span class="muted-note">Select approved/reviewed requests that are not already batched.<?= ops_admin_help_button('Batch selected requests', 'Batch groups selected execution requests into one operator bundle. Use it when the selected items should progress through quorum, submission, finality, and publication together. Do not batch unrelated items simply because they are available.') ?></span></div><?php endif; ?>
  </form>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><h2 style="margin:0">Execution batches<?= ops_admin_help_button('Execution batches', 'This is the live control area for execution. Once requests are batched, all major operator actions happen here: open quorum, mark quorum met, mark submitted, mark finalised, and publish.') ?></h2><span class="muted">Operational controls</span></div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>Batch<?= ops_admin_help_button('Batch key', 'The unique reference for the governed processing bundle. Use it to trace the bundle across execution, submission, and publication.') ?></th><th>Status<?= ops_admin_help_button('Batch status', "Shows the batch's current stage in the operator lifecycle. Read this together with Quorum and Submission to understand what can happen next.") ?></th><th>Items</th><th>Quorum<?= ops_admin_help_button('Quorum', 'Open quorum starts the formal sign-off stage. Mark quorum met only when the required operator/signer threshold has actually been satisfied.') ?></th><th>Submission<?= ops_admin_help_button('Submission', 'This shows whether the batch has been formally recorded as submitted and whether it is still provisional or already finalised.') ?></th><th>Actions<?= ops_admin_help_button('Batch actions', 'Use these in order. Batch actions are stage-gated so that you cannot legitimately submit, finalise, or publish a batch out of order.') ?></th></tr></thead>
    <tbody>
      <?php if (!$batchRows): ?><tr><td colspan="6">No execution batches yet.</td></tr><?php endif; ?>
      <?php foreach ($batchRows as $row): ?>
      <tr>
        <td><?= ops_h($row['batch_key'] ?? '') ?></td>
        <td><?= ops_h($row['batch_status'] ?? '') ?></td>
        <td><?= (int)($row['item_count'] ?? 0) ?></td>
        <td><?= ops_h($row['quorum_status'] ?? '—') ?></td>
        <td><?= ops_h($row['submission_status'] ?? '—') ?></td>
        <td>
          <div class="actions">
            <?php if ($canManage && ex_ui_can_open_quorum($row)): ?>
              <form method="post" class="mini-form"><input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>"><input type="hidden" name="action" value="open_quorum"><input type="hidden" name="execution_batch_id" value="<?= (int)$row['id'] ?>"><button class="small-btn" type="submit">Open quorum</button></form>
            <?php endif; ?>
            <?php if ($canManage && ex_ui_can_mark_quorum_met($row)): ?>
              <form method="post" class="mini-form"><input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>"><input type="hidden" name="action" value="mark_quorum_met"><input type="hidden" name="execution_batch_id" value="<?= (int)$row['id'] ?>"><button class="small-btn secondary" type="submit">Mark quorum met</button></form>
            <?php endif; ?>
            <?php if ($canManage && ex_ui_can_submit($row)): ?>
              <form method="post" class="mini-form">
                <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
                <input type="hidden" name="action" value="mark_submitted">
                <input type="hidden" name="execution_batch_id" value="<?= (int)$row['id'] ?>">
                <input class="mini-input" type="text" name="ledger_tx_hash" value="<?= ops_h($row['ledger_tx_hash'] ?? '') ?>" placeholder="ledger tx hash (optional)" title="Optional transaction/reference ID from the ledger or network used when the batch is actually written externally. Leave blank during manual or pre-ledger testing.">
                <button class="small-btn" type="submit">Mark submitted</button>
              </form>
            <?php endif; ?>
            <?php if ($canManage && ex_ui_can_finalise($row)): ?>
              <form method="post" class="mini-form"><input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>"><input type="hidden" name="action" value="mark_finalised"><input type="hidden" name="execution_batch_id" value="<?= (int)$row['id'] ?>"><button class="small-btn secondary" type="submit">Mark finalised</button></form>
            <?php endif; ?>
            <?php if ($canManage && ex_ui_can_publish($row)): ?>
              <form method="post" class="mini-form"><input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>"><input type="hidden" name="action" value="publish_batch"><input type="hidden" name="execution_batch_id" value="<?= (int)$row['id'] ?>"><button class="small-btn" type="submit">Publish</button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><h2 style="margin:0">Legacy execution bridge<?= ops_admin_help_button('Legacy execution bridge', 'This table is diagnostic. It shows how the current execution path maps to older mint queue / handoff records while bridge mode remains enabled. Use it to confirm traceability, not as the primary operator surface.') ?></h2><span class="muted">Read source: v_phase1_legacy_execution_bridge</span></div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>Mint queue</th><th>Legacy queue status</th><th>Execution request</th><th>Execution status</th><th>Batch</th><th>Handoff</th><th>Submission</th></tr></thead>
    <tbody>
      <?php if (!$legacy): ?><tr><td colspan="7">No legacy bridge rows found.</td></tr><?php endif; ?>
      <?php foreach ($legacy as $row): ?>
      <tr>
        <td><?= (int)($row['mint_queue_id'] ?? 0) ?></td>
        <td><?= ops_h($row['queue_status'] ?? '') ?></td>
        <td><?= ops_h($row['request_key'] ?? '—') ?></td>
        <td><?= ops_h($row['execution_status'] ?? '—') ?></td>
        <td><?= ops_h(($row['batch_code'] ?? '') ?: '—') ?></td>
        <td><?= ops_h($row['handoff_status'] ?? '—') ?></td>
        <td><?= ops_h($row['submission_status'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('Execution', 'execution', $body, $flash, $flashType);

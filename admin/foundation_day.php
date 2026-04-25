<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

require_once dirname(__DIR__) . '/_app/api/services/TrusteeCounterpartService.php';
$tcrRecord = TrusteeCounterpartService::getFoundingRecord($pdo);

$canManage = ops_admin_can($pdo, 'admin.full') || ops_admin_can($pdo, 'governance_admin');

function fd_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function fd_rows(PDO $p, string $q, array $params = []): array {
    try { $s = $p->prepare($q); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}
function fd_val(PDO $p, string $q, array $params = []): mixed {
    try { $s = $p->prepare($q); $s->execute($params); return $s->fetchColumn(); }
    catch (Throwable $e) { return null; }
}
function fd_dollars(int $cents): string { return '$' . number_format($cents / 100, 2); }

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (function_exists('admin_csrf_verify')) { admin_csrf_verify(); }
    $action = trim((string)($_POST['action'] ?? ''));
    try {

        // ── Bulk allocate Community COG$ to all Members (1,000 per S-class) ─
        if ($action === 'allocate_community_tokens') {
            $tcRow = fd_val($pdo, "SELECT id FROM token_classes WHERE class_code = 'COM_COG' AND is_active = 1 LIMIT 1");
            if (!$tcRow) throw new RuntimeException('COM_COG token class not found or inactive.');
            $tcId = (int)$tcRow;

            // Get all active personal members via members table
            $partners = fd_rows($pdo,
                "SELECT m.id AS member_id, m.member_number, sm.full_name
                 FROM members m
                 JOIN snft_memberships sm ON sm.member_number = m.member_number
                 WHERE m.member_type = 'personal'
                   AND sm.signup_payment_status = 'paid'
                   AND sm.wallet_status = 'active'
                 ORDER BY m.id ASC"
            );
            if (empty($partners)) throw new RuntimeException('No active paid Members found.');

            $allocated = 0;
            $skipped   = 0;
            $units     = 1000;

            $pdo->beginTransaction();
            try {
                foreach ($partners as $p) {
                    $memberId = (int)$p['member_id'];
                    // Check if already has Community COG$ allocation
                    $existing = fd_val($pdo,
                        "SELECT requested_units FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1",
                        [$memberId, $tcId]
                    );
                    if ($existing !== false && $existing !== null && (int)$existing >= $units) {
                        $skipped++;
                        continue;
                    }
                    if ($existing !== false && $existing !== null) {
                        // Has some but less than 1000 — top up
                        $pdo->prepare(
                            "UPDATE member_reservation_lines
                             SET requested_units = ?, approved_units = ?, paid_units = ?,
                                 approval_status = 'approved', payment_status = 'paid',
                                 updated_at = UTC_TIMESTAMP()
                             WHERE member_id = ? AND token_class_id = ?"
                        )->execute([$units, $units, $units, $memberId, $tcId]);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO member_reservation_lines
                             (member_id, token_class_id, requested_units, approved_units, paid_units,
                              approval_status, payment_status, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, 'approved', 'paid', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
                        )->execute([$memberId, $tcId, $units, $units, $units]);
                    }
                    // Sync snft_memberships.community_tokens and tokens_total.
                    // tokens_total only increments when community_tokens was 0 —
                    // a partial top-up was already counted and must not be double-added.
                    $pdo->prepare(
                        "UPDATE snft_memberships
                         SET community_tokens = ?,
                             tokens_total = tokens_total + CASE WHEN community_tokens = 0 THEN ? ELSE 0 END,
                             updated_at = UTC_TIMESTAMP()
                         WHERE member_number = ? AND community_tokens < ?"
                    )->execute([$units, $units, $p['member_number'], $units]);
                    $allocated++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Record in admin_settings
            ops_setting_set($pdo, 'community_tokens_allocated', 'yes', 'flag',
                "Community COG$ initial allocation run: {$allocated} Members allocated {$units} tokens each. Skipped: {$skipped}.");
            ops_setting_set($pdo, 'community_tokens_allocated_at', date('Y-m-d H:i:s'), 'datetime');

            $flash = "Community COG$ allocated: {$allocated} Members received {$units} tokens. {$skipped} already had allocation.";
            $flashType = 'ok';
        }

        // ── Create the Foundation Day inaugural poll ──────────────────────────
        if ($action === 'create_foundation_poll') {
            if (!function_exists('ops_has_table') || !ops_has_table($pdo, 'community_polls')) {
                throw new RuntimeException('community_polls table not found.');
            }

            $pollTitle   = trim((string)($_POST['poll_title'] ?? ''));
            $pollSummary = trim((string)($_POST['poll_summary'] ?? ''));
            $pollBody    = trim((string)($_POST['poll_body'] ?? ''));
            $deliberHours = max(0, (int)($_POST['deliberation_hours'] ?? 0));
            $voteHours   = max(1, (int)($_POST['vote_hours'] ?? 72));
            $resType     = in_array($_POST['resolution_type'] ?? '', ['ordinary','special','urgent'], true)
                           ? (string)$_POST['resolution_type'] : 'ordinary';

            if ($pollTitle === '') throw new RuntimeException('Poll title is required.');

            $adminId = function_exists('ops_admin_id') ? ops_admin_id() : null;
            $pollKey = 'FOUNDATION-DAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

            $now = date('Y-m-d H:i:s');
            $deliberOpen  = $now;
            $deliberClose = $deliberHours > 0 ? date('Y-m-d H:i:s', strtotime("+{$deliberHours} hours")) : $now;
            $voteOpen     = $deliberClose;
            $voteClose    = date('Y-m-d H:i:s', strtotime($voteOpen . " +{$voteHours} hours"));

            // Quorum: 20 Members or 1% whichever is greater (Declaration cl.36.2)
            $partnerCount = (int)(fd_val($pdo,
                "SELECT COUNT(*) FROM snft_memberships WHERE signup_payment_status = 'paid' AND wallet_status = 'active'"
            ) ?: 0);
            $quorum = max(20, (int)ceil($partnerCount * 0.01));

            $pdo->prepare(
                "INSERT INTO community_polls
                 (poll_key, title, summary, body, resolution_type, eligibility_scope,
                  deliberation_opens_at, deliberation_closes_at, voting_opens_at, voting_closes_at,
                  status, quorum_required_count, created_by_admin_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'personal', ?, ?, ?, ?, 'deliberation', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $pollKey, $pollTitle, $pollSummary ?: null, $pollBody ?: null,
                $resType, $deliberOpen, $deliberClose, $voteOpen, $voteClose,
                $quorum, $adminId,
            ]);

            $pollId = (int)$pdo->lastInsertId();

            // Record in admin_settings
            ops_setting_set($pdo, 'foundation_day_poll_key', $pollKey, 'text',
                "Inaugural Foundation Day poll created. Poll ID: {$pollId}. Key: {$pollKey}.");

            $flash = "Foundation Day poll created: {$pollKey} (ID #{$pollId}). Deliberation opens now. Voting opens in {$deliberHours}h.";
            $flashType = 'ok';
        }

        // ── Set operational flag (AJAX) ───────────────────────────────────────
        if ($action === 'set_flag_direct') {
            $flagKey = trim((string)($_POST['flag_key'] ?? ''));
            $flagVal = trim((string)($_POST['flag_value'] ?? 'yes'));
            $allowed = ['jvpa_founding_signature_recorded', 'key_management_policy_adopted'];
            if (!in_array($flagKey, $allowed, true)) throw new RuntimeException('Invalid flag key.');
            ops_setting_set($pdo, $flagKey, $flagVal, 'flag', 'Set manually by admin via Foundation Day page.');
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }


        if ($action === 'declare_foundation_day') {
            $pollKey = trim((string)($_POST['declaring_poll_key'] ?? ''));
            if ($pollKey === '') throw new RuntimeException('Poll key is required to declare Foundation Day.');

            // Verify poll exists and is closed/declared
            $poll = fd_rows($pdo,
                "SELECT id, poll_key, title, status, audit_hash, voting_closes_at FROM community_polls WHERE poll_key = ? LIMIT 1",
                [$pollKey]
            );
            if (empty($poll)) throw new RuntimeException("Poll {$pollKey} not found.");
            if (!in_array($poll[0]['status'], ['closed','declared','executed'], true)) {
                throw new RuntimeException("Poll {$pollKey} has status '{$poll[0]['status']}' — it must be closed or declared before Foundation Day can be declared.");
            }

            $declarationDate = date('Y-m-d');
            $declarationTs   = date('Y-m-d H:i:s');

            // Generate Foundation Day declaration hash
            $payload = json_encode([
                'event'          => 'GOVERNANCE_FOUNDATION_DAY',
                'date'           => $declarationDate,
                'poll_key'       => $pollKey,
                'poll_id'        => (int)$poll[0]['id'],
                'poll_title'     => $poll[0]['title'],
                'declared_at'    => $declarationTs,
                'declared_by'    => (string)(function_exists('ops_admin_id') ? ops_admin_id() : 'caretaker_trustee'),
                'authority'      => 'Declaration cl.1.7.4 — Caretaker Trustee — transitional operational infrastructure',
            ]);
            $declarationHash = hash('sha256', $payload);

            ops_setting_set($pdo, 'governance_foundation_day_declared', 'yes', 'flag',
                "Foundation Day declared on {$declarationDate}. Poll: {$pollKey}. Hash: {$declarationHash}.");
            ops_setting_set($pdo, 'governance_foundation_day_date', $declarationDate, 'date');
            ops_setting_set($pdo, 'governance_foundation_day_poll_key', $pollKey, 'text');
            ops_setting_set($pdo, 'governance_foundation_day_hash', $declarationHash, 'text',
                "SHA-256 of declaration payload. Independently verifiable.");

            // Mark poll as declared if not already
            $pdo->prepare(
                "UPDATE community_polls SET status = 'declared', audit_hash = ?, updated_at = UTC_TIMESTAMP()
                 WHERE poll_key = ? AND status != 'declared'"
            )->execute([$declarationHash, $pollKey]);

            $flash = "🎉 Governance Foundation Day declared: {$declarationDate}. Declaration hash: " . substr($declarationHash, 0, 16) . "…";
            $flashType = 'ok';
        }

        // ── Open/close a poll ─────────────────────────────────────────────────
        if ($action === 'update_poll_status') {
            $pollId    = (int)($_POST['poll_id'] ?? 0);
            $newStatus = trim((string)($_POST['new_status'] ?? ''));
            $validStatuses = ['draft','deliberation','open','closed','declared','executed','archived'];
            if (!$pollId || !in_array($newStatus, $validStatuses, true)) {
                throw new RuntimeException('Invalid poll ID or status.');
            }
            $pdo->prepare(
                "UPDATE community_polls SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([$newStatus, $pollId]);
            $flash = "Poll #{$pollId} status updated to {$newStatus}.";
            $flashType = 'ok';
        }

    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
    header('Location: ' . admin_url('foundation_day.php') . '?flash=' . urlencode((string)$flash) . '&type=' . $flashType);
    exit;
}

// ── Live readiness checks ─────────────────────────────────────────────────────

// Check 1: All 12 invariants returning zero rows
$invariants = fd_rows($pdo, "SELECT code, name, violation_count FROM v_godley_invariant_status ORDER BY code");
$totalViolations = array_sum(array_column($invariants, 'violation_count'));
$invariantsOk = $totalViolations === 0 && count($invariants) === 12;

// Check 2: Baseline reconciliation snapshot exists
$hasBaseline = (bool)fd_val($pdo, "SELECT COUNT(*) FROM reconciliation_snapshots WHERE snapshot_ref LIKE 'BASELINE%'");

// Check 3: At least 1 paid active S-class Member
$partnerCount = (int)(fd_val($pdo,
    "SELECT COUNT(*) FROM snft_memberships WHERE signup_payment_status = 'paid' AND wallet_status = 'active'"
) ?: 0);
$partnersOk = $partnerCount >= 1;

// Check 4: Stripe webhook / bank payment path produces balanced ledger entries
$ledgerEntryCount = (int)(fd_val($pdo, "SELECT COUNT(*) FROM ledger_entries") ?: 0);
$ledgerOk = $ledgerEntryCount > 0;
$grandBalance = (int)(fd_val($pdo,
    "SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount_cents WHEN entry_type='credit' THEN -amount_cents END), 0) FROM ledger_entries"
) ?: 0);
$ledgerBalanced = $grandBalance === 0;

// Check 5: Community COG$ allocated
$communityAllocated = ops_setting_get($pdo, 'community_tokens_allocated', '') === 'yes';
$communityAllocatedAt = ops_setting_get($pdo, 'community_tokens_allocated_at', '');
$communityCount = (int)(fd_val($pdo,
    "SELECT COUNT(DISTINCT member_id) FROM member_reservation_lines mrl
     JOIN token_classes tc ON tc.id = mrl.token_class_id
     WHERE tc.class_code = 'COM_COG' AND mrl.requested_units >= 1000"
) ?: 0);

// Check 6: Foundation Day inaugural poll created
$foundationPollKey = ops_setting_get($pdo, 'foundation_day_poll_key', '');
$foundationPoll = $foundationPollKey !== ''
    ? fd_rows($pdo, "SELECT * FROM community_polls WHERE poll_key = ? LIMIT 1", [$foundationPollKey])
    : [];
$pollCreated = !empty($foundationPoll);

// Check 7: Foundation Day declared
$fdDeclared     = ops_setting_get($pdo, 'governance_foundation_day_declared', '') === 'yes';
$fdDate         = ops_setting_get($pdo, 'governance_foundation_day_date', '');
$fdPollKey      = ops_setting_get($pdo, 'governance_foundation_day_poll_key', '');
$fdHash         = ops_setting_get($pdo, 'governance_foundation_day_hash', '');

// Check 8: Donation COG$ STC direct transfer path wired
$donationFlowOk = class_exists('AccountingHooks') || file_exists(__DIR__ . '/includes/AccountingHooks.php');

// Check 9: Key Management Policy adopted.
// Auto-detected from TDR-20260425-018 execution status (fully_executed = adopted).
// Falls back to manual admin_settings flag if TDR record is not found.
$kmpFromTdr = (bool)fd_val($pdo,
    "SELECT COUNT(*) FROM trustee_decisions
     WHERE decision_ref = 'TDR-20260425-018' AND status = 'fully_executed' LIMIT 1");
$kmpAdopted = $kmpFromTdr || ops_setting_get($pdo, 'key_management_policy_adopted', '') === 'yes';
if ($kmpFromTdr && ops_setting_get($pdo, 'key_management_policy_adopted', '') !== 'yes') {
    try { ops_setting_set($pdo, 'key_management_policy_adopted', 'yes', 'flag',
        'Auto-set from TDR-20260425-018 fully_executed status.'); } catch (\Throwable $e) {}
}

// Check 10: JVPA executed — verified from cryptographic DB records.
// Passes when: (a) trustee_counterpart_records has a founding TCR, AND
//              (b) evidence_vault_entries has a jvpa_accepted entry for at least one partner.
// No manual flag required — execution is evidenced in the system.
$tcrExists = (bool)fd_val($pdo,
    "SELECT COUNT(*) FROM trustee_counterpart_records WHERE superseded_at IS NULL LIMIT 1");
$jvpaMemberAccepted = (bool)fd_val($pdo,
    "SELECT COUNT(*) FROM evidence_vault_entries WHERE entry_type = 'jvpa_accepted' LIMIT 1");
$jvpaExecuted = $tcrExists && $jvpaMemberAccepted;

// Gather detail for display
$jvpaDetail = '';
if ($jvpaExecuted) {
    $tcrRow = fd_rows($pdo,
        "SELECT trustee_full_name, jvpa_version, jvpa_execution_date, LEFT(record_sha256, 12) AS hash_prefix
         FROM trustee_counterpart_records WHERE superseded_at IS NULL LIMIT 1");
    $memberAcceptRow = fd_rows($pdo,
        "SELECT ev.subject_ref AS member_number, ev.created_at AS accepted_at
         FROM evidence_vault_entries ev
         WHERE ev.entry_type = 'jvpa_accepted'
         ORDER BY ev.created_at ASC LIMIT 1");
    if (!empty($tcrRow)) {
        $tcr = $tcrRow[0];
        $jvpaDetail = 'TCR: ' . ($tcr['trustee_full_name'] ?? '') .
                      ' — ' . ($tcr['jvpa_version'] ?? '') .
                      ' — executed ' . ($tcr['jvpa_execution_date'] ?? '') .
                      ' — hash ' . ($tcr['hash_prefix'] ?? '') . '…';
        if (!empty($memberAcceptRow)) {
            $mar = $memberAcceptRow[0];
            $jvpaDetail .= ' | Member ' . ($mar['member_number'] ?? '') .
                           ' accepted ' . substr((string)($mar['accepted_at'] ?? ''), 0, 10);
        }
    }
} else {
    $jvpaDetail = 'JVPA not yet executed — trustee counterpart record or member acceptance record missing';
}

// All polls for management
$allPolls = fd_rows($pdo, "SELECT id, poll_key, title, status, resolution_type, voting_opens_at, voting_closes_at, quorum_required_count, quorum_reached, created_at FROM community_polls ORDER BY id DESC LIMIT 20");

// Poll vote tallies for closed/declared polls
$pollVotes = [];
if (!empty($allPolls)) {
    foreach ($allPolls as $p) {
        if (in_array($p['status'], ['open','closed','declared','executed'], true)) {
            $tally = fd_rows($pdo,
                "SELECT option_code, SUM(vote_weight) AS weight, COUNT(*) AS count
                 FROM poll_votes WHERE community_poll_id = ? GROUP BY option_code",
                [(int)$p['id']]
            );
            $pollVotes[(int)$p['id']] = $tally;
        }
    }
}

// Readiness summary
$checks = [
    ['label' => 'All 12 Godley invariants clear',         'ok' => $invariantsOk,       'detail' => $invariantsOk ? '12/12 — zero violations' : $totalViolations . ' violation(s) across ' . count($invariants) . ' invariants'],
    ['label' => 'Ledger balanced (grand total = zero)',    'ok' => $ledgerBalanced,     'detail' => $ledgerBalanced ? $ledgerEntryCount . ' entries — balanced ✓' : 'Grand balance: ' . fd_dollars(abs($grandBalance)) . ' imbalance'],
    ['label' => 'Baseline reconciliation snapshot',       'ok' => $hasBaseline,        'detail' => $hasBaseline ? 'BASELINE snapshot recorded' : 'No baseline snapshot found — run Stage 3 close-out'],
    ['label' => 'Active paid Members on platform',       'ok' => $partnersOk,         'detail' => $partnerCount . ' active paid Member(s)'],
    ['label' => 'Community COG$ initial allocation',      'ok' => $communityAllocated, 'detail' => $communityAllocated ? $communityCount . ' Members allocated 1,000 tokens each (' . $communityAllocatedAt . ')' : $communityCount . ' Members allocated — run allocation below'],
    ['label' => 'JVPA executed — Trustee and founding Member', 'ok' => $jvpaExecuted, 'detail' => $jvpaDetail],
    ['label' => 'Key Management Policy adopted',          'ok' => $kmpAdopted,         'detail' => $kmpAdopted ? 'Adopted — TDR-20260425-018 fully executed' : 'Set flag after Caretaker Trustee executes TDR-20260425-018'],
    ['label' => 'Foundation Day inaugural poll created',  'ok' => $pollCreated,        'detail' => $pollCreated ? ($foundationPoll[0]['title'] ?? '') . ' — status: ' . ($foundationPoll[0]['status'] ?? '') : 'Create poll below'],
    ['label' => 'Foundation Day declared',                'ok' => $fdDeclared,         'detail' => $fdDeclared ? "Declared {$fdDate} — poll {$fdPollKey} — hash " . substr($fdHash, 0, 12) . '…' : 'Declare after poll is closed'],
];

$readyCount = count(array_filter($checks, fn($c) => $c['ok']));
$totalChecks = count($checks);
$allReady = $readyCount === $totalChecks;

$csrfToken = function_exists('admin_csrf_token') ? admin_csrf_token() : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Foundation Day Readiness | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>.main { padding:24px 28px; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; max-width:580px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); cursor:pointer; }
.btn-gold { background:rgba(212,178,92,.15); border-color:rgba(212,178,92,.3); color:var(--gold); }
.btn-sm { padding:5px 12px; font-size:12px; border-radius:8px; }
.btn-declare { background:rgba(82,184,122,.15); border-color:rgba(82,184,122,.3); color:var(--ok); font-size:14px; padding:12px 24px; }
.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:22px; }
@media(max-width:980px) { .grid2,.grid3 { grid-template-columns:1fr; } }
table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:9px 10px; font-size:13px; border-top:1px solid var(--line); }
th { color:var(--dim); font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-top:none; }
.mono { font-family:monospace; font-size:11.5px; }
.st { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:700; text-transform:uppercase; }
.st-ok { background:var(--okb); color:var(--ok); }
.st-warn { background:var(--warnb); color:var(--warn); }
.st-err { background:var(--errb); color:var(--err); }
.st-dim { background:rgba(255,255,255,.04); color:var(--dim); }
.alert { padding:12px 16px; border-radius:var(--r2); margin-bottom:18px; font-size:13px; font-weight:600; }
.alert-ok  { background:var(--okb);   border:1px solid rgba(82,184,122,.3);  color:#a0e8b8; }
.alert-err { background:var(--errb);  border:1px solid rgba(196,96,96,.3);   color:#f0a0a0; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11.5px; font-weight:700; color:var(--sub); text-transform:uppercase; letter-spacing:.05em; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; background:var(--panel); border:1px solid var(--line2); border-radius:8px; color:var(--text); font-size:13px; padding:8px 12px; font-family:inherit; }
.form-group textarea { min-height:80px; resize:vertical; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:1px solid rgba(212,178,92,.35); }
.empty { color:var(--dim); font-size:13px; padding:20px 0; text-align:center; }

/* Readiness checklist */
.checklist { display:flex; flex-direction:column; gap:0; }
.check-row { display:flex; align-items:flex-start; gap:12px; padding:11px 16px; border-bottom:1px solid var(--line); }
.check-row:last-child { border-bottom:none; }
.check-icon { width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; margin-top:1px; }
.check-icon.ok  { background:var(--okb); color:var(--ok); border:1px solid rgba(82,184,122,.3); }
.check-icon.err { background:var(--errb); color:var(--err); border:1px solid rgba(196,96,96,.3); }
.check-label { font-size:13px; font-weight:600; }
.check-detail { font-size:11.5px; color:var(--sub); margin-top:2px; }

/* Progress bar */
.progress-bar { height:8px; background:rgba(255,255,255,.06); border-radius:4px; overflow:hidden; margin:12px 0 4px; }
.progress-fill { height:100%; border-radius:4px; transition:width .3s; }

/* Declaration banner */
.declared-banner { padding:18px 22px; border-radius:var(--r); background:linear-gradient(135deg,rgba(82,184,122,.12),rgba(212,178,92,.08)); border:1px solid rgba(82,184,122,.3); margin-bottom:18px; }
.declared-banner h2 { font-size:1.3rem; font-weight:800; color:var(--ok); margin-bottom:6px; }
.declared-banner .hash { font-family:monospace; font-size:11px; color:var(--sub); word-break:break-all; margin-top:8px; }

/* Poll status badges */
.poll-status { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:700; text-transform:uppercase; }
.ps-draft       { background:rgba(255,255,255,.05); color:var(--dim); }
.ps-deliberation{ background:var(--warnb); color:var(--warn); }
.ps-open        { background:var(--okb); color:var(--ok); }
.ps-closed      { background:rgba(255,255,255,.05); color:var(--sub); }
.ps-declared    { background:linear-gradient(135deg,var(--okb),rgba(212,178,92,.1)); color:var(--ok); border:1px solid rgba(82,184,122,.2); }
.ps-executed    { background:rgba(90,158,212,.1); color:var(--blue); }
.ps-archived    { background:rgba(255,255,255,.03); color:var(--dim); }

/* Flag setter */
.flag-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--line); }
.flag-row:last-child { border-bottom:none; }
.flag-label { flex:1; font-size:12.5px; }
.flag-status { font-size:11px; font-weight:700; }
.flag-status.set { color:var(--ok); }
.flag-status.unset { color:var(--dim); }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('foundation_day'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Governance Foundation Day<?php echo ops_admin_help_button('Governance Foundation Day', 'Phase 7 of the Godley Accounting Specification — the final readiness checklist before Foundation Day (14 May 2026). Foundation Day is defined as the date on which the Foundation conducts its first online cryptographic member poll. Declaration cl.1.7.4 — Caretaker Trustee authority.'); ?></h1>
    <p>Gate 2 readiness checklist — Godley Spec §7 Phase 7. Target date: <strong style="color:var(--gold)">14 May 2026</strong>.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="<?php echo fd_h(admin_url('accounting.php')); ?>">Accounting</a>
    <a class="btn" href="<?php echo fd_h(admin_url('governance.php')); ?>">Governance</a>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="alert alert-<?php echo $flashType === 'ok' ? 'ok' : 'err'; ?>"><?php echo fd_h($flash); ?></div>
<?php endif; ?>

<?php if (!$tcrRecord): ?>
<!-- ── Trustee Counterpart Record — NOT YET GENERATED ─────────────────── -->
<div style="background:rgba(192,85,58,.1);border:2px solid rgba(192,85,58,.4);border-radius:10px;padding:18px 24px;margin-bottom:22px;">
  <strong style="color:var(--err);font-size:.95rem;">
    ⚠ FOUNDING TRUSTEE COUNTERPART RECORD NOT YET GENERATED — JVPA NOT YET IN FORCE
  </strong>
  <p style="color:var(--sub);font-size:.82rem;margin:8px 0 0;">
    The Joint Venture Participation Agreement enters into force only upon generation of both
    the founding Member's cryptographic acceptance record (clause 8.1A) and the Trustee
    Counterpart Record (clause 10.10A). Neither has yet been generated.
    <a href="<?php echo fd_h(admin_url('generate_trustee_token.php')); ?>" style="color:var(--gold);margin-left:8px;">
      → Generate Trustee acceptance token
    </a>
  </p>
</div>
<?php else: ?>
<!-- ── Trustee Counterpart Record — GENERATED ─────────────────────────── -->
<div class="card" style="border-color:rgba(82,184,122,.3);margin-bottom:22px;">
  <div class="card-head">
    <h2>✓ Founding Trustee Counterpart Record</h2>
    <span style="font-size:.78rem;color:var(--ok);font-weight:700;">JVPA IN FORCE ON FIRST MEMBER ACCEPTANCE</span>
  </div>
  <div class="card-body" style="display:grid;grid-template-columns:180px 1fr;gap:8px 16px;font-size:.82rem;padding:16px 20px;">
    <span style="color:var(--dim)">Record ID</span>
    <span style="font-family:monospace;word-break:break-all;color:var(--text)"><?php echo fd_h($tcrRecord['record_id']); ?></span>
    <span style="color:var(--dim)">UTC Timestamp</span>
    <span style="font-family:monospace;color:var(--text)"><?php echo fd_h($tcrRecord['acceptance_timestamp_utc']); ?></span>
    <span style="color:var(--dim)">JVPA Version</span>
    <span style="color:var(--text)"><?php echo fd_h($tcrRecord['jvpa_version']); ?> — <?php echo fd_h($tcrRecord['jvpa_title']); ?></span>
    <span style="color:var(--dim)">JVPA Execution Date</span>
    <span style="color:var(--text)"><?php echo fd_h($tcrRecord['jvpa_execution_date']); ?></span>
    <span style="color:var(--dim)">JVPA SHA-256</span>
    <span style="font-family:monospace;word-break:break-all;color:var(--gold)"><?php echo fd_h($tcrRecord['jvpa_sha256']); ?></span>
    <span style="color:var(--dim)">Record SHA-256</span>
    <span style="font-family:monospace;word-break:break-all;color:var(--gold)"><?php echo fd_h($tcrRecord['record_sha256']); ?></span>
    <span style="color:var(--dim)">On-Chain Ref</span>
    <span style="font-family:monospace;color:var(--text)"><?php echo fd_h((string)$tcrRecord['onchain_commitment_txid']); ?> (transitional — evidence vault)</span>
    <span style="color:var(--dim)">Capacity Type</span>
    <span style="color:var(--text)">Founding Caretaker Trustee</span>
    <span style="color:var(--dim)">Status</span>
    <span style="color:var(--ok)">Active — cannot be altered or deleted per JVPA cl.10.10A(f)</span>
  </div>
</div>
<?php endif; ?>


<?php if ($fdDeclared): ?>
<!-- ── Foundation Day declared banner ─────────────────────────────────── -->
<div class="declared-banner">
  <h2>🎉 Governance Foundation Day — <?php echo fd_h($fdDate); ?></h2>
  <p style="color:var(--sub);font-size:13px">First cryptographic member poll: <strong style="color:var(--text)"><?php echo fd_h($fdPollKey); ?></strong> — Gate 2 satisfied — Tier 1 classes (kS, D, P, Lr, C) now available for issuance.</p>
  <div class="hash">Declaration SHA-256: <?php echo fd_h($fdHash); ?></div>
</div>
<?php endif; ?>

<!-- ── Readiness summary ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h2>Gate 2 readiness — <?php echo $readyCount; ?> / <?php echo $totalChecks; ?> checks passed</h2>
    <span style="font-size:12px;color:<?php echo $allReady ? 'var(--ok)' : 'var(--warn)'; ?>;font-weight:700">
      <?php echo $allReady ? '✓ Ready to declare' : ($readyCount . ' of ' . $totalChecks . ' complete'); ?>
    </span>
  </div>
  <div style="padding:0 16px 4px">
    <div class="progress-bar">
      <div class="progress-fill" style="width:<?php echo round(($readyCount / $totalChecks) * 100); ?>%;background:<?php echo $allReady ? 'var(--ok)' : 'var(--gold)'; ?>"></div>
    </div>
    <div style="font-size:11px;color:var(--dim);margin-bottom:10px;text-align:right"><?php echo round(($readyCount / $totalChecks) * 100); ?>%</div>
  </div>
  <div class="checklist">
    <?php foreach ($checks as $check): ?>
    <div class="check-row">
      <div class="check-icon <?php echo $check['ok'] ? 'ok' : 'err'; ?>"><?php echo $check['ok'] ? '✓' : '✗'; ?></div>
      <div>
        <div class="check-label"><?php echo fd_h($check['label']); ?></div>
        <div class="check-detail"><?php echo fd_h($check['detail']); ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid2">
<div>

  <!-- Invariant strip -->
  <?php if (!empty($invariants)): ?>
  <div class="card">
    <div class="card-head">
      <h2>Invariant status (I1–I12)</h2>
      <span style="font-size:12px;color:<?php echo $invariantsOk ? 'var(--ok)' : 'var(--err)'; ?>;font-weight:700">
        <?php echo $invariantsOk ? '✓ All clear' : $totalViolations . ' violation(s)'; ?>
      </span>
    </div>
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:7px">
      <?php foreach ($invariants as $inv):
        $viol = (int)$inv['violation_count'];
        $cls  = $viol === 0 ? 'st-ok' : 'st-err';
      ?>
        <span class="st <?php echo $cls; ?>" title="<?php echo fd_h($inv['name']); ?>"><?php echo fd_h($inv['code']); ?><?php if ($viol > 0): ?> (<?php echo $viol; ?>)<?php endif; ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Community COG$ allocation -->
  <div class="card">
    <div class="card-head">
      <h2>Community COG$ initial allocation<?php echo ops_admin_help_button('Community COG$ allocation', 'Declaration cl.23D.3 — initial allocation of 1,000 Community COG$ per Individual Member from Governance Foundation Day by standing Members Poll direction. Must be run before Foundation Day is declared.'); ?></h2>
      <span class="st <?php echo $communityAllocated ? 'st-ok' : 'st-warn'; ?>"><?php echo $communityAllocated ? 'Done' : 'Pending'; ?></span>
    </div>
    <div class="card-body">
      <div style="display:flex;gap:20px;margin-bottom:14px">
        <div>
          <div style="font-size:1.4rem;font-weight:800;color:<?php echo $communityAllocated ? 'var(--ok)' : 'var(--gold)'; ?>"><?php echo $communityCount; ?></div>
          <div style="font-size:.72rem;color:var(--sub);text-transform:uppercase;letter-spacing:.05em;margin-top:2px">Members allocated</div>
        </div>
        <div>
          <div style="font-size:1.4rem;font-weight:800;color:var(--blue)"><?php echo $partnerCount; ?></div>
          <div style="font-size:.72rem;color:var(--sub);text-transform:uppercase;letter-spacing:.05em;margin-top:2px">Total active Members</div>
        </div>
      </div>
      <?php if ($communityAllocated && $communityCount >= $partnerCount): ?>
        <p style="font-size:12px;color:var(--ok)">✓ All Members have received 1,000 Community COG$ tokens.</p>
      <?php elseif ($canManage): ?>
        <p style="font-size:12px;color:var(--sub);margin-bottom:12px">Allocates 1,000 Community COG$ to every active paid Member. Idempotent — safe to re-run. Members already at 1,000 are skipped.</p>
        <form method="post">
          <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo fd_h($csrfToken); ?>"><?php endif; ?>
          <input type="hidden" name="action" value="allocate_community_tokens">
          <button type="submit" class="btn btn-gold">Allocate 1,000 Community COG$ to all Members</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Operational flags -->
  <div class="card">
    <div class="card-head"><h2>Operational flags<?php echo ops_admin_help_button('Operational flags', 'The JVPA execution status is now auto-detected from cryptographic records (Trustee Counterpart Record + evidence_vault_entries jvpa_accepted). The Key Management Policy flag requires manual confirmation after Board adoption.'); ?></h2></div>
    <div class="card-body">
      <?php
      $flags = [
          ['key' => 'key_management_policy_adopted', 'label' => 'Key Management Policy adopted by Board', 'note' => 'Required before Foundation Day per Declaration cl.35.'],
      ];
      ?>
      <!-- JVPA execution — auto-detected from cryptographic records -->
      <div class="flag-row">
        <div class="flag-label">
          <div style="font-weight:600;font-size:12.5px">JVPA executed — Trustee and founding Member</div>
          <div style="font-size:11px;color:var(--dim)">
            Auto-detected from Trustee Counterpart Record and evidence vault (<code>jvpa_accepted</code>).
            No manual flag required.
          </div>
        </div>
        <?php if ($jvpaExecuted): ?>
          <span class="flag-status set">✓ Executed</span>
          <span style="font-size:11px;color:var(--sub);margin-left:8px;"><?php echo fd_h($jvpaDetail); ?></span>
        <?php else: ?>
          <span class="flag-status unset">— Not yet executed</span>
          <span style="font-size:11px;color:var(--dim);margin-left:8px;">TCR or member acceptance record missing</span>
        <?php endif; ?>
      </div>

      <?php foreach ($flags as $flag):
        $isSet = ops_setting_get($pdo, $flag['key'], '') === 'yes';
      ?>
      <div class="flag-row">
        <div class="flag-label">
          <div style="font-weight:600;font-size:12.5px"><?php echo fd_h($flag['label']); ?></div>
          <div style="font-size:11px;color:var(--dim)"><?php echo fd_h($flag['note']); ?></div>
        </div>
        <span class="flag-status <?php echo $isSet ? 'set' : 'unset'; ?>"><?php echo $isSet ? '✓ Set' : '— Not set'; ?></span>
        <?php if ($canManage && !$isSet): ?>
          <form method="post" style="display:inline">
            <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo fd_h($csrfToken); ?>"><?php endif; ?>
            <input type="hidden" name="action" value="update_poll_status">
            <!-- Reuse a lightweight approach via admin_settings directly -->
            <button type="button" class="btn btn-sm btn-gold"
              onclick="setFlag('<?php echo fd_h($flag['key']); ?>', this)">Mark done</button>
          </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>
<div>

  <!-- Foundation Day poll -->
  <div class="card">
    <div class="card-head">
      <h2>Foundation Day inaugural poll<?php echo ops_admin_help_button('Inaugural poll', 'This is the first online cryptographic member poll that defines Governance Foundation Day. Every vote receives a SHA-256 receipt hash. Declaration cl.1.7.4 — the transitional operational infrastructure with SHA-256 hash records is the operational control system from the date of execution.'); ?></h2>
      <span class="st <?php echo $pollCreated ? 'st-ok' : 'st-warn'; ?>"><?php echo $pollCreated ? 'Created' : 'Not yet created'; ?></span>
    </div>
    <div class="card-body">
      <?php if ($pollCreated && !empty($foundationPoll)): ?>
        <?php $fp = $foundationPoll[0]; ?>
        <div style="margin-bottom:14px">
          <div style="font-weight:700;margin-bottom:4px"><?php echo fd_h($fp['title']); ?></div>
          <div style="font-size:12px;color:var(--sub);margin-bottom:8px">Key: <span class="mono"><?php echo fd_h($fp['poll_key']); ?></span></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <span class="poll-status ps-<?php echo fd_h($fp['status']); ?>"><?php echo fd_h($fp['status']); ?></span>
            <span class="st st-dim"><?php echo fd_h($fp['resolution_type']); ?></span>
            <?php if ($fp['quorum_reached']): ?><span class="st st-ok">Quorum reached</span><?php endif; ?>
          </div>
          <div style="font-size:11.5px;color:var(--sub)">
            Voting: <?php echo fd_h($fp['voting_opens_at'] ?? '—'); ?> → <?php echo fd_h($fp['voting_closes_at'] ?? '—'); ?><br>
            Quorum required: <?php echo (int)$fp['quorum_required_count']; ?> Members
          </div>
          <?php $fpVotes = $pollVotes[(int)$fp['id']] ?? []; ?>
          <?php if (!empty($fpVotes)): ?>
          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--dim);letter-spacing:.05em;margin-bottom:6px">Vote tally</div>
            <?php foreach ($fpVotes as $v): ?>
              <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0">
                <span class="mono"><?php echo fd_h($v['option_code']); ?></span>
                <span style="font-weight:700"><?php echo (int)$v['count']; ?> vote<?php echo (int)$v['count'] !== 1 ? 's' : ''; ?> (weight: <?php echo number_format((float)$v['weight'], 0); ?>)</span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($canManage && !$fdDeclared): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
            <?php $nextStatuses = ['draft'=>['deliberation','open'],'deliberation'=>['open','closed'],'open'=>['closed'],'closed'=>['declared']]; ?>
            <?php foreach ($nextStatuses[$fp['status']] ?? [] as $ns): ?>
              <form method="post" style="display:inline">
                <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo fd_h($csrfToken); ?>"><?php endif; ?>
                <input type="hidden" name="action" value="update_poll_status">
                <input type="hidden" name="poll_id" value="<?php echo (int)$fp['id']; ?>">
                <input type="hidden" name="new_status" value="<?php echo fd_h($ns); ?>">
                <button type="submit" class="btn btn-sm <?php echo $ns === 'open' ? 'btn-gold' : ''; ?>">→ <?php echo ucfirst($ns); ?></button>
              </form>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php elseif ($canManage): ?>
        <p style="font-size:12px;color:var(--sub);margin-bottom:14px">Create the inaugural Members Poll. This is the first online cryptographic member poll — the act that defines Governance Foundation Day. Each vote will be recorded with a SHA-256 receipt hash.</p>
        <form method="post">
          <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo fd_h($csrfToken); ?>"><?php endif; ?>
          <input type="hidden" name="action" value="create_foundation_poll">
          <div class="form-group"><label>Poll title</label><input type="text" name="poll_title" required value="COGs of Australia Foundation — Inaugural Governance Poll — Governance Foundation Day" autocomplete="off"></div>
          <div class="form-group"><label>Summary</label><input type="text" name="poll_summary" value="The Foundation's first online cryptographic member poll. A vote on this poll constitutes the Governance Foundation Day event under the Declaration." autocomplete="off"></div>
          <div class="form-group"><label>Poll body (optional)</label><textarea name="poll_body" rows="3" placeholder="Describe the matter being put to Members…"></textarea></div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
            <div class="form-group" style="margin:0"><label>Deliberation (hours)</label><input type="number" name="deliberation_hours" value="0" min="0" max="168"></div>
            <div class="form-group" style="margin:0"><label>Voting period (hours)</label><input type="number" name="vote_hours" value="72" min="1" max="336"></div>
            <div class="form-group" style="margin:0"><label>Resolution type</label><select name="resolution_type"><option value="ordinary">Ordinary</option><option value="special">Special (75%)</option><option value="urgent">Urgent</option></select></div>
          </div>
          <button type="submit" class="btn btn-gold">Create inaugural poll</button>
        </form>
      <?php else: ?>
        <p class="empty">No Foundation Day poll created yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Declare Foundation Day -->
  <?php if ($pollCreated && !$fdDeclared && $canManage):
    $fp = $foundationPoll[0] ?? [];
    $pollCloseable = in_array($fp['status'] ?? '', ['closed','declared','executed'], true);
  ?>
  <div class="card" style="border-color:rgba(82,184,122,.25)">
    <div class="card-head" style="background:rgba(82,184,122,.04)">
      <h2 style="color:var(--ok)">Declare Governance Foundation Day<?php echo ops_admin_help_button('Declare Foundation Day', 'This action records the Foundation Day declaration in admin_settings with a SHA-256 hash. The poll must be closed first. This is an irreversible operational milestone.'); ?></h2>
    </div>
    <div class="card-body">
      <?php if (!$pollCloseable): ?>
        <p style="font-size:12px;color:var(--warn)">⚠ Poll must be closed before Foundation Day can be declared. Current status: <strong><?php echo fd_h($fp['status'] ?? '—'); ?></strong></p>
      <?php else: ?>
        <p style="font-size:12px;color:var(--sub);margin-bottom:14px">This records the Governance Foundation Day declaration with a SHA-256 hash, marks the poll as declared, and enables Gate 2 Tier 1 token classes (kS, D, P, Lr, C). This action is irreversible.</p>
        <form method="post">
          <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo fd_h($csrfToken); ?>"><?php endif; ?>
          <input type="hidden" name="action" value="declare_foundation_day">
          <input type="hidden" name="declaring_poll_key" value="<?php echo fd_h($fp['poll_key'] ?? ''); ?>">
          <button type="submit" class="btn btn-declare" onclick="return confirm('Declare Governance Foundation Day using poll <?php echo fd_h($fp['poll_key'] ?? ''); ?>? This is irreversible.')">
            🎉 Declare Governance Foundation Day
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- All polls -->
  <?php if (!empty($allPolls)): ?>
  <div class="card">
    <div class="card-head"><h2>All community polls</h2><span style="font-size:12px;color:var(--dim)"><?php echo count($allPolls); ?></span></div>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Key</th><th>Title</th><th>Status</th><th>Voting closes</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($allPolls as $ap): ?>
          <tr>
            <td class="mono" style="font-size:10.5px"><?php echo fd_h($ap['poll_key']); ?></td>
            <td style="font-size:12px"><?php echo fd_h($ap['title']); ?></td>
            <td><span class="poll-status ps-<?php echo fd_h($ap['status']); ?>"><?php echo fd_h($ap['status']); ?></span></td>
            <td style="font-size:11px;color:var(--sub)"><?php echo $ap['voting_closes_at'] ? fd_h($ap['voting_closes_at']) : '—'; ?></td>
            <?php if ($canManage): ?>
            <td>
              <?php $nextMap = ['draft'=>'deliberation','deliberation'=>'open','open'=>'closed','closed'=>'declared']; ?>
              <?php $next = $nextMap[$ap['status']] ?? null; ?>
              <?php if ($next && !$fdDeclared): ?>
                <form method="post" style="display:inline">
                  <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo fd_h($csrfToken); ?>"><?php endif; ?>
                  <input type="hidden" name="action" value="update_poll_status">
                  <input type="hidden" name="poll_id" value="<?php echo (int)$ap['id']; ?>">
                  <input type="hidden" name="new_status" value="<?php echo fd_h($next); ?>">
                  <button type="submit" class="btn btn-sm">→ <?php echo ucfirst($next); ?></button>
                </form>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>
</div>

</main>
</div>

<script>
function setFlag(key, btn) {
  if (!confirm('Mark "' + key + '" as done? This records that the off-system step has been completed.')) return;
  btn.disabled = true;
  btn.textContent = 'Saving…';
  fetch(window.location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'set_flag_direct',
      flag_key: key,
      flag_value: 'yes',
      <?php if ($csrfToken): ?>csrf_token: '<?php echo fd_h($csrfToken); ?>'<?php endif; ?>
    })
  }).then(function() {
    window.location.reload();
  }).catch(function() {
    btn.disabled = false;
    btn.textContent = 'Mark done';
  });
}
</script>
</body>
</html>

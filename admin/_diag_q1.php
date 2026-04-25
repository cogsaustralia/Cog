<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/integrations/mailer.php';
require_once __DIR__ . '/../_app/api/services/TrusteeDecisionService.php';

ops_require_admin();
$pdo = ops_db();

function td_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ── Actions ───────────────────────────────────────────────────────────────────
$action  = trim((string)($_GET['action'] ?? ''));
$id      = trim((string)($_GET['id']     ?? ''));
$message = '';
$error   = '';

// POST: create draft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create_draft') {
    try {
        $powers = [];
        $clauses = $_POST['clause_ref']  ?? [];
        $descs   = $_POST['clause_desc'] ?? [];
        foreach ($clauses as $i => $cref) {
            $cref = trim($cref);
            $desc = trim($descs[$i] ?? '');
            if ($cref !== '' && $desc !== '') {
                $powers[] = ['clause_ref' => $cref, 'description' => $desc];
            }
        }
        if (empty($powers)) {
            throw new \RuntimeException('At least one power / clause reference is required.');
        }
        $data = [
            'sub_trust_context'           => $_POST['sub_trust_context'] ?? '',
            'decision_category'           => $_POST['decision_category'] ?? '',
            'title'                       => trim($_POST['title'] ?? ''),
            'effective_date'              => trim($_POST['effective_date'] ?? ''),
            'powers'                      => $powers,
            'background_md'               => trim($_POST['background_md']         ?? ''),
            'fnac_consideration_md'       => trim($_POST['fnac_consideration_md'] ?? ''),
            'fpic_consideration_md'       => trim($_POST['fpic_consideration_md'] ?? ''),
            'cultural_heritage_md'        => trim($_POST['cultural_heritage_md']  ?? ''),
            'resolution_md'               => trim($_POST['resolution_md']         ?? ''),
            'fnac_consulted'              => !empty($_POST['fnac_consulted']),
            'fnac_evidence_ref'           => trim($_POST['fnac_evidence_ref']          ?? ''),
            'fpic_obtained'               => !empty($_POST['fpic_obtained']),
            'fpic_evidence_ref'           => trim($_POST['fpic_evidence_ref']          ?? ''),
            'cultural_heritage_assessed'  => !empty($_POST['cultural_heritage_assessed']),
            'cultural_heritage_ref'       => trim($_POST['cultural_heritage_ref']      ?? ''),
        ];
        foreach (['sub_trust_context','decision_category','title','effective_date','resolution_md'] as $req) {
            if (($data[$req] ?? '') === '') {
                throw new \RuntimeException("Field '{$req}' is required.");
            }
        }
        $newUuid = TrusteeDecisionService::createDraft($pdo, $data, null);
        header('Location: ./trustee_decisions.php?id=' . urlencode($newUuid) . '&msg=created');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'create';
    }
}

// POST: update existing draft/pending record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_draft') {
    $uuid = trim($_POST['decision_uuid'] ?? '');
    try {
        $powers = [];
        $clauses = $_POST['clause_ref']  ?? [];
        $descs   = $_POST['clause_desc'] ?? [];
        foreach ($clauses as $i => $cref) {
            $cref = trim($cref);
            $desc = trim($descs[$i] ?? '');
            if ($cref !== '' && $desc !== '') {
                $powers[] = ['clause_ref' => $cref, 'description' => $desc];
            }
        }
        if (empty($powers)) {
            throw new \RuntimeException('At least one power / clause reference is required.');
        }
        $data = [
            'sub_trust_context'           => $_POST['sub_trust_context'] ?? '',
            'decision_category'           => $_POST['decision_category'] ?? '',
            'title'                       => trim($_POST['title'] ?? ''),
            'effective_date'              => trim($_POST['effective_date'] ?? ''),
            'powers'                      => $powers,
            'background_md'               => trim($_POST['background_md']         ?? ''),
            'fnac_consideration_md'       => trim($_POST['fnac_consideration_md'] ?? ''),
            'fpic_consideration_md'       => trim($_POST['fpic_consideration_md'] ?? ''),
            'cultural_heritage_md'        => trim($_POST['cultural_heritage_md']  ?? ''),
            'resolution_md'               => trim($_POST['resolution_md']         ?? ''),
            'fnac_consulted'              => !empty($_POST['fnac_consulted']),
            'fnac_evidence_ref'           => trim($_POST['fnac_evidence_ref']          ?? ''),
            'fpic_obtained'               => !empty($_POST['fpic_obtained']),
            'fpic_evidence_ref'           => trim($_POST['fpic_evidence_ref']          ?? ''),
            'cultural_heritage_assessed'  => !empty($_POST['cultural_heritage_assessed']),
            'cultural_heritage_ref'       => trim($_POST['cultural_heritage_ref']      ?? ''),
        ];
        foreach (['sub_trust_context','decision_category','title','effective_date','resolution_md'] as $req) {
            if (($data[$req] ?? '') === '') {
                throw new \RuntimeException("Field '{$req}' is required.");
            }
        }
        TrusteeDecisionService::updateDraft($pdo, $uuid, $data);
        header('Location: ./trustee_decisions.php?id=' . urlencode($uuid) . '&msg=updated');
        exit;
    } catch (\Throwable $e) {
        $error  = $e->getMessage();
        $action = 'edit';
        $id     = $uuid;
        // Re-load decision so edit form can pre-fill
        $decision = TrusteeDecisionService::getDecision($pdo, $uuid);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'issue_token') {
    $uuid = trim($_POST['decision_uuid'] ?? '');
    try {
        $decision = TrusteeDecisionService::getDecision($pdo, $uuid);
        if (!$decision) throw new \RuntimeException('TDR not found.');
        $raw   = TrusteeDecisionService::issueExecutionToken($pdo, $uuid);
        $email = TrusteeDecisionService::getTrusteeEmail($pdo, $decision['sub_trust_context']);
        $link  = 'https://cogsaustralia.org/execute_tdr.php?token=' . urlencode($raw);

        $subj     = '[COG$] Execute Trustee Decision Record — ' . $decision['decision_ref'];
        $scLabel  = $subTrustLabels[$decision['sub_trust_context']] ?? strtoupper(str_replace('_', '-', $decision['sub_trust_context']));
        $htmlBody = '<p>Trustee Decision Record Execution</p>'
            . '<p><strong>Reference:</strong> ' . htmlspecialchars($decision['decision_ref'], ENT_QUOTES) . '<br>'
            . '<strong>Title:</strong> ' . htmlspecialchars($decision['title'], ENT_QUOTES) . '<br>'
            . '<strong>Sub-Committee:</strong> ' . htmlspecialchars($scLabel, ENT_QUOTES) . '</p>'
            . '<p>Your one-time execution link (valid 15 minutes):</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . htmlspecialchars($link, ENT_QUOTES) . '</a></p>'
            . '<p>This link is single-use. Do not forward it.</p>';
        $textBody = "Trustee Decision Record Execution\n\n"
            . "Reference: {$decision['decision_ref']}\n"
            . "Title: {$decision['title']}\n"
            . "Sub-Committee: {$scLabel}\n\n"
            . "Your one-time execution link (valid 15 minutes):\n{$link}\n\n"
            . "This link is single-use. Do not forward it.";

        if (mailerEnabled()) {
            smtpSendEmail($email, $subj, $htmlBody, $textBody);
            $redirectMsg = 'Execution token issued and emailed to ' . $email;
        } else {
            // SMTP not configured — log and surface link to admin via redirect message
            error_log('TDR token mailer disabled — link for ' . $decision['decision_ref'] . ': ' . $link);
            $redirectMsg = 'SMTP_FALLBACK:' . $link;
        }
        header('Location: ./trustee_decisions.php?id=' . urlencode($uuid) . '&msg=' . urlencode($redirectMsg));
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $id = $uuid;
    }
}

// GET: incoming message
$smtpFallbackLink = '';
if (isset($_GET['msg'])) {
    $rawMsg = urldecode((string)$_GET['msg']);
    if (str_starts_with($rawMsg, 'SMTP_FALLBACK:')) {
        $smtpFallbackLink = substr($rawMsg, strlen('SMTP_FALLBACK:'));
        $message = 'Token issued. SMTP is not configured — copy the execution link below and send it directly to the trustee.';
    } else {
        $message = td_h($rawMsg);
    }
}

// ── Data load ─────────────────────────────────────────────────────────────────
$decision    = null;
$execRecords = [];
$attachments = [];

if ($id !== '') {
    $decision = TrusteeDecisionService::getDecision($pdo, $id);
    if (!$decision) {
        // Try by ref
        $decision = TrusteeDecisionService::getDecisionByRef($pdo, $id);
    }
    if ($decision) {
        $execRecords = TrusteeDecisionService::getExecutionRecords($pdo, $decision['decision_uuid']);
        $attachments = TrusteeDecisionService::getAttachments($pdo, $decision['decision_uuid']);
    }
}

$listFilters = [
    'sub_trust_context'  => trim((string)($_GET['sub_trust']  ?? '')),
    'decision_category'  => trim((string)($_GET['category']   ?? '')),
    'status'             => trim((string)($_GET['status']      ?? '')),
    'date_from'          => trim((string)($_GET['date_from']   ?? '')),
    'date_to'            => trim((string)($_GET['date_to']     ?? '')),
];
$decisions = TrusteeDecisionService::listDecisions(
    $pdo,
    $listFilters['sub_trust_context']  ?: null,
    $listFilters['decision_category']  ?: null,
    $listFilters['status']             ?: null,
    $listFilters['date_from']          ?: null,
    $listFilters['date_to']            ?: null,
    100, 0
);

$categoryLabels = [
    'bank_account'                  => 'Bank Account',
    'investment_instruction'        => 'Investment Instruction',
    'distribution'                  => 'Distribution',
    'operational_amendment'         => 'Operational Amendment',
    'regulatory_compliance'         => 'Regulatory Compliance',
    'fnac_engagement'               => 'FNAC Engagement',
    'member_poll_implementation'    => 'Poll Implementation',
    'fiduciary_conflict_invocation' => 'Fiduciary Conflict',
    'record_keeping'                => 'Record Keeping',
    'governance_instrument'         => 'Governance Instrument',
    'other'                         => 'Other',
];
$subTrustLabels = [
    'sub_trust_a' => 'STA — Operations, Financial & Technical',
    'sub_trust_b' => 'STB — Research, ESG & Education',
    'sub_trust_c' => 'STC — FNAC, Community & Place-Based',
    'all'         => 'All Sub-Committees',
];
// Sub-committee hub membership — for display context in TDR create/edit forms
$subCommitteeHubs = [
    'sub_trust_a' => ['Day-to-Day Operations', 'Financial Oversight', 'Technology & Blockchain'],
    'sub_trust_b' => ['Research & Acquisitions', 'ESG & Proxy Voting', 'Education & Outreach'],
    'sub_trust_c' => ['First Nations JV', 'Community Projects', 'Place-Based Decisions'],
    'all'         => ['All nine management hubs'],
];
$statusBadge = [
    'draft'             => ['badge-warn', 'Draft'],
    'pending_execution' => ['badge-warn', 'Pending Execution'],
    'fully_executed'    => ['badge-ok',   'Fully Executed'],
    'superseded'        => ['badge-err',  'Superseded'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trustee Decisions | COG$ Admin</title>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
<style>
.main { padding: 24px 28px; }
.topbar h2 { font-size: 1.1rem; font-weight: 700; margin: 0 0 4px; }
.topbar p  { color: var(--sub); font-size: 13px; max-width: 640px; }

.filter-bar {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;
  margin-bottom: 18px; background: var(--panel2);
  border: 1px solid var(--line2); border-radius: 8px; padding: 12px 16px;
}
.filter-bar select, .filter-bar input {
  background: var(--input); border: 1px solid var(--line2); border-radius: 6px;
  color: var(--text); font-size: .8rem; padding: 5px 8px;
}
.filter-bar label { font-size: .75rem; color: var(--sub); display: block; margin-bottom: 3px; }

.tdr-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.tdr-table th {
  background: var(--panel2); border-bottom: 1px solid var(--line);
  padding: 8px 12px; text-align: left; font-size: .72rem;
  text-transform: uppercase; letter-spacing: .08em; color: var(--gold);
}
.tdr-table td { padding: 9px 12px; border-bottom: 1px solid var(--line2); vertical-align: top; }
.tdr-table tr:hover td { background: var(--panel2); }

.badge { font-size: .7rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.badge-ok   { background: var(--okb);   color: var(--ok);   border: 1px solid rgba(82,184,122,.3); }
.badge-warn { background: var(--warnb); color: var(--warn); border: 1px solid rgba(212,148,74,.3); }
.badge-err  { background: var(--errb);  color: var(--err);  border: 1px solid rgba(192,85,58,.3); }

.btn-primary {
  display: inline-block; padding: 7px 16px; border-radius: 7px; font-size: .8rem;
  font-weight: 700; text-decoration: none; cursor: pointer; border: none;
  background: rgba(212,178,92,.2); border: 1px solid rgba(212,178,92,.4);
  color: var(--gold);
}
.btn-primary:hover { background: rgba(212,178,92,.35); }
.btn-sm { padding: 4px 10px; font-size: .75rem; }
.btn-danger {
  background: rgba(192,85,58,.15); border-color: rgba(192,85,58,.4); color: var(--err);
}

?>
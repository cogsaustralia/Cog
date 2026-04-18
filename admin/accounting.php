<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

$hooksFile = __DIR__ . '/includes/AccountingHooks.php';
if (file_exists($hooksFile)) { require_once $hooksFile; }
$hasHooks = class_exists('AccountingHooks');

function ac_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function ac_val(PDO $p, string $q): int {
    try { $s = $p->query($q); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function ac_rows(PDO $p, string $q): array {
    try { $s = $p->query($q); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}

function ac_dollars(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

function ac_transfer_label(string $t): string {
    $map = [
        'a_to_b_bds' => 'A to B (BDS)',
        'a_to_b_dds' => 'A to B (DDS)',
        'a_to_a_reinvest_bds' => 'A reinvest (BDS)',
        'a_to_a_reinvest_dds' => 'A reinvest (DDS)',
        'a_to_admin_dds' => 'Admin fee (DDS)',
        'a_to_c_direct' => 'A to C direct',
        'b_to_holders' => 'B to Holders',
        'b_to_c_dclass' => 'B to C (D Class)',
        'c_to_grant' => 'C to Grant',
    ];
    return $map[$t] ?? ucwords(str_replace('_', ' ', $t));
}

function ac_status_class(string $s): string {
    if (in_array($s, ['completed','paid','disbursed','acquitted'], true)) return 'st-ok';
    if (in_array($s, ['pending','draft','calculated'], true)) return 'st-warn';
    if (in_array($s, ['overdue','failed','cancelled'], true)) return 'st-bad';
    return 'st-dim';
}

$hasTable = function_exists('ops_has_table');
$acctOk = $hasTable && ops_has_table($pdo, 'trust_accounts');

// Fund totals
$received = 0; $adminExp = 0; $investRet = 0; $directC = 0; $pendingC = 0; $toB = 0;
if ($acctOk) {
    $received  = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE payment_status='paid'")
                 + ac_val($pdo, "SELECT COALESCE(SUM(net_amount_cents),0) FROM trust_income");
    $investRet = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM trust_transfers WHERE transfer_type IN ('a_to_a_reinvest_bds','a_to_a_reinvest_dds') AND status='completed'");
    $directC   = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM trust_transfers WHERE transfer_type='a_to_c_direct' AND status='completed'");
    $pendingC  = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM trust_transfers WHERE transfer_type='a_to_c_direct' AND status='pending'");
    $toB       = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM trust_transfers WHERE transfer_type IN ('a_to_b_bds','a_to_b_dds') AND status='completed'");
    $adminFundIn  = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM trust_transfers WHERE transfer_type IN ('payment_to_admin','a_to_admin_dds') AND status='completed'");
    $adminFundOut = ac_val($pdo, "SELECT COALESCE(SUM(amount_cents),0) FROM trust_expenses te JOIN trust_accounts ta ON ta.id = te.trust_account_id WHERE ta.account_code = 'TRUST_A_ADMIN_FUND' AND te.status IN ('approved','paid')");
    $adminFundBal = $adminFundIn - $adminFundOut;
}

// Overdue transfers
$overdue = $acctOk ? ac_rows($pdo, "SELECT transfer_ref, transfer_type, amount_cents, compliance_due_by, DATEDIFF(NOW(), compliance_due_by) AS days_overdue FROM trust_transfers WHERE status IN ('pending','approved') AND compliance_due_by IS NOT NULL AND compliance_due_by < NOW() ORDER BY compliance_due_by") : [];

// Recent transfers
$transfers = $acctOk ? ac_rows($pdo, "SELECT transfer_ref, transfer_type, amount_cents, status, COALESCE(transferred_at, created_at) AS dated FROM trust_transfers ORDER BY id DESC LIMIT 12") : [];

// Recent expenses
$expenses = $acctOk ? ac_rows($pdo, "SELECT expense_ref, expense_category, description, amount_cents, status FROM trust_expenses ORDER BY id DESC LIMIT 10") : [];

// Trust accounts
$accounts = $acctOk ? ac_rows($pdo, "SELECT sub_trust, account_name, direction FROM trust_accounts ORDER BY id") : [];

// Donation ledger
$dlPending = 0; $dlDone = 0;
if ($acctOk && $hasTable && ops_has_table($pdo, 'donation_ledger')) {
    $dlPending = ac_val($pdo, "SELECT COUNT(*) FROM donation_ledger WHERE transfer_to_c_status='pending'");
    $dlDone    = ac_val($pdo, "SELECT COUNT(*) FROM donation_ledger WHERE transfer_to_c_status='transferred'");
}

// Distribution runs
$distRuns = ($acctOk && $hasTable && ops_has_table($pdo, 'distribution_runs'))
    ? ac_rows($pdo, "SELECT run_ref, total_pool_cents, distribution_due_by, status FROM distribution_runs ORDER BY id DESC LIMIT 5") : [];

// ── Godley ledger data ───────────────────────────────────────────────────────
// Invariant status strip
$invariants = ac_rows($pdo, "SELECT code, name, violation_count FROM v_godley_invariant_status ORDER BY code");

// Sub-trust sector balances (for matrix row grouping)
$sectorBalances = ac_rows($pdo, "
    SELECT ga.sub_trust,
           sa.id AS sa_id,
           sa.display_name,
           sa.account_type,
           COALESCE(SUM(CASE WHEN le.entry_type='debit'   THEN le.amount_cents ELSE 0 END),0) AS total_debit,
           COALESCE(SUM(CASE WHEN le.entry_type='credit'  THEN le.amount_cents ELSE 0 END),0) AS total_credit,
           COALESCE(SUM(CASE WHEN le.entry_type='debit'   THEN le.amount_cents
                              WHEN le.entry_type='credit' THEN -le.amount_cents END),0) AS balance_cents,
           COUNT(le.id) AS entry_count
    FROM stewardship_accounts sa
    JOIN v_godley_accounts ga ON ga.id = sa.id
    LEFT JOIN ledger_entries le ON le.stewardship_account_id = sa.id
    GROUP BY ga.sub_trust, sa.id, sa.display_name, sa.account_type
    ORDER BY FIELD(ga.sub_trust,'A','B','C','M','X'), sa.id
");

// Flow matrix: account x flow_category cell values
$flowMatrix = ac_rows($pdo, "
    SELECT sa.id AS sa_id,
           le.flow_category,
           SUM(CASE WHEN le.entry_type='debit'  THEN le.amount_cents ELSE 0 END) AS debit_cents,
           SUM(CASE WHEN le.entry_type='credit' THEN le.amount_cents ELSE 0 END) AS credit_cents
    FROM ledger_entries le
    JOIN stewardship_accounts sa ON sa.id = le.stewardship_account_id
    WHERE le.flow_category IS NOT NULL
    GROUP BY sa.id, le.flow_category
");
// Build lookup: [sa_id][flow_category] = ['d'=>..., 'c'=>...]
$flowLookup = [];
$flowCols   = [];
foreach ($flowMatrix as $row) {
    $flowLookup[$row['sa_id']][$row['flow_category']] = ['d' => (int)$row['debit_cents'], 'c' => (int)$row['credit_cents']];
    $flowCols[$row['flow_category']] = true;
}
$flowCols = array_keys($flowCols);
sort($flowCols);

// Consolidated sub-trust totals from view
$consolidated = ac_rows($pdo, "SELECT sub_trust, display_name, balance_cents, entry_count FROM v_godley_consolidated");

// ── Balance sheet per sub-trust ──────────────────────────────────────────────
$bsA = ac_rows($pdo, "SELECT account_key, display_name, account_type, balance_cents, entry_count, last_activity_date FROM v_godley_st_a ORDER BY account_key");
$bsB = ac_rows($pdo, "SELECT account_key, display_name, account_type, balance_cents, entry_count, last_activity_date FROM v_godley_st_b ORDER BY account_key");
$bsC = ac_rows($pdo, "SELECT account_key, display_name, account_type, balance_cents, entry_count, last_activity_date FROM v_godley_st_c ORDER BY account_key");

// ── Compliance deadline tracker ───────────────────────────────────────────────
// I3: pending 5-biz-day dividend splits (currently OVERDUE ones from the view)
$i3Rows = ac_rows($pdo, "SELECT event_ref, event_date, deadline_date, days_overdue FROM v_invariant_i3_5bizday_transfer ORDER BY days_overdue DESC");
// I4: pending 60-day STB distributions
$i4Rows = ac_rows($pdo, "SELECT inflow_ref, inflow_date, deadline_date, days_overdue, inflow_cents, distributed_cents FROM v_invariant_i4_60day_distribution ORDER BY days_overdue DESC");
// I5: pending 2-biz-day STC direct transfers
$i5Rows = ac_rows($pdo, "SELECT payment_ref, received_date, deadline_date, days_overdue FROM v_invariant_i5_2bizday_direct_c ORDER BY days_overdue DESC");
// I12: ASX stewardship lock violations
$i12Rows = ac_rows($pdo, "SELECT token_key, sender_subject_ref, requested_at, locked_until FROM v_invariant_i12_stewardship_lock ORDER BY locked_until DESC");

// Also fetch upcoming trust_transfers pending (not yet overdue) with compliance_due_by within 14 days
$pendingDeadlines = $acctOk ? ac_rows($pdo, "SELECT transfer_ref, transfer_type, amount_cents, compliance_due_by, DATEDIFF(compliance_due_by, NOW()) AS days_remaining FROM trust_transfers WHERE status IN ('pending','approved') AND compliance_due_by IS NOT NULL AND compliance_due_by >= NOW() AND compliance_due_by <= DATE_ADD(NOW(), INTERVAL 14 DAY) ORDER BY compliance_due_by") : [];

// FN grant compliance
$fnData = [];
if ($acctOk && $hasTable && ops_has_table($pdo, 'grants')) {
    try { $fnData = ac_rows($pdo, "SELECT * FROM v_fn_grant_compliance ORDER BY financial_year DESC LIMIT 3"); } catch (Throwable $e) {}
}

// Pre-compute conditional colors
$pendingCColor = ($pendingC > 0) ? 'var(--warn)' : 'var(--ok)';
$dlColor = ($dlPending > 0) ? 'var(--warn)' : 'var(--ok)';
$adminBalColor = ($adminFundBal > 0) ? 'var(--ok)' : 'var(--err)';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./assets/admin.css">
<title>Trust Accounting | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>.main { padding:24px 28px; min-width:0; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; max-width:560px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); }

.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }

.stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(155px,1fr)); gap:14px; margin-bottom:22px; }
.stat { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r2); padding:16px 18px; text-align:center; }
.stat-val { font-size:1.6rem; font-weight:800; margin-bottom:4px; }
.stat-label { font-size:.72rem; color:var(--sub); text-transform:uppercase; letter-spacing:.06em; }

.grid2 { display:grid; grid-template-columns:1.1fr .9fr; gap:18px; }
@media(max-width:980px) { .grid2 { grid-template-columns:1fr; } }

table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:9px 10px; font-size:13px; border-top:1px solid var(--line); vertical-align:top; }
th { color:var(--dim); font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-top:none; }
.mono { font-family:monospace; font-size:12px; }

.st { display:inline-block; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700; text-transform:uppercase; }
.st-ok { background:var(--okb); color:var(--ok); }
.st-warn { background:var(--warnb); color:var(--warn); }
.st-bad { background:var(--errb); color:var(--err); }
.st-dim { background:rgba(255,255,255,.04); color:var(--dim); }

.alert { padding:14px 18px; border-radius:var(--r2); margin-bottom:18px; font-size:13px; font-weight:600; }
.alert-red { background:var(--errb); border:1px solid rgba(196,96,96,.3); color:#f0a0a0; }
.alert-green { background:var(--okb); border:1px solid rgba(82,184,122,.3); color:#a0e8b8; }
.alert-amber { background:var(--warnb); border:1px solid rgba(200,144,26,.3); color:#e8cc80; }

.empty { color:var(--dim); font-size:13px; padding:20px 0; text-align:center; }
.acct-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:600; background:rgba(255,255,255,.04); border:1px solid var(--line); }
.acct-dot { width:8px; height:8px; border-radius:50%; }
.acct-dot.a { background:var(--blue); }
.acct-dot.b { background:var(--ok); }
.acct-dot.c { background:var(--purple); }

/* ── Invariant strip ── */
.inv-strip { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:22px; }
.inv-pill { display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:10px; font-size:12px; font-weight:700; border:1px solid transparent; cursor:default; }
.inv-pill.ok  { background:rgba(82,184,122,.1);  border-color:rgba(82,184,122,.3);  color:var(--ok); }
.inv-pill.err { background:rgba(196,96,96,.12);  border-color:rgba(196,96,96,.35);  color:var(--err); }
.inv-pill .inv-code { font-size:10px; font-weight:800; opacity:.7; font-family:monospace; }
.inv-pill .inv-dot  { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.inv-pill.ok  .inv-dot { background:var(--ok); }
.inv-pill.err .inv-dot { background:var(--err); box-shadow:0 0 6px var(--err); }

/* ── Godley matrix ── */
.godley-wrap { overflow-x:auto; margin-bottom:22px; }
.godley-table { border-collapse:collapse; font-size:12px; min-width:600px; width:100%; }
.godley-table th, .godley-table td { padding:7px 10px; border:1px solid var(--line); text-align:right; white-space:nowrap; }
.godley-table th { background:var(--panel2); color:var(--dim); font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
.godley-table th.acct-col { text-align:left; min-width:190px; position:sticky; left:0; z-index:2; background:var(--panel2); }
.godley-table td.acct-col { text-align:left; position:sticky; left:0; z-index:1; background:var(--panel); font-weight:600; font-size:11.5px; }
.godley-table td.acct-col .acct-sub { font-size:10px; color:var(--dim); font-weight:400; display:block; margin-top:1px; }
.godley-table tr.sector-head td, .godley-table tr.sector-head th { background:rgba(255,255,255,.03); color:var(--sub); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; border-bottom:1px solid var(--line2); }
.godley-table tr.sector-head td.acct-col { background:rgba(255,255,255,.03); }
.godley-table tr.sector-total td { background:rgba(255,255,255,.02); font-weight:700; border-top:2px solid var(--line2); font-size:11.5px; }
.godley-table tr.sector-total td.acct-col { background:rgba(255,255,255,.02); }
.godley-table td.zero { color:var(--dim); }
.godley-table td.pos  { color:var(--ok); }
.godley-table td.neg  { color:var(--err); }
.cell-dr { font-size:10px; color:var(--err); display:block; }
.cell-cr { font-size:10px; color:var(--ok);  display:block; }
.godley-table tr.grand-total td { background:var(--panel2); font-weight:800; font-size:12.5px; border-top:2px solid var(--line2); color:var(--text); }
.godley-table tr.grand-total td.acct-col { background:var(--panel2); }

/* ── Balance sheet ── */
.bs-grid { display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:0; border:1px solid var(--line); border-radius:var(--r2); overflow:hidden; margin-top:4px; }
.bs-col { border-right:1px solid var(--line); }
.bs-col:last-child { border-right:none; }
.bs-head { background:var(--panel2); padding:10px 14px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; border-bottom:1px solid var(--line); }
.bs-head.a { color:var(--blue); }
.bs-head.b { color:var(--ok); }
.bs-head.c { color:var(--purple); }
.bs-head.cons { color:var(--gold); }
.bs-row { display:flex; justify-content:space-between; align-items:baseline; padding:7px 14px; border-top:1px solid var(--line); font-size:12px; gap:8px; }
.bs-row:first-of-type { border-top:none; }
.bs-row .bs-name { color:var(--sub); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bs-row .bs-val { font-weight:700; white-space:nowrap; }
.bs-row .bs-val.pos { color:var(--ok); }
.bs-row .bs-val.neg { color:var(--err); }
.bs-row .bs-val.zero { color:var(--dim); }
.bs-total { background:rgba(255,255,255,.03); border-top:2px solid var(--line2) !important; }
.bs-total .bs-name { font-weight:700; color:var(--text); }

/* ── Compliance tracker ── */
.comp-tabs { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
.comp-tab { padding:5px 12px; border-radius:8px; font-size:11.5px; font-weight:700; border:1px solid var(--line); background:var(--panel2); color:var(--sub); cursor:pointer; transition:background .12s, color .12s; }
.comp-tab.active { background:rgba(196,96,96,.15); border-color:rgba(196,96,96,.35); color:var(--err); }
.comp-tab.ok { background:rgba(82,184,122,.08); border-color:rgba(82,184,122,.25); color:var(--ok); cursor:default; }
.comp-panel { display:none; }
.comp-panel.active { display:block; }
.comp-deadline-badge { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10px; font-weight:800; }
.comp-deadline-badge.overdue { background:var(--errb); color:var(--err); }
.comp-deadline-badge.soon { background:var(--warnb); color:var(--warn); }
.comp-deadline-badge.safe { background:var(--okb); color:var(--ok); }

/* ── Invariant drill modal ── */
.inv-modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:1000; align-items:center; justify-content:center; }
.inv-modal-bg.open { display:flex; }
.inv-modal { background:var(--panel); border:1px solid var(--line2); border-radius:var(--r); padding:0; max-width:760px; width:94vw; max-height:80vh; display:flex; flex-direction:column; }
.inv-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.inv-modal-head h3 { font-size:1rem; font-weight:700; }
.inv-modal-close { background:none; border:none; color:var(--sub); font-size:1.3rem; cursor:pointer; padding:4px 8px; border-radius:6px; }
.inv-modal-close:hover { color:var(--text); background:rgba(255,255,255,.06); }
.inv-modal-body { overflow-y:auto; padding:16px 20px; flex:1; }
.inv-pill.err { cursor:pointer; }
.inv-pill.err:hover { background:rgba(196,96,96,.2); }

/* ── Ledger link cells ── */
.godley-table td a.cell-link { color:inherit; text-decoration:none; display:block; }
.godley-table td a.cell-link:hover { text-decoration:underline; opacity:.85; }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('accounting'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Trust accounting<?php echo ops_admin_help_button('Trust accounting', 'This page is the finance control view for trust balances, transfers, deadlines, accounts, donation-ledger movement, and distribution readiness. Use it to review whether money has been allocated correctly across Sub-Trust A, B, and C.'); ?></h1>
    <p>Fund balances, inter-trust transfers, compliance deadlines, and reconciliation across Sub-Trust A, B, and C.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="<?php echo ac_h(admin_url('dashboard.php')); ?>">Dashboard</a>
  </div>
</div>

<?php if (!$acctOk): ?>
  <div class="alert alert-amber">Accounting tables not found. Run cogs_accounting_schema.sql in phpMyAdmin first.</div>
<?php else: ?>

<?php if (!$hasHooks): ?>
  <div class="alert alert-amber">AccountingHooks.php not loaded. Upload to admin/includes/ for full automation.</div>
<?php endif; ?>

<?php echo ops_admin_info_panel(
    'Finance control surface',
    'What this page does',
    'Use this page to review how money is moving across Sub-Trust A, B, and C. It is the main control-review page for fund balances, transfer obligations, donation-ledger movement, and distribution readiness.',
    [
        'Review incoming funds and where they have been allocated.',
        'Check overdue or pending transfers before compliance deadlines are breached.',
        'Confirm Donation Ledger movement into Sub-Trust C.',
        'Review recent expenses, trust accounts, and distribution runs in one place.',
    ]
); ?>

<?php echo ops_admin_workflow_panel(
    'Typical workflow',
    'Work from balances and exceptions toward specific records so you understand the overall position before changing or escalating anything.',
    [
        ['title' => 'Check the top balances', 'body' => 'Start with funds received, administration balance, reinvestment, Sub-Trust B allocations, and Sub-Trust C transfers.'],
        ['title' => 'Review deadline pressure', 'body' => 'If overdue transfers appear, open those items first because they represent compliance timing risk.'],
        ['title' => 'Read the supporting records', 'body' => 'Use recent transfers, expenses, donation-ledger status, and distribution runs to understand what created the current position.'],
        ['title' => 'Escalate or correct downstream', 'body' => 'Use Expenses or the relevant operational page if you need to update a record. This page is primarily for review and control oversight.'],
    ]
); ?>

<?php echo ops_admin_guide_panel(
    'How to read this page',
    'Each section answers a different finance-control question.',
    [
        ['title' => 'Top metrics', 'body' => 'Headline money movements and balances across the trust structure.'],
        ['title' => 'Overdue transfers', 'body' => 'Transfers whose compliance due date has passed and now require immediate attention.'],
        ['title' => 'Recent transfers', 'body' => 'The latest money movements between trust accounts and pathways.'],
        ['title' => 'Recent expenses', 'body' => 'The latest cost records so you can see what has reduced available balances.'],
        ['title' => 'Trust accounts / Donation Ledger / Distribution runs', 'body' => 'Supporting evidence for where funds sit and whether downstream obligations are being met.'],
    ]
); ?>

<?php echo ops_admin_status_panel(
    'Status guide',
    'These labels show whether an accounting item is healthy, still in progress, or already in breach.',
    [
        ['label' => 'Completed / Paid / Disbursed / Acquitted', 'body' => 'The money movement or obligation has been finished successfully.'],
        ['label' => 'Pending / Draft / Calculated', 'body' => 'The item exists but still requires a later step before it is complete.'],
        ['label' => 'Overdue / Failed / Cancelled', 'body' => 'The item requires review because timing has been missed, the action failed, or it is no longer proceeding.'],
    ]
); ?>

<?php if (count($overdue)): ?>
  <div class="alert alert-red"><?php echo count($overdue); ?> overdue transfer<?php echo count($overdue) !== 1 ? 's' : ''; ?> — compliance deadline breached</div>
<?php else: ?>
  <div class="alert alert-green">No overdue transfers — all compliance deadlines met</div>
<?php endif; ?>

<?php if (!empty($invariants)): ?>
<!-- ── Invariant status strip ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h2>Godley invariants<?php echo ops_admin_help_button('Godley invariants', '12 constitutional rules are continuously verified against every ledger entry. Green = zero violations. Red = one or more violations requiring immediate attention.'); ?></h2>
    <span style="font-size:12px;color:var(--dim)"><?php
      $totalViolations = array_sum(array_column($invariants, 'violation_count'));
      echo $totalViolations === 0
        ? '<span style="color:var(--ok);font-weight:700">✓ All clear — 0 violations</span>'
        : '<span style="color:var(--err);font-weight:700">' . $totalViolations . ' violation' . ($totalViolations !== 1 ? 's' : '') . ' detected</span>';
    ?></span>
  </div>
  <div class="card-body">
    <div class="inv-strip">
    <?php foreach ($invariants as $inv):
      $viol = (int)$inv['violation_count'];
      $cls  = $viol === 0 ? 'ok' : 'err';
    ?>
      <div class="inv-pill <?php echo $cls; ?>" title="<?php echo ac_h($inv['name']); ?>">
        <span class="inv-dot"></span>
        <span class="inv-code"><?php echo ac_h($inv['code']); ?></span>
        <span><?php echo ac_h($inv['name']); ?></span>
        <?php if ($viol > 0): ?>
          <span style="background:var(--err);color:#fff;border-radius:6px;padding:1px 6px;font-size:10px"><?php echo $viol; ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ── Balance sheet card ─────────────────────────────────────────────────────
$bsTrusts = [
    'A' => ['label'=>'Sub-Trust A','class'=>'a','rows'=>$bsA],
    'B' => ['label'=>'Sub-Trust B','class'=>'b','rows'=>$bsB],
    'C' => ['label'=>'Sub-Trust C','class'=>'c','rows'=>$bsC],
];
// Build consolidated totals from $consolidated query
$consMap = [];
foreach($consolidated as $cr) { $consMap[$cr['sub_trust']] = $cr; }
?>
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h2>Balance sheet<?php echo ops_admin_help_button('Balance sheet', 'Current net balances for each stewardship account, grouped by sub-trust. A positive (Dr) balance means more debits than credits — for asset accounts this is the expected direction. Grand totals must net to zero across the full system.'); ?></h2>
    <span style="font-size:12px;color:var(--dim)">Live from ledger_entries</span>
  </div>
  <div class="card-body" style="padding:12px 16px">
    <div class="bs-grid">
      <?php foreach($bsTrusts as $key => $trust): ?>
      <div class="bs-col">
        <div class="bs-head <?php echo $trust['class']; ?>"><?php echo ac_h($trust['label']); ?></div>
        <?php if(empty($trust['rows'])): ?>
          <div class="bs-row"><span class="bs-name" style="color:var(--dim)">No accounts</span></div>
        <?php else: ?>
          <?php
          $colTotal = 0;
          foreach($trust['rows'] as $r):
            $bal = (int)$r['balance_cents'];
            $colTotal += $bal;
            $vc = $bal > 0 ? 'pos' : ($bal < 0 ? 'neg' : 'zero');
          ?>
          <div class="bs-row" title="<?php echo ac_h($r['account_type']); ?> | <?php echo (int)$r['entry_count']; ?> entries<?php echo $r['last_activity_date'] ? ' | last: '.ac_h($r['last_activity_date']) : ''; ?>">
            <span class="bs-name"><?php echo ac_h($r['display_name']); ?></span>
            <span class="bs-val <?php echo $vc; ?>"><?php echo ac_h(ac_dollars(abs($bal))); ?><?php echo $bal < 0 ? ' Cr' : ($bal > 0 ? ' Dr' : ''); ?></span>
          </div>
          <?php endforeach; ?>
          <?php $tc = $colTotal > 0 ? 'pos' : ($colTotal < 0 ? 'neg' : 'zero'); ?>
          <div class="bs-row bs-total">
            <span class="bs-name">Total <?php echo ac_h($key); ?></span>
            <span class="bs-val <?php echo $tc; ?>"><?php echo ac_h(ac_dollars(abs($colTotal))); ?><?php echo $colTotal < 0 ? ' Cr' : ($colTotal > 0 ? ' Dr' : ''); ?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <!-- Consolidated column -->
      <div class="bs-col">
        <div class="bs-head cons">Consolidated</div>
        <?php
        $grandTotal = 0;
        foreach(['A','B','C'] as $sk):
          $cr = $consMap[$sk] ?? null;
          $bal = $cr ? (int)$cr['balance_cents'] : 0;
          $grandTotal += $bal;
          $vc = $bal > 0 ? 'pos' : ($bal < 0 ? 'neg' : 'zero');
        ?>
        <div class="bs-row">
          <span class="bs-name"><?php echo ac_h($cr['display_name'] ?? 'Sub-Trust '.$sk); ?></span>
          <span class="bs-val <?php echo $vc; ?>"><?php echo ac_h(ac_dollars(abs($bal))); ?><?php echo $bal < 0 ? ' Cr' : ($bal > 0 ? ' Dr' : ''); ?></span>
        </div>
        <?php endforeach; ?>
        <?php $gc = $grandTotal === 0 ? 'zero' : ($grandTotal > 0 ? 'pos' : 'neg'); ?>
        <div class="bs-row bs-total">
          <span class="bs-name">Grand total</span>
          <span class="bs-val <?php echo $gc; ?>">
            <?php echo $grandTotal === 0
              ? '<span style="color:var(--ok)">✓ Zero</span>'
              : ac_h(ac_dollars(abs($grandTotal))) . ($grandTotal < 0 ? ' Cr' : ' Dr'); ?>
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// ── Compliance deadline tracker ────────────────────────────────────────────
$compTabs = [
    'i3'  => ['label'=>'I3 · Div splits',  'rows'=>$i3Rows,  'empty'=>'No overdue dividend split transfers (5-biz-day rule).'],
    'i4'  => ['label'=>'I4 · B distribute','rows'=>$i4Rows,  'empty'=>'No overdue Sub-Trust B distributions (60-day rule).'],
    'i5'  => ['label'=>'I5 · C direct',    'rows'=>$i5Rows,  'empty'=>'No overdue Sub-Trust C direct transfers (2-biz-day rule).'],
    'i12' => ['label'=>'I12 · ASX lock',   'rows'=>$i12Rows, 'empty'=>'No ASX Stewardship Season lock violations.'],
];
$firstActiveTab = null;
foreach($compTabs as $tid => $tab) {
    if(!empty($tab['rows'])) { $firstActiveTab = $tid; break; }
}
if($firstActiveTab === null) $firstActiveTab = 'i3';
?>
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h2>Compliance deadline tracker<?php echo ops_admin_help_button('Compliance deadline tracker', 'Shows overdue items per constitutional invariant: I3 (dividend splits within 5 business days), I4 (Sub-Trust B distributions within 60 days), I5 (Sub-Trust C direct transfers within 2 business days), I12 (ASX Stewardship Season locks). Each tab shows the violating records. Green tab = no violations.'); ?></h2>
    <?php
    $anyViol = !empty($i3Rows) || !empty($i4Rows) || !empty($i5Rows) || !empty($i12Rows);
    echo $anyViol
      ? '<span style="font-size:12px;color:var(--err);font-weight:700">⚠ Violations present</span>'
      : '<span style="font-size:12px;color:var(--ok);font-weight:700">✓ All clear</span>';
    ?>
  </div>
  <div class="card-body">
    <?php if(!empty($pendingDeadlines)): ?>
    <div style="margin-bottom:14px;padding:10px 14px;background:var(--warnb);border:1px solid rgba(200,144,26,.3);border-radius:10px;font-size:12px">
      <strong style="color:var(--warn)">⏰ <?php echo count($pendingDeadlines); ?> transfer<?php echo count($pendingDeadlines)!==1?'s':''; ?> due within 14 days:</strong>
      <?php foreach($pendingDeadlines as $pd): ?>
        <div style="margin-top:5px;display:flex;gap:10px;align-items:baseline">
          <span class="mono" style="color:var(--warn)"><?php echo ac_h($pd['transfer_ref']); ?></span>
          <span style="color:var(--sub)"><?php echo ac_h(ac_transfer_label($pd['transfer_type'])); ?></span>
          <span style="color:var(--text);font-weight:700"><?php echo ac_h(ac_dollars((int)$pd['amount_cents'])); ?></span>
          <span class="comp-deadline-badge soon">due <?php echo ac_h($pd['compliance_due_by']); ?> (<?php echo (int)$pd['days_remaining']; ?>d)</span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="comp-tabs">
      <?php foreach($compTabs as $tid => $tab):
        $hasViol = !empty($tab['rows']);
        $cls = $hasViol ? ($tid === $firstActiveTab ? 'active' : '') : 'ok';
      ?>
      <button class="comp-tab <?php echo $cls; ?>" data-panel="comp-<?php echo ac_h($tid); ?>" type="button">
        <?php echo $hasViol ? '⚠ ' : '✓ '; echo ac_h($tab['label']); ?>
        <?php if($hasViol): ?><span style="margin-left:5px;background:var(--err);color:#fff;border-radius:4px;padding:0 5px;font-size:10px"><?php echo count($tab['rows']); ?></span><?php endif; ?>
      </button>
      <?php endforeach; ?>
    </div>

    <?php foreach($compTabs as $tid => $tab):
      $isActive = $tid === $firstActiveTab; ?>
    <div class="comp-panel <?php echo $isActive ? 'active' : ''; ?>" id="comp-<?php echo ac_h($tid); ?>">
      <?php if(empty($tab['rows'])): ?>
        <p style="color:var(--ok);font-size:13px"><?php echo ac_h($tab['empty']); ?></p>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table>
          <thead><tr>
            <?php if($tid === 'i3'): ?>
              <th>Event ref</th><th>Event date</th><th>Deadline</th><th>Overdue by</th>
            <?php elseif($tid === 'i4'): ?>
              <th>Inflow ref</th><th>Inflow date</th><th>Deadline</th><th>Overdue by</th><th>Inflow</th><th>Distributed</th>
            <?php elseif($tid === 'i5'): ?>
              <th>Payment ref</th><th>Received</th><th>Deadline</th><th>Overdue by</th>
            <?php elseif($tid === 'i12'): ?>
              <th>Token</th><th>Sender</th><th>Requested</th><th>Locked until</th>
            <?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach($tab['rows'] as $r): ?>
            <tr>
            <?php if($tid === 'i3'): ?>
              <td class="mono"><?php echo ac_h($r['event_ref']); ?></td>
              <td style="color:var(--sub)"><?php echo ac_h($r['event_date']); ?></td>
              <td style="color:var(--warn)"><?php echo ac_h($r['deadline_date']); ?></td>
              <td><span class="comp-deadline-badge overdue"><?php echo (int)$r['days_overdue']; ?> days</span></td>
            <?php elseif($tid === 'i4'): ?>
              <td class="mono"><?php echo ac_h($r['inflow_ref']); ?></td>
              <td style="color:var(--sub)"><?php echo ac_h($r['inflow_date']); ?></td>
              <td style="color:var(--warn)"><?php echo ac_h($r['deadline_date']); ?></td>
              <td><span class="comp-deadline-badge overdue"><?php echo (int)$r['days_overdue']; ?> days</span></td>
              <td style="font-weight:600"><?php echo ac_h(ac_dollars((int)$r['inflow_cents'])); ?></td>
              <td style="color:var(--sub)"><?php echo ac_h(ac_dollars((int)$r['distributed_cents'])); ?></td>
            <?php elseif($tid === 'i5'): ?>
              <td class="mono"><?php echo ac_h($r['payment_ref']); ?></td>
              <td style="color:var(--sub)"><?php echo ac_h($r['received_date']); ?></td>
              <td style="color:var(--warn)"><?php echo ac_h($r['deadline_date']); ?></td>
              <td><span class="comp-deadline-badge overdue"><?php echo (int)$r['days_overdue']; ?> days</span></td>
            <?php elseif($tid === 'i12'): ?>
              <td class="mono"><?php echo ac_h($r['token_key']); ?></td>
              <td style="color:var(--sub)"><?php echo ac_h($r['sender_subject_ref']); ?></td>
              <td style="color:var(--sub)"><?php echo ac_h($r['requested_at']); ?></td>
              <td style="color:var(--warn)"><?php echo ac_h($r['locked_until']); ?></td>
            <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!empty($sectorBalances) && !empty($flowCols)): ?>
<!-- ── Godley sector-by-flow matrix ──────────────────────────────────── -->
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h2>Godley ledger matrix<?php echo ops_admin_help_button('Godley ledger matrix', 'Rows are stewardship accounts grouped by sub-trust sector. Columns are flow categories. Each cell shows the net balance for that account-flow combination. Dr = debit total, Cr = credit total. The grand total row must sum to zero (double-entry integrity).'); ?></h2>
    <span style="font-size:12px;color:var(--dim)"><?php echo count($sectorBalances); ?> accounts &middot; <?php echo count($flowCols); ?> flow types</span>
  </div>
  <div class="godley-wrap">
    <table class="godley-table">
      <thead>
        <tr>
          <th class="acct-col">Account</th>
          <?php foreach ($flowCols as $fc): ?>
            <th><?php echo ac_h(str_replace('_', ' ', $fc)); ?></th>
          <?php endforeach; ?>
          <th style="color:var(--text)">Net balance</th>
          <th>Entries</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $currentSector   = null;
        $sectorDrTotals  = [];
        $sectorCrTotals  = [];
        $sectorBal       = 0;
        $grandDrTotals   = array_fill_keys($flowCols, 0);
        $grandCrTotals   = array_fill_keys($flowCols, 0);
        $grandBal        = 0;
        $grandEntries    = 0;
        $sectorRows      = [];

        // Group rows by sector
        $grouped = [];
        foreach ($sectorBalances as $row) {
            $grouped[$row['sub_trust']][] = $row;
        }
        $sectorLabels = ['A'=>'Sub-Trust A — Partners Asset Pool','B'=>'Sub-Trust B — Dividend Distribution','C'=>'Sub-Trust C — Community Projects Fund','M'=>'Partners (Member Wallets)','X'=>'External Accounts'];

        foreach ($grouped as $sector => $rows):
            // Sector header
            echo '<tr class="sector-head"><td class="acct-col" colspan="' . (count($flowCols)+3) . '">' . ac_h($sectorLabels[$sector] ?? 'Sector '.$sector) . '</td></tr>';

            $sDr = array_fill_keys($flowCols, 0);
            $sCr = array_fill_keys($flowCols, 0);
            $sBal = 0; $sEntries = 0;

            foreach ($rows as $r):
                $saId = $r['sa_id'];
                $bal  = (int)$r['balance_cents'];
                $sBal += $bal; $grandBal += $bal;
                $sEntries += (int)$r['entry_count']; $grandEntries += (int)$r['entry_count'];
                $balClass = $bal > 0 ? 'pos' : ($bal < 0 ? 'neg' : 'zero');
                echo '<tr>';
                echo '<td class="acct-col"><a class="cell-link" href="ledger.php?account=' . urlencode((string)$saId) . '">' . ac_h($r['display_name']) . '</a><span class="acct-sub">' . ac_h($r['account_type']) . '</span></td>';
                foreach ($flowCols as $fc):
                    $cell = $flowLookup[$saId][$fc] ?? null;
                    if ($cell === null) {
                        echo '<td class="zero">—</td>';
                    } else {
                        $net = $cell['d'] - $cell['c'];
                        $sDr[$fc] += $cell['d']; $sCr[$fc] += $cell['c'];
                        $grandDrTotals[$fc] += $cell['d']; $grandCrTotals[$fc] += $cell['c'];
                        $nc = $net > 0 ? 'pos' : ($net < 0 ? 'neg' : 'zero');
                        $href = 'ledger.php?account=' . urlencode((string)$saId) . '&flow=' . urlencode($fc);
                        echo '<td class="' . $nc . '">';
                        echo '<a class="cell-link" href="' . $href . '">';
                        if ($cell['d'] > 0) echo '<span class="cell-dr">Dr ' . ac_h(ac_dollars($cell['d'])) . '</span>';
                        if ($cell['c'] > 0) echo '<span class="cell-cr">Cr ' . ac_h(ac_dollars($cell['c'])) . '</span>';
                        echo '</a>';
                        echo '</td>';
                    }
                endforeach;
                echo '<td class="' . $balClass . '" style="font-weight:700">' . ac_h(ac_dollars(abs($bal))) . ($bal < 0 ? ' Cr' : ($bal > 0 ? ' Dr' : '')) . '</td>';
                echo '<td class="zero">' . (int)$r['entry_count'] . '</td>';
                echo '</tr>';
            endforeach;

            // Sector total row
            $sBc = $sBal > 0 ? 'pos' : ($sBal < 0 ? 'neg' : 'zero');
            echo '<tr class="sector-total">';
            echo '<td class="acct-col">Sector ' . ac_h($sector) . ' total</td>';
            foreach ($flowCols as $fc):
                $net = $sDr[$fc] - $sCr[$fc];
                $nc  = $net > 0 ? 'pos' : ($net < 0 ? 'neg' : 'zero');
                if ($sDr[$fc] === 0 && $sCr[$fc] === 0) { echo '<td class="zero">—</td>'; }
                else { echo '<td class="' . $nc . '"><span class="cell-dr">Dr ' . ac_h(ac_dollars($sDr[$fc])) . '</span><span class="cell-cr">Cr ' . ac_h(ac_dollars($sCr[$fc])) . '</span></td>'; }
            endforeach;
            echo '<td class="' . $sBc . '" style="font-weight:700">' . ac_h(ac_dollars(abs($sBal))) . ($sBal < 0 ? ' Cr' : ($sBal > 0 ? ' Dr' : '')) . '</td>';
            echo '<td class="zero">' . $sEntries . '</td>';
            echo '</tr>';

        endforeach;
      ?>
      </tbody>
      <tfoot>
        <tr class="grand-total">
          <td class="acct-col">Grand total (must = 0)</td>
          <?php foreach ($flowCols as $fc):
            $net = $grandDrTotals[$fc] - $grandCrTotals[$fc];
            $nc  = $net === 0 ? 'zero' : ($net > 0 ? 'pos' : 'neg');
            if ($grandDrTotals[$fc] === 0 && $grandCrTotals[$fc] === 0) { echo '<td class="zero">—</td>'; }
            else { echo '<td class="' . $nc . '"><span class="cell-dr">Dr ' . ac_h(ac_dollars($grandDrTotals[$fc])) . '</span><span class="cell-cr">Cr ' . ac_h(ac_dollars($grandCrTotals[$fc])) . '</span></td>'; }
          endforeach; ?>
          <?php $gbc = $grandBal === 0 ? 'zero' : ($grandBal > 0 ? 'pos' : 'neg'); ?>
          <td class="<?php echo $gbc; ?>" style="font-size:13px">
            <?php echo $grandBal === 0
              ? '<span style="color:var(--ok)">✓ Balanced</span>'
              : '<span style="color:var(--err)">⚠ ' . ac_h(ac_dollars(abs($grandBal))) . ' imbalance</span>'; ?>
          </td>
          <td class="zero"><?php echo $grandEntries; ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-val" style="color:var(--ok)"><?php echo ac_dollars($received); ?></div><div class="stat-label">Total received (incl. income)</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--blue)"><?php echo ac_dollars($investRet); ?></div><div class="stat-label">Investment pool (A)</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--gold)"><?php echo ac_dollars($adminFundIn); ?></div><div class="stat-label">Admin fund allocated</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--err)"><?php echo ac_dollars($adminFundOut); ?></div><div class="stat-label">Admin fund spent</div></div>
  <div class="stat" style="border-color:<?php echo $adminBalColor; ?>"><div class="stat-val" style="color:<?php echo $adminBalColor; ?>"><?php echo ac_dollars($adminFundBal); ?></div><div class="stat-label">Admin fund available</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--purple)"><?php echo ac_dollars($directC); ?></div><div class="stat-label">Direct to Trust C</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--blue)"><?php echo ac_dollars($toB); ?></div><div class="stat-label">Distributed via B</div></div>
  <div class="stat"><div class="stat-val" style="color:<?php echo $pendingCColor; ?>"><?php echo ac_dollars($pendingC); ?></div><div class="stat-label">Pending A to C</div></div>
</div>

<div class="grid2">
<div>

  <div class="card">
    <div class="card-head"><h2>Recent trust transfers<?php echo ops_admin_help_button('Recent trust transfers', 'This table shows the latest movement of money between trust pathways and accounts. Use it to understand what has already happened, not as the primary editing surface.'); ?></h2></div>
    <?php if (empty($transfers)): ?>
      <div class="card-body"><p class="empty">No trust transfers recorded yet.</p></div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Reference</th><th>Type<?php echo ops_admin_help_button('Transfer type', 'The pathway of money movement, for example A to B, A to C, reinvestment, or grant flow. This tells you what the transfer is meant to achieve.'); ?></th><th>Amount</th><th>Status<?php echo ops_admin_help_button('Status', 'Status tells you whether the transfer or run is complete, still in progress, or already problematic. Use the page status guide for the meaning of each label.'); ?></th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($transfers as $t): ?>
            <tr>
              <td class="mono"><?php echo ac_h($t['transfer_ref']); ?></td>
              <td><?php echo ac_h(ac_transfer_label($t['transfer_type'])); ?></td>
              <td style="font-weight:600"><?php echo ac_dollars((int)$t['amount_cents']); ?></td>
              <td><span class="st <?php echo ac_status_class($t['status']); ?>"><?php echo ac_h($t['status']); ?></span></td>
              <td style="color:var(--sub);font-size:12px"><?php echo ac_h($t['dated']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>Recent expenses (Trust A)</h2></div>
    <?php if (empty($expenses)): ?>
      <div class="card-body"><p class="empty">No expenses recorded yet.</p></div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Reference</th><th>Category</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($expenses as $e): ?>
            <tr>
              <td class="mono"><?php echo ac_h($e['expense_ref']); ?></td>
              <td><?php echo ac_h(ucwords(str_replace('_', ' ', $e['expense_category']))); ?></td>
              <td style="font-weight:600"><?php echo ac_dollars((int)$e['amount_cents']); ?></td>
              <td><span class="st <?php echo ac_status_class($e['status']); ?>"><?php echo ac_h($e['status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>
<div>

  <div class="card">
    <div class="card-head"><h2>Trust accounts<?php echo ops_admin_help_button('Trust accounts', 'These are the ledger or operating accounts used by the trust structure. They tell you where money is meant to sit or move, not necessarily the live bank balance.'); ?></h2></div>
    <div class="card-body">
      <?php if (empty($accounts)): ?>
        <p class="empty">No trust accounts configured.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($accounts as $ta): ?>
          <div class="acct-pill">
            <span class="acct-dot <?php echo strtolower($ta['sub_trust']); ?>"></span>
            <span>Sub-Trust <?php echo ac_h($ta['sub_trust']); ?></span>
            <span style="color:var(--dim)"><?php echo ac_h($ta['account_name']); ?> (<?php echo ac_h($ta['direction']); ?>)</span>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Donation ledger</h2></div>
    <div class="card-body">
      <div style="display:flex;gap:20px">
        <div>
          <div style="font-size:1.3rem;font-weight:800;color:<?php echo $dlColor; ?>"><?php echo $dlPending; ?></div>
          <div style="font-size:.72rem;color:var(--sub);text-transform:uppercase;margin-top:2px">Pending to C</div>
        </div>
        <div>
          <div style="font-size:1.3rem;font-weight:800;color:var(--ok)"><?php echo $dlDone; ?></div>
          <div style="font-size:.72rem;color:var(--sub);text-transform:uppercase;margin-top:2px">Transferred</div>
        </div>
      </div>
      <?php if ($dlPending === 0 && $dlDone === 0): ?>
        <p style="color:var(--dim);font-size:12px;margin-top:10px">No Donation COG$ minted yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Trust B distribution runs</h2></div>
    <div class="card-body">
      <?php if (empty($distRuns)): ?>
        <p class="empty">No distribution runs yet.</p>
      <?php else: ?>
        <?php foreach ($distRuns as $dr): ?>
          <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--line)">
            <div style="display:flex;justify-content:space-between">
              <span class="mono" style="font-weight:700"><?php echo ac_h($dr['run_ref']); ?></span>
              <span class="st <?php echo ac_status_class($dr['status']); ?>"><?php echo ac_h($dr['status']); ?></span>
            </div>
            <div style="font-size:12px;color:var(--sub);margin-top:4px">
              Pool: <?php echo ac_dollars((int)$dr['total_pool_cents']); ?> | Due: <?php echo ac_h($dr['distribution_due_by']); ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>First Nations grant compliance</h2></div>
    <div class="card-body">
      <?php if (empty($fnData)): ?>
        <p class="empty">No grants recorded yet. Min 30% of annual grants must go to First Nations.</p>
      <?php else: ?>
        <?php foreach ($fnData as $fy): ?>
          <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between">
              <strong>FY <?php echo ac_h($fy['financial_year'] ?? ''); ?></strong>
              <?php $fnOk = ($fy['compliance_status'] ?? '') === 'COMPLIANT'; ?>
              <span class="st <?php echo $fnOk ? 'st-ok' : 'st-bad'; ?>"><?php echo ac_h($fy['fn_percentage'] ?? '0'); ?>% FN</span>
            </div>
            <div style="font-size:12px;color:var(--sub);margin-top:4px">
              Total: <?php echo ac_dollars((int)($fy['total_grants_cents'] ?? 0)); ?> | FN: <?php echo ac_dollars((int)($fy['fn_grants_cents'] ?? 0)); ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>
</div>

<?php endif; ?>

</main>
</div>

<!-- ── Invariant drill modal ─────────────────────────────────────────────── -->
<div class="inv-modal-bg" id="invModalBg">
  <div class="inv-modal">
    <div class="inv-modal-head">
      <h3 id="invModalTitle">Invariant violations</h3>
      <button class="inv-modal-close" id="invModalClose" type="button">✕</button>
    </div>
    <div class="inv-modal-body" id="invModalBody">Loading…</div>
  </div>
</div>

<script>
(function(){
  /* ── Compliance tab switcher ── */
  var tabs = document.querySelectorAll('.comp-tab[data-panel]');
  tabs.forEach(function(tab){
    if(tab.classList.contains('ok')) return;
    tab.addEventListener('click', function(){
      var pid = tab.getAttribute('data-panel');
      tabs.forEach(function(t){ t.classList.remove('active'); });
      document.querySelectorAll('.comp-panel').forEach(function(p){ p.classList.remove('active'); });
      tab.classList.add('active');
      var panel = document.getElementById(pid);
      if(panel) panel.classList.add('active');
    });
  });

  /* ── Invariant drill modal ── */
  var bg    = document.getElementById('invModalBg');
  var title = document.getElementById('invModalTitle');
  var body  = document.getElementById('invModalBody');
  var close = document.getElementById('invModalClose');

  var invData = <?php
    // Pre-render invariant detail data as JSON for JS
    $invDetail = [];
    foreach ($invariants as $inv) {
        $code = $inv['code'];
        $viol = (int)$inv['violation_count'];
        if ($viol === 0) { $invDetail[$code] = []; continue; }
        // fetch detail rows per code
        $detail = [];
        try {
            switch($code) {
                case 'I3':
                    $detail = ac_rows($pdo, "SELECT event_ref AS ref, event_date AS d1, deadline_date AS deadline, CONCAT(days_overdue,' days overdue') AS note FROM v_invariant_i3_5bizday_transfer ORDER BY days_overdue DESC");
                    break;
                case 'I4':
                    $detail = ac_rows($pdo, "SELECT inflow_ref AS ref, inflow_date AS d1, deadline_date AS deadline, CONCAT(days_overdue,' days overdue | inflow ',FORMAT(inflow_cents/100,2),' distributed ',FORMAT(distributed_cents/100,2)) AS note FROM v_invariant_i4_60day_distribution ORDER BY days_overdue DESC");
                    break;
                case 'I5':
                    $detail = ac_rows($pdo, "SELECT payment_ref AS ref, received_date AS d1, deadline_date AS deadline, CONCAT(days_overdue,' days overdue') AS note FROM v_invariant_i5_2bizday_direct_c ORDER BY days_overdue DESC");
                    break;
                case 'I12':
                    $detail = ac_rows($pdo, "SELECT token_key AS ref, DATE(requested_at) AS d1, DATE(locked_until) AS deadline, CONCAT('Locked until ',locked_until) AS note FROM v_invariant_i12_stewardship_lock ORDER BY locked_until DESC");
                    break;
                default:
                    $detail = [['ref'=>'(detail not available for '.$code.')','d1'=>'','deadline'=>'','note'=>'Query '.$code.' view directly in phpMyAdmin for row detail.']];
            }
        } catch(Throwable $ex) {
            $detail = [['ref'=>'Error querying detail','d1'=>'','deadline'=>'','note'=>$ex->getMessage()]];
        }
        $invDetail[$code] = $detail;
    }
    echo json_encode($invDetail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
  ?>;

  var invNames = <?php
    $nm = [];
    foreach($invariants as $inv) $nm[$inv['code']] = $inv['name'];
    echo json_encode($nm);
  ?>;

  document.querySelectorAll('.inv-pill.err').forEach(function(pill){
    pill.addEventListener('click', function(){
      var code = pill.querySelector('.inv-code') ? pill.querySelector('.inv-code').textContent.trim() : '';
      if(!code) return;
      title.textContent = code + ' — ' + (invNames[code] || 'Violations');
      var rows = invDetail[code] || [];
      if(rows.length === 0){
        body.innerHTML = '<p style="color:var(--dim);font-size:13px">No detail rows available.</p>';
      } else {
        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px">'
          + '<thead><tr>'
          + '<th style="text-align:left;padding:6px 8px;color:var(--dim);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--line)">Reference</th>'
          + '<th style="text-align:left;padding:6px 8px;color:var(--dim);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--line)">Date</th>'
          + '<th style="text-align:left;padding:6px 8px;color:var(--dim);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--line)">Deadline</th>'
          + '<th style="text-align:left;padding:6px 8px;color:var(--dim);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--line)">Note</th>'
          + '</tr></thead><tbody>';
        rows.forEach(function(r){
          html += '<tr>'
            + '<td style="padding:7px 8px;border-top:1px solid var(--line);font-family:monospace;font-size:11px;color:var(--err)">' + esc(r.ref||'') + '</td>'
            + '<td style="padding:7px 8px;border-top:1px solid var(--line);color:var(--sub)">' + esc(r.d1||'') + '</td>'
            + '<td style="padding:7px 8px;border-top:1px solid var(--line);color:var(--warn)">' + esc(r.deadline||'') + '</td>'
            + '<td style="padding:7px 8px;border-top:1px solid var(--line);color:var(--sub);font-size:11px">' + esc(r.note||'') + '</td>'
            + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
      }
      bg.classList.add('open');
    });
  });

  function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

  close.addEventListener('click', function(){ bg.classList.remove('open'); });
  bg.addEventListener('click', function(e){ if(e.target===bg) bg.classList.remove('open'); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') bg.classList.remove('open'); });
})();
</script>
</body>
</html>

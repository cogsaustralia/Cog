<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();
$adminId = ops_admin_id();

function ex_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$hasTable = function_exists('ops_has_table') && ops_has_table($pdo, 'trust_expenses');
$flash = null;
$error = null;

$categories = [
    'brokerage'         => 'ASX brokerage',
    'compliance'        => 'Compliance / audit / legal',
    'technology'        => 'Technology / hosting / dev',
    'insurance'         => 'Insurance',
    'governance'        => 'Governance / AGM / FNAC',
    'administration'    => 'General administration',
    'stripe_fees'       => 'Stripe processing fees',
    'tax_withholding'   => 'Tax / withholding',
    'other'             => 'Other',
];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTable) {
    admin_csrf_verify();
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'add_expense') {
            $cat         = trim((string)($_POST['category'] ?? ''));
            $desc        = trim((string)($_POST['description'] ?? ''));
            $amountStr   = trim((string)($_POST['amount'] ?? ''));
            $gstStr      = trim((string)($_POST['gst'] ?? '0'));
            $expDate     = trim((string)($_POST['expense_date'] ?? ''));
            $payee       = trim((string)($_POST['payee_name'] ?? ''));
            $payeeAbn    = trim((string)($_POST['payee_abn'] ?? ''));
            $invoiceRef  = trim((string)($_POST['invoice_ref'] ?? ''));
            $method      = trim((string)($_POST['payment_method'] ?? ''));
            $bankRef     = trim((string)($_POST['bank_reference'] ?? ''));
            $notes       = trim((string)($_POST['notes'] ?? ''));
            $markPaid    = !empty($_POST['mark_paid']);

            if ($desc === '') throw new RuntimeException('Description is required.');
            if (!is_numeric($amountStr) || (float)$amountStr <= 0) throw new RuntimeException('Valid amount is required.');
            if ($expDate === '') throw new RuntimeException('Expense date is required.');

            // Map stripe_fees to 'other' for the enum (or administration)
            $dbCat = $cat;
            if ($dbCat === 'stripe_fees') $dbCat = 'other';

            $amountCents = (int)round((float)$amountStr * 100);
            $gstCents    = (int)round((float)$gstStr * 100);
            $status      = $markPaid ? 'paid' : 'approved';
            $paidAt      = $markPaid ? date('Y-m-d H:i:s') : null;

            // Generate ref
            $refDate = str_replace('-', '', $expDate);
            $refRand = strtoupper(substr(md5(uniqid('', true)), 0, 6));
            $expRef  = 'EXP-' . $refDate . '-' . $refRand;

            $stmt = $pdo->prepare("
                INSERT INTO trust_expenses
                  (expense_ref, expense_category, description, amount_cents, gst_cents,
                   currency_code, expense_date, payee_name, payee_abn, invoice_reference,
                   trust_account_id, payment_method, bank_reference, status, paid_at,
                   notes, created_by_admin_id, approved_by_admin_id, approved_at)
                VALUES
                  (?, ?, ?, ?, ?, 'AUD', ?, ?, ?, ?, 6, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $expRef, $dbCat, $desc, $amountCents, $gstCents,
                $expDate, $payee ?: null, $payeeAbn ?: null, $invoiceRef ?: null,
                $method ?: null, $bankRef ?: null, $status, $paidAt,
                $notes ?: null, $adminId, $adminId, date('Y-m-d H:i:s'),
            ]);

            $newExpenseId = (int) $pdo->lastInsertId();

            // --- Godley ledger emission (Stage 2) — only on paid status ---
            $ledgerEmitter = __DIR__ . '/includes/LedgerEmitter.php';
            if ($status === 'paid' && file_exists($ledgerEmitter)) {
                require_once $ledgerEmitter;
                if (class_exists('LedgerEmitter')) {
                    $godleyRef = "GDLY-{$expRef}";
                    $entries = LedgerEmitter::buildOperatingExpenseEntries($amountCents, $gstCents);
                    $res = LedgerEmitter::emitTransaction(
                        $pdo, $godleyRef, 'trust_expenses', $newExpenseId, $entries, $expDate
                    );
                    if ($res['status'] === 'error') {
                        $flash = 'Expense recorded but Godley emission failed: ' . $res['message'];
                    }
                }
            }

            $flash = $flash ?: ('Expense recorded: ' . $expRef . ' — $' . number_format($amountCents / 100, 2) . ' (' . $status . ')');

        } elseif ($action === 'mark_expense_paid') {
            $expId = (int)($_POST['expense_id'] ?? 0);
            $bankRef = trim((string)($_POST['bank_reference'] ?? ''));
            if ($expId < 1) throw new RuntimeException('Expense ID required.');

            $pdo->prepare("
                UPDATE trust_expenses
                SET status = 'paid', paid_at = NOW(), bank_reference = ?, updated_at = NOW()
                WHERE id = ? AND status != 'paid'
            ")->execute([$bankRef ?: null, $expId]);

            // --- Godley ledger emission on payment (Stage 2) ---
            $ledgerEmitter = __DIR__ . '/includes/LedgerEmitter.php';
            if (file_exists($ledgerEmitter)) {
                require_once $ledgerEmitter;
                if (class_exists('LedgerEmitter')) {
                    $expRow = $pdo->prepare("SELECT expense_ref, amount_cents, gst_cents, expense_date FROM trust_expenses WHERE id = ?");
                    $expRow->execute([$expId]);
                    $e = $expRow->fetch(PDO::FETCH_ASSOC);
                    if ($e) {
                        $godleyRef = "GDLY-{$e['expense_ref']}";
                        $entries = LedgerEmitter::buildOperatingExpenseEntries(
                            (int)$e['amount_cents'], (int)$e['gst_cents']
                        );
                        LedgerEmitter::emitTransaction(
                            $pdo, $godleyRef, 'trust_expenses', $expId, $entries, $e['expense_date']
                        );
                    }
                }
            }

            $flash = 'Expense #' . $expId . ' marked as paid.';

        } elseif ($action === 'cancel_expense') {
            $expId = (int)($_POST['expense_id'] ?? 0);
            if ($expId < 1) throw new RuntimeException('Expense ID required.');

            $pdo->prepare("
                UPDATE trust_expenses SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status != 'paid'
            ")->execute([$expId]);

            $flash = 'Expense #' . $expId . ' cancelled.';
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Load expenses
$allExpenses = $hasTable ? (function() use ($pdo) {
    try {
        $s = $pdo->query("SELECT * FROM trust_expenses ORDER BY expense_date DESC, id DESC LIMIT 50");
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
})() : [];

// Totals
$totalPaid = 0; $totalApproved = 0; $totalGst = 0;
foreach ($allExpenses as $e) {
    if ($e['status'] === 'paid') { $totalPaid += (int)$e['amount_cents']; $totalGst += (int)$e['gst_cents']; }
    if ($e['status'] === 'approved') { $totalApproved += (int)$e['amount_cents']; }
}

function ex_status_class(string $s): string {
    if ($s === 'paid') return 'st-ok';
    if ($s === 'approved' || $s === 'draft') return 'st-warn';
    if ($s === 'cancelled') return 'st-bad';
    return 'st-dim';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./assets/admin.css">
<title>Expenses | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>.main { padding:24px 28px; min-width:0; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); cursor:pointer; }
.btn-gold { background:var(--gold); color:#201507; border-color:rgba(212,178,92,.3); }

.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }

.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:22px; }
.stat { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r2); padding:16px 18px; text-align:center; }
.stat-val { font-size:1.5rem; font-weight:800; margin-bottom:4px; }
.stat-label { font-size:.72rem; color:var(--sub); text-transform:uppercase; letter-spacing:.06em; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:700px) { .form-grid { grid-template-columns:1fr; } }
.field { display:flex; flex-direction:column; gap:5px; }
.field label { font-size:.82rem; color:var(--sub); }
.field input, .field select, .field textarea { background:#0f1720; border:1px solid var(--line); color:var(--text); padding:9px 11px; border-radius:10px; font:inherit; font-size:.9rem; }
.field textarea { min-height:60px; resize:vertical; }
.field-full { grid-column:1/-1; }
.check-row { display:flex; align-items:center; gap:8px; grid-column:1/-1; padding:4px 0; }
.check-row input[type=checkbox] { width:18px; height:18px; }

table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:9px 10px; font-size:13px; border-top:1px solid var(--line); vertical-align:top; }
th { color:var(--dim); font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-top:none; }
.mono { font-family:monospace; font-size:12px; }
.st { display:inline-block; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700; text-transform:uppercase; }
.st-ok { background:var(--okb); color:var(--ok); }
.st-warn { background:var(--warnb); color:var(--warn); }
.st-bad { background:var(--errb); color:var(--err); }
.st-dim { background:rgba(255,255,255,.04); color:var(--dim); }

.msg { padding:12px 14px; border-radius:12px; margin-bottom:16px; }
.msg.ok { background:rgba(47,143,87,.12); border:1px solid rgba(47,143,87,.35); color:#b8efc8; }
.msg.err { background:rgba(200,61,75,.12); border:1px solid rgba(200,61,75,.35); color:#ffb4be; }
.empty { color:var(--dim); font-size:13px; padding:20px 0; text-align:center; }
.inline-form { display:inline-flex; gap:4px; align-items:center; }
.inline-form input { background:#0f1720; border:1px solid var(--line); color:var(--text); padding:5px 8px; border-radius:6px; font:inherit; font-size:12px; width:120px; }
.mini-btn { padding:5px 10px; border-radius:6px; font-size:11px; font-weight:700; border:1px solid var(--line); background:rgba(255,255,255,.04); color:var(--text); cursor:pointer; }
.mini-btn.green { background:var(--okb); border-color:rgba(82,184,122,.25); color:var(--ok); }
.mini-btn.red { background:var(--errb); border-color:rgba(196,96,96,.25); color:var(--err); }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('expenses'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Trust A expenses<?php echo ops_admin_help_button('Trust A expenses', 'Use this page to create, review, and update operating expense records paid from the Sub-Trust A operating account. It is the practical recording surface for costs, while Accounting remains the control overview.'); ?></h1>
    <p>Record and manage all Foundation expenses. All expenses are paid from Sub-Trust A operating account.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="<?php echo ex_h(admin_url('accounting.php')); ?>">Accounting</a>
    <a class="btn" href="<?php echo ex_h(admin_url('dashboard.php')); ?>">Dashboard</a>
  </div>
</div>

<?php echo ops_admin_info_panel(
    'Finance operations',
    'What this page does',
    'Use this page to record and manage operating expenses. It is the practical expense-entry and payment-tracking page for Trust A, while the Accounting page remains the wider review and control surface.',
    [
        'Create a new expense with category, amount, payee, and supporting notes.',
        'Mark approved expenses as paid once money has actually left the operating account.',
        'Cancel items that should not proceed before they are paid.',
        'Review recent expense history and GST totals.',
    ]
); ?>

<?php echo ops_admin_workflow_panel(
    'Typical workflow',
    'Work from recording to settlement so the expense log stays accurate and auditable.',
    [
        ['title' => 'Record the expense', 'body' => 'Enter category, amount, date, payee, and any invoice or bank reference details.'],
        ['title' => 'Review the status', 'body' => 'New entries should remain draft or approved until you are ready to confirm payment.'],
        ['title' => 'Mark as paid only after payment occurs', 'body' => 'Use the paid action once the actual outgoing payment has happened.'],
        ['title' => 'Use Accounting for control review', 'body' => 'Return to Accounting if you need to understand how expenses affect trust balances and wider fund movement.'],
    ]
); ?>

<?php echo ops_admin_guide_panel(
    'How to read this page',
    'This page mixes entry and review functions, so use the top form for new records and the lower table for existing ones.',
    [
        ['title' => 'Top metrics', 'body' => 'Quick totals for paid expenses, approved unpaid items, GST, and total entries.'],
        ['title' => 'Record new expense', 'body' => 'The main creation form for new expense items.'],
        ['title' => 'Expense register', 'body' => 'The latest expense records with status and inline actions.'],
        ['title' => 'Inline actions', 'body' => 'Small action buttons for marking payment or cancelling unpaid items.'],
    ]
); ?>

<?php echo ops_admin_status_panel(
    'Status guide',
    'Expense status tells you whether an item is only recorded, ready for payment, settled, or abandoned.',
    [
        ['label' => 'Paid', 'body' => 'The expense has been settled and should align with the real outgoing payment.'],
        ['label' => 'Approved / Draft', 'body' => 'The item has been recorded but is not yet a completed outgoing payment.'],
        ['label' => 'Cancelled', 'body' => 'The item should not proceed and should no longer be treated as payable.'],
    ]
); ?>

<?php if (!$hasTable): ?>
  <div class="msg err">Accounting tables not found. Run cogs_accounting_schema.sql first.</div>
<?php else: ?>

<?php if ($flash): ?><div class="msg ok"><?php echo ex_h($flash); ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg err"><?php echo ex_h($error); ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-val" style="color:var(--gold)"><?php echo '$' . number_format($totalPaid / 100, 2); ?></div><div class="stat-label">Total paid</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--warn)"><?php echo '$' . number_format($totalApproved / 100, 2); ?></div><div class="stat-label">Approved unpaid</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--sub)"><?php echo '$' . number_format($totalGst / 100, 2); ?></div><div class="stat-label">GST component</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--text)"><?php echo count($allExpenses); ?></div><div class="stat-label">Total entries</div></div>
</div>

<!-- Add expense form -->
<div class="card">
  <div class="card-head"><h2>Record new expense<?php echo ops_admin_help_button('Record new expense', 'Use this form to create a new expense record. Enter the commercial facts accurately first; payment confirmation can be completed later if needed.'); ?></h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo ex_h(admin_csrf_token()); ?>">
      <input type="hidden" name="action" value="add_expense">
      <div class="form-grid">
        <div class="field">
          <label>Category<?php echo ops_admin_help_button('Category', 'Choose the category that best describes the nature of the cost. This affects later reporting and accounting interpretation.'); ?></label>
          <select name="category" required>
            <?php foreach ($categories as $k => $v): ?>
              <option value="<?php echo ex_h($k); ?>"><?php echo ex_h($v); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Expense date</label>
          <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="field field-full">
          <label>Description<?php echo ops_admin_help_button('Description', 'Describe the expense clearly enough that another operator can recognise what was paid for without needing outside context.'); ?></label>
          <input type="text" name="description" placeholder="e.g. Stripe processing fee — April 2026" required>
        </div>
        <div class="field">
          <label>Amount (AUD excl GST)</label>
          <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
        </div>
        <div class="field">
          <label>GST component (AUD)</label>
          <input type="number" name="gst" min="0" step="0.01" value="0.00">
        </div>
        <div class="field">
          <label>Payee name</label>
          <input type="text" name="payee_name" placeholder="e.g. Stripe, Serversaurus, etc.">
        </div>
        <div class="field">
          <label>Payee ABN</label>
          <input type="text" name="payee_abn" placeholder="Optional">
        </div>
        <div class="field">
          <label>Invoice / reference</label>
          <input type="text" name="invoice_ref" placeholder="Invoice number or reference">
        </div>
        <div class="field">
          <label>Payment method</label>
          <select name="payment_method">
            <option value="">— select —</option>
            <option value="EFT">EFT / bank transfer</option>
            <option value="STRIPE">Stripe</option>
            <option value="CARD">Credit/debit card</option>
            <option value="DIRECT_DEBIT">Direct debit</option>
            <option value="CASH">Cash</option>
            <option value="OTHER">Other</option>
          </select>
        </div>
        <div class="field">
          <label>Bank reference</label>
          <input type="text" name="bank_reference" placeholder="Bank statement ref">
        </div>
        <div class="field field-full">
          <label>Notes</label>
          <textarea name="notes" placeholder="Optional notes"></textarea>
        </div>
        <div class="check-row">
          <input type="checkbox" name="mark_paid" id="mark_paid" value="1" checked>
          <label for="mark_paid" style="font-size:.9rem;color:var(--text)">Mark as paid now</label>
        </div>
        <div class="field-full" style="padding-top:4px">
          <button type="submit" class="btn btn-gold">Record expense</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Expense list -->
<div class="card">
  <div class="card-head"><h2>Expense history</h2></div>
  <?php if (empty($allExpenses)): ?>
    <div class="card-body"><p class="empty">No expenses recorded yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr><th>Ref</th><th>Date</th><th>Category<?php echo ops_admin_help_button('Category', 'The reporting category assigned to the expense when it was created.'); ?></th><th>Description</th><th>Payee</th><th>Amount</th><th>GST</th><th>Status<?php echo ops_admin_help_button('Status', 'Status shows whether the expense is only recorded, ready to pay, already settled, or cancelled.'); ?></th><th>Actions<?php echo ops_admin_help_button('Actions', 'Use these controls to mark an unpaid item as paid or cancel it before settlement.'); ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($allExpenses as $e):
          $isPaid = $e['status'] === 'paid';
          $isCancelled = $e['status'] === 'cancelled';
        ?>
          <tr>
            <td class="mono"><?php echo ex_h($e['expense_ref']); ?></td>
            <td style="white-space:nowrap"><?php echo ex_h($e['expense_date']); ?></td>
            <td><?php echo ex_h(ucwords(str_replace('_', ' ', $e['expense_category']))); ?></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--sub)"><?php echo ex_h($e['description']); ?></td>
            <td style="color:var(--sub)"><?php echo ex_h($e['payee_name'] ?? '—'); ?></td>
            <td style="font-weight:600"><?php echo '$' . number_format((int)$e['amount_cents'] / 100, 2); ?></td>
            <td style="color:var(--dim)"><?php echo (int)$e['gst_cents'] > 0 ? '$' . number_format((int)$e['gst_cents'] / 100, 2) : '—'; ?></td>
            <td><span class="st <?php echo ex_status_class($e['status']); ?>"><?php echo ex_h($e['status']); ?></span></td>
            <td style="white-space:nowrap">
              <?php if (!$isPaid && !$isCancelled): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?php echo ex_h(admin_csrf_token()); ?>">
                  <input type="hidden" name="action" value="mark_expense_paid">
                  <input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>">
                  <input type="text" name="bank_reference" placeholder="Bank ref">
                  <button type="submit" class="mini-btn green">Pay</button>
                </form>
                <form method="post" class="inline-form" style="margin-left:4px" onsubmit="return confirm('Cancel this expense?')">
                  <input type="hidden" name="_csrf" value="<?php echo ex_h(admin_csrf_token()); ?>">
                  <input type="hidden" name="action" value="cancel_expense">
                  <input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>">
                  <button type="submit" class="mini-btn red">Cancel</button>
                </form>
              <?php elseif ($isPaid): ?>
                <span style="font-size:11px;color:var(--dim)"><?php echo ex_h($e['bank_reference'] ?? ''); ?></span>
              <?php else: ?>
                <span style="font-size:11px;color:var(--dim)">Cancelled</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

</main>
</div>
</body>
</html>

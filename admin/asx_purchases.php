<?php
declare(strict_types=1);
ob_start(); // Outer buffer — catches any accidental output (warnings, notices, whitespace from includes) before DOCTYPE
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$adminUserId = ops_admin_id();
$flash = null; $flashType = 'ok';

function ap_money(int|float $cents): string {
    $val = ((float)$cents) / 100;
    // Sub-dollar values need 4 decimal places to show e.g. $0.1550 correctly
    $dp = ($val > 0 && $val < 1.00) ? 4 : 2;
    return '$' . number_format($val, $dp);
}
function ap_num($n, int $dp = 0): string { return number_format((float)$n, $dp); }
function ap_trade_ref(): string { return 'ASX-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6)); }
function ap_recalc_holding(PDO $pdo, int $holdingId): void {
    $row = ops_fetch_one($pdo, "SELECT COALESCE(SUM(units),0) AS units_held, COALESCE(SUM(total_cost_cents + brokerage_cents),0) AS total_cost_cents FROM asx_trades WHERE holding_id = ? AND status = 'settled'", [$holdingId]);
    $units = (int)($row['units_held'] ?? 0);
    $total = (float)($row['total_cost_cents'] ?? 0);
    $avg   = $units > 0 ? round($total / $units, 4) : 0;
    $pdo->prepare('UPDATE asx_holdings SET units_held=?, average_cost_cents=?, total_cost_cents=?, updated_at=NOW() WHERE id=?')
        ->execute([$units, $avg, $total, $holdingId]);
}

$holdings = ops_fetch_all($pdo, 'SELECT id, ticker, company_name FROM asx_holdings ORDER BY ticker ASC');
$focusHolding = (int)($_GET['holding_id'] ?? 0);
$editTradeId  = (int)($_GET['edit_trade'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_purchase') {
            $holdingId = (int)($_POST['holding_id'] ?? 0);
            $tradeType = (string)($_POST['trade_type'] ?? 'buy');
            $units = (int)($_POST['units'] ?? 0);
            $price = (float)($_POST['price_per_share'] ?? 0);
            $brokerage = (float)($_POST['brokerage'] ?? 0);
            $tradeDate = trim((string)($_POST['trade_date'] ?? ''));
            $settlementDate = trim((string)($_POST['settlement_date'] ?? ''));
            $fundedBy = (string)($_POST['funded_by'] ?? 'member_payment');
            $status = (string)($_POST['status'] ?? 'pending');
            $chessRef = trim((string)($_POST['chess_confirmation_ref'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($holdingId < 1) throw new RuntimeException('Select an ASX holding.');
            if (!in_array($tradeType, ['buy','reinvestment','legacy_seed'], true)) $tradeType = 'buy';
            if ($units < 1) throw new RuntimeException('Number of shares is required.');
            if ($price <= 0) throw new RuntimeException('Price per share must be greater than zero.');
            if ($tradeDate === '') throw new RuntimeException('Trade date is required.');
            if (!in_array($fundedBy, ['member_payment','bds_reinvestment','dds_reinvestment'], true)) $fundedBy = 'member_payment';
            if (!in_array($status, ['pending','settled','failed'], true)) $status = 'pending';
            $priceCents     = round($price * 100, 4);   // stored as DECIMAL(12,4) — preserves e.g. 15.5c
            $brokerageCents = round($brokerage * 100, 4);
            $totalCost      = round($units * $priceCents, 4);
            $ref = ap_trade_ref();
            $pdo->prepare("INSERT INTO asx_trades (trade_ref, holding_id, trade_type, units, price_cents_per_unit, total_cost_cents, brokerage_cents, trade_date, settlement_date, funded_by, chess_confirmation_ref, status, notes, created_by_admin_id, created_by_admin_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NOW())")
                ->execute([$ref,$holdingId,$tradeType,$units,$priceCents,$totalCost,$brokerageCents,$tradeDate,$settlementDate !== '' ? $settlementDate : null,$fundedBy,$chessRef !== '' ? $chessRef : null,$status,$notes !== '' ? $notes : null,$adminUserId]);
            ap_recalc_holding($pdo, $holdingId);
            $flash = 'ASX purchase lot recorded.';
            header('Location: ./asx_purchases.php?holding_id=' . $holdingId . '&saved=1'); exit;
        }
        if ($action === 'upload_document') {
            $holdingId = (int)($_POST['holding_id'] ?? 0);
            $tradeId   = (int)($_POST['trade_id'] ?? 0);
            $docType   = (string)($_POST['document_type'] ?? 'broker_confirmation');
            $notes     = trim((string)($_POST['notes'] ?? ''));
            $isLegacySeed = !empty($_POST['is_legacy_seed']);
            if ($holdingId < 1) throw new RuntimeException('Select an ASX holding.');
            if (empty($_FILES['trade_document']['tmp_name'])) throw new RuntimeException('No file was uploaded.');
            if (!function_exists('ops_store_asx_trade_document')) throw new RuntimeException('Document vault functions not loaded.');
            $docId = ops_store_asx_trade_document($pdo, $holdingId, $adminUserId, $_FILES['trade_document'], $docType, $tradeId, $isLegacySeed, $notes);
            $flash = 'Document uploaded, SHA-256 hashed, and anchored in the evidence vault.' . ($isLegacySeed ? ' Standalone chain attestation record created.' : '');
            header('Location: ./asx_purchases.php?holding_id=' . $holdingId . '&saved=1'); exit;
        }
        if ($action === 'edit_trade') {
            $tradeId    = (int)($_POST['trade_id'] ?? 0);
            $tradeType  = (string)($_POST['trade_type'] ?? 'buy');
            $units      = (int)($_POST['units'] ?? 0);
            $price      = (float)($_POST['price_per_share'] ?? 0);
            $brokerage  = (float)($_POST['brokerage'] ?? 0);
            $tradeDate  = trim((string)($_POST['trade_date'] ?? ''));
            $settlementDate = trim((string)($_POST['settlement_date'] ?? ''));
            $fundedBy   = (string)($_POST['funded_by'] ?? 'member_payment');
            $status     = (string)($_POST['status'] ?? 'pending');
            $chessRef   = trim((string)($_POST['chess_confirmation_ref'] ?? ''));
            $notesTxt   = trim((string)($_POST['notes'] ?? ''));
            if ($tradeId < 1) throw new RuntimeException('Trade ID missing.');
            if (!in_array($tradeType, ['buy','reinvestment','legacy_seed'], true)) $tradeType = 'buy';
            if ($units < 1) throw new RuntimeException('Shares must be greater than zero.');
            if ($price <= 0) throw new RuntimeException('Price per share must be greater than zero.');
            if ($tradeDate === '') throw new RuntimeException('Trade date is required.');
            if (!in_array($fundedBy, ['member_payment','bds_reinvestment','dds_reinvestment'], true)) $fundedBy = 'member_payment';
            if (!in_array($status, ['pending','settled','failed'], true)) $status = 'pending';
            $priceCents     = round($price * 100, 4);
            $brokerageCents = round($brokerage * 100, 4);
            $totalCost      = round($units * $priceCents, 4);
            $row = ops_fetch_one($pdo, 'SELECT id, holding_id FROM asx_trades WHERE id = ? LIMIT 1', [$tradeId]);
            if (!$row) throw new RuntimeException('Trade lot not found.');
            $pdo->prepare('UPDATE asx_trades SET trade_type=?, units=?, price_cents_per_unit=?, total_cost_cents=?, brokerage_cents=?, trade_date=?, settlement_date=?, funded_by=?, status=?, chess_confirmation_ref=?, notes=?, updated_at=NOW() WHERE id=?')
                ->execute([$tradeType, $units, $priceCents, $totalCost, $brokerageCents, $tradeDate, $settlementDate !== '' ? $settlementDate : null, $fundedBy, $status, $chessRef !== '' ? $chessRef : null, $notesTxt !== '' ? $notesTxt : null, $tradeId]);
            ap_recalc_holding($pdo, (int)$row['holding_id']);
            $flash = 'Trade lot updated.';
            header('Location: ./asx_purchases.php?holding_id=' . (int)$row['holding_id'] . '&saved=1'); exit;
        }
        if ($action === 'update_status') {
            $tradeId = (int)($_POST['trade_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'pending');
            if ($tradeId < 1) throw new RuntimeException('Trade ID missing.');
            if (!in_array($status, ['pending','settled','failed'], true)) $status = 'pending';
            $row = ops_fetch_one($pdo, 'SELECT id, holding_id, total_cost_cents, brokerage_cents, trade_date, status FROM asx_trades WHERE id = ? LIMIT 1', [$tradeId]);
            if (!$row) throw new RuntimeException('Trade lot not found.');
            $prevStatus = (string)$row['status'];
            $pdo->prepare('UPDATE asx_trades SET status=?, updated_at=NOW() WHERE id=?')->execute([$status, $tradeId]);
            ap_recalc_holding($pdo, (int)$row['holding_id']);

            // Emit Godley ASX acquisition entry when status transitions to 'settled'
            if ($status === 'settled' && $prevStatus !== 'settled') {
                $totalCostCents = (int)round((float)$row['total_cost_cents']);
                $brokerageCents = (int)round((float)$row['brokerage_cents']);
                $tradeDate      = (string)$row['trade_date'];
                $godleyRef      = 'GDLY-ASX-SETTLE-' . $tradeId . '-' . date('Ymd');
                $ledgerEmitter  = __DIR__ . '/includes/LedgerEmitter.php';
                if (file_exists($ledgerEmitter)) {
                    require_once $ledgerEmitter;
                    if (class_exists('LedgerEmitter')) {
                        // Acquisition cost (shares acquired)
                        $res = LedgerEmitter::emitTransaction(
                            $pdo, $godleyRef, 'asx_trades', $tradeId,
                            LedgerEmitter::buildASXAcquisitionEntries($totalCostCents),
                            $tradeDate
                        );
                        if ($res['status'] === 'error') {
                            $flash = 'Status updated but Godley emission failed: ' . $res['message'];
                        }
                        // Brokerage recorded as operating expense from Admin Fund
                        if ($brokerageCents > 0) {
                            $brokerageRef = $godleyRef . '-BROK';
                            LedgerEmitter::emitTransaction(
                                $pdo, $brokerageRef, 'asx_trades', $tradeId,
                                LedgerEmitter::buildOperatingExpenseEntries($brokerageCents, 0),
                                $tradeDate
                            );
                        }
                    }
                }
            }

            $flash = $flash ?: 'Trade status updated' . ($status === 'settled' ? ' — Godley acquisition entries emitted.' : '.');
            header('Location: ./asx_purchases.php?holding_id=' . (int)$row['holding_id']); exit;
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}
if (isset($_GET['saved']) && !$flash) $flash = 'ASX purchase saved.';

$holdingSummary = null;
$recentTrades   = [];
$capacity       = null;
$holdingDocs    = [];
$dbError        = null;

try {
    $holdingSummary = $focusHolding > 0
        ? ops_fetch_one($pdo, 'SELECT * FROM asx_holdings WHERE id = ? LIMIT 1', [$focusHolding])
        : null;
    $recentTrades = $focusHolding > 0
        ? ops_fetch_all($pdo, 'SELECT t.*, h.ticker, h.company_name FROM asx_trades t INNER JOIN asx_holdings h ON h.id=t.holding_id WHERE t.holding_id=? ORDER BY t.trade_date DESC, t.id DESC LIMIT 50', [$focusHolding])
        : ops_fetch_all($pdo, 'SELECT t.*, h.ticker, h.company_name FROM asx_trades t INNER JOIN asx_holdings h ON h.id=t.holding_id ORDER BY t.trade_date DESC, t.id DESC LIMIT 50');
    $capacity = ops_table_exists($pdo, 'v_foundation_asset_backing_capacity')
        ? ops_fetch_one($pdo, "SELECT * FROM v_foundation_asset_backing_capacity WHERE backing_group='ASX' LIMIT 1")
        : null;
    $holdingDocs = ($focusHolding > 0 && function_exists('ops_get_asx_documents_for_holding'))
        ? ops_get_asx_documents_for_holding($pdo, $focusHolding)
        : [];
} catch (Throwable $dbEx) {
    $dbError = $dbEx->getMessage();
    error_log('[asx_purchases] data fetch error: ' . $dbEx->getMessage());
}

ob_end_clean(); // Discard outer buffer — eliminates any pre-doctype output (notices, whitespace from includes)
ob_start();     // Inner buffer — captures page body for ops_render_page
?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('ASX Purchase Ledger', 'What this page does', 'Use this page to record actual blocks of ASX shares purchased by the joint venture. These purchase lots drive the live totals for shares held, weighted average price, total book value, and ASX-token capacity.', [
    'Each submission creates one ledgered purchase lot for a registered ASX holding.',
    'Use pending until the trade is settled — pending lots do not inflate live capacity.',
    'Upload the broker confirmation PDF to create a full evidence trail per lot.',
    'Chain attestation anchors the document hash to the blockchain record.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Follow this sequence when working with this page.', [
    ['title' => 'Select the holding', 'body' => 'Choose the registered ASX company this purchase belongs to.'],
    ['title' => 'Enter trade details', 'body' => 'Record the date, share count, price per share, and broker reference.'],
    ['title' => 'Set status to pending', 'body' => 'Keep as pending until CHESS settlement is confirmed.'],
    ['title' => 'Mark settled', 'body' => 'Update to settled once the shares appear in the CHESS registry.'],
    ['title' => 'Upload broker confirmation', 'body' => 'Attach the PDF to complete the evidence trail.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'Each section serves a different purpose.', [
    ['title' => 'Add purchase lot form', 'body' => 'One form submission = one trade lot. Do not batch multiple trades.'],
    ['title' => 'Recent purchase lots', 'body' => 'Full ledger of all recorded lots with status and book value.'],
    ['title' => 'Document vault', 'body' => 'Upload and verify broker PDFs. Each upload is SHA-256 hashed.'],
  ]),
  ops_admin_status_panel('Status guide', 'These statuses appear throughout this page.', [
    ['label' => 'Pending', 'body' => 'Trade recorded but not yet CHESS-settled. Does not count toward capacity.'],
    ['label' => 'Settled', 'body' => 'Shares confirmed in CHESS. Contributes to live book value and capacity.'],
    ['label' => 'Failed / cancelled', 'body' => 'Trade did not proceed. Excluded from all capacity calculations.'],
  ]),
]) ?>

<div class="grid" style="gap:18px">
  <?php if ($dbError): ?>
  <div class="card" style="border-left:4px solid var(--color-error,#c0392b)">
    <div class="card-head"><h2>⚠ Database error — ASX data could not be loaded</h2></div>
    <div class="card-body">
      <p>The page encountered a database error loading ASX trade data. The most common cause is a table collation mismatch between <code>asx_trades</code> and <code>asx_holdings</code>.</p>
      <p><strong>To fix:</strong> run the following two SQL statements in phpMyAdmin, then reload this page:</p>
      <pre style="background:var(--bg-muted,#f5f5f5);padding:12px;border-radius:4px;font-size:0.85em;overflow-x:auto">ALTER TABLE `asx_trades` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `asx_holdings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</pre>
      <p class="muted" style="margin-top:8px">Technical detail: <?= ops_h($dbError) ?></p>
    </div>
  </div>
  <?php endif; ?>
  



<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
    <div class="card"><div class="card-head"><h2>ASX book value <?= ops_admin_help_button('ASX book value', 'This is the aggregate settled ASX share cost basis from the live backing-capacity view.') ?></h2></div><div class="card-body"><div class="stat-value"><?= ap_money((float)($capacity['source_book_value_cents'] ?? 0)) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ backed</h2></div><div class="card-body"><div class="stat-value"><?= ap_num((float)($capacity['cogs_backed'] ?? 0), 0) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ minted</h2></div><div class="card-body"><div class="stat-value"><?= ap_num((float)($capacity['cogs_minted'] ?? 0), 0) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ available <?= ops_admin_help_button('COG$ available', 'Calculated at 1 ASX COG$ = $4 of settled ASX shares.') ?></h2></div><div class="card-body"><div class="stat-value"><?= ap_num((float)($capacity['cogs_available_to_back'] ?? 0), 0) ?></div></div></div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Add ASX purchase lot <?= ops_admin_help_button('ASX purchase lot form', 'Each form submission creates one ledgered purchase lot. Use pending until the trade is settled if you do not want it counted into live backing yet.') ?></h2></div>
    <div class="card-body">
      <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="add_purchase">
        <label><div class="muted" style="margin-bottom:6px">ASX holding</div><select name="holding_id" style="width:100%"><option value="">Select…</option><?php foreach ($holdings as $h): ?><option value="<?= (int)$h['id'] ?>"<?= $focusHolding === (int)$h['id'] ? ' selected' : '' ?>><?= ops_h((string)$h['ticker']) ?> — <?= ops_h((string)$h['company_name']) ?></option><?php endforeach; ?></select></label>
        <label><div class="muted" style="margin-bottom:6px">Trade type</div><select name="trade_type" style="width:100%"><option value="buy">Buy</option><option value="reinvestment">Reinvestment</option><option value="legacy_seed">Legacy seed (opening position)</option></select></label>
        <label><div class="muted" style="margin-bottom:6px">Shares purchased</div><input type="number" min="1" step="1" name="units" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Price per share</div><input type="number" min="0.0001" step="0.0001" name="price_per_share" placeholder="0.2350" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Brokerage</div><input type="number" min="0" step="0.01" name="brokerage" value="0.00" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Trade date</div><input type="date" name="trade_date" value="<?= date('Y-m-d') ?>" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Settlement date</div><input type="date" name="settlement_date" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Funded by</div><select name="funded_by" style="width:100%"><option value="member_payment">Member payment</option><option value="bds_reinvestment">BDS reinvestment</option><option value="dds_reinvestment">DDS reinvestment</option></select></label>
        <label><div class="muted" style="margin-bottom:6px">Settlement status <?= ops_admin_help_button('Settlement status', 'Settled lots affect live holdings. Pending and failed lots remain in the ledger but should not inflate the live backing position.') ?></div><select name="status" style="width:100%"><option value="pending">Pending</option><option value="settled">Settled</option><option value="failed">Failed</option></select></label>
        <label><div class="muted" style="margin-bottom:6px">CHESS / confirmation ref</div><input type="text" name="chess_confirmation_ref" style="width:100%"></label>
        <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:6px">Notes</div><textarea name="notes" rows="4" style="width:100%"></textarea></label>
        <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap"><button type="submit">Record purchase lot</button><a class="mini-btn secondary" href="./asx_holdings.php">Manage ASX Holdings</a></div>
      </form>
      <?php if ($holdingSummary): ?><p class="muted" style="margin-top:12px">Current live holding: <strong><?= ops_h((string)$holdingSummary['ticker']) ?></strong> · <?= ap_num((int)$holdingSummary['units_held'], 0) ?> shares · <?= ap_money((float)$holdingSummary['total_cost_cents']) ?> book value.</p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Recent purchase lots <?= ops_admin_help_button('Recent purchase lots', 'Use this ledger to review all recorded ASX trade lots. The status controls whether the lot contributes to live book value and token-backing capacity.') ?></h2></div>
    <div class="card-body table-wrap">
      <div class="table-wrap"><table>
        <thead><tr><th>Ref</th><th>Holding</th><th>Type</th><th>Shares</th><th>Price/share</th><th>Total cost</th><th>Status</th><th>Dates</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (!$recentTrades): ?><tr><td colspan="9" class="muted">No purchase lots recorded yet.</td></tr><?php else: foreach ($recentTrades as $t):
            $isEditing = ($editTradeId === (int)$t['id']); ?>
            <?php if ($isEditing): ?>
            <tr style="background:rgba(212,178,92,.06)">
              <td colspan="9">
                <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;padding:8px 0">
                  <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                  <input type="hidden" name="action" value="edit_trade">
                  <input type="hidden" name="trade_id" value="<?= (int)$t['id'] ?>">
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Trade type</div>
                    <select name="trade_type" style="width:100%">
                      <option value="buy"<?= $t['trade_type']==='buy'?' selected':'' ?>>Buy</option>
                      <option value="reinvestment"<?= $t['trade_type']==='reinvestment'?' selected':'' ?>>Reinvestment</option>
                      <option value="legacy_seed"<?= $t['trade_type']==='legacy_seed'?' selected':'' ?>>Legacy seed (opening position)</option>
                    </select>
                  </label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Shares</div><input type="number" name="units" value="<?= (int)$t['units'] ?>" min="1" step="1" style="width:100%"></label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Price/share ($)</div><input type="number" name="price_per_share" value="<?= number_format((float)$t['price_cents_per_unit']/100, 4) ?>" min="0.0001" step="0.0001" style="width:100%"></label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Brokerage ($)</div><input type="number" name="brokerage" value="<?= number_format((float)$t['brokerage_cents']/100, 2) ?>" min="0" step="0.01" style="width:100%"></label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Trade date</div><input type="date" name="trade_date" value="<?= ops_h((string)$t['trade_date']) ?>" style="width:100%"></label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Settlement date</div><input type="date" name="settlement_date" value="<?= ops_h((string)($t['settlement_date'] ?? '')) ?>" style="width:100%"></label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Funded by</div>
                    <select name="funded_by" style="width:100%">
                      <option value="member_payment"<?= $t['funded_by']==='member_payment'?' selected':'' ?>>Member payment</option>
                      <option value="bds_reinvestment"<?= $t['funded_by']==='bds_reinvestment'?' selected':'' ?>>BDS reinvestment</option>
                      <option value="dds_reinvestment"<?= $t['funded_by']==='dds_reinvestment'?' selected':'' ?>>DDS reinvestment</option>
                    </select>
                  </label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">Status</div>
                    <select name="status" style="width:100%">
                      <option value="pending"<?= $t['status']==='pending'?' selected':'' ?>>Pending</option>
                      <option value="settled"<?= $t['status']==='settled'?' selected':'' ?>>Settled</option>
                      <option value="failed"<?= $t['status']==='failed'?' selected':'' ?>>Failed</option>
                    </select>
                  </label>
                  <label><div class="muted" style="margin-bottom:4px;font-size:.85em">CHESS ref</div><input type="text" name="chess_confirmation_ref" value="<?= ops_h((string)($t['chess_confirmation_ref'] ?? '')) ?>" style="width:100%"></label>
                  <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:4px;font-size:.85em">Notes</div><textarea name="notes" rows="2" style="width:100%"><?= ops_h((string)($t['notes'] ?? '')) ?></textarea></label>
                  <div style="grid-column:1/-1;display:flex;gap:8px">
                    <button type="submit">Save changes</button>
                    <a class="mini-btn secondary" href="./asx_purchases.php?holding_id=<?= $focusHolding ?>">Cancel</a>
                  </div>
                </form>
              </td>
            </tr>
            <?php else: ?>
            <tr>
              <td class="mono"><?= ops_h((string)$t['trade_ref']) ?></td>
              <td><strong><?= ops_h((string)$t['ticker']) ?></strong><div class="muted"><?= ops_h((string)$t['company_name']) ?></div></td>
              <td><?= ops_h((string)$t['trade_type']) ?></td>
              <td><?= ap_num((int)$t['units'], 0) ?></td>
              <td><?= ap_money((float)$t['price_cents_per_unit']) ?></td>
              <td><?= ap_money((float)$t['total_cost_cents'] + (float)$t['brokerage_cents']) ?></td>
              <td><span class="chip"><?= ops_h((string)$t['status']) ?></span></td>
              <td><div><?= ops_h((string)$t['trade_date']) ?></div><div class="muted"><?= ops_h((string)($t['settlement_date'] ?: '—')) ?></div></td>
              <td>
                <a class="mini-btn secondary" href="./asx_purchases.php?holding_id=<?= $focusHolding ?>&edit_trade=<?= (int)$t['id'] ?>#edit-trade-<?= (int)$t['id'] ?>">Edit</a>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Upload purchase document <?= ops_admin_help_button('Upload purchase document', 'Upload the PDF broker confirmation, IG statement, or CHESS notification for this holding. The file is SHA-256 hashed on upload and anchored in the evidence vault. For legacy seed lots (trust forming property), a standalone chain attestation record is created automatically.') ?></h2></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="upload_document">
        <label><div class="muted" style="margin-bottom:6px">ASX holding</div>
          <select name="holding_id" style="width:100%">
            <option value="">Select…</option>
            <?php foreach ($holdings as $h): ?>
              <option value="<?= (int)$h['id'] ?>"<?= $focusHolding === (int)$h['id'] ? ' selected' : '' ?>><?= ops_h((string)$h['ticker']) ?> — <?= ops_h((string)$h['company_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><div class="muted" style="margin-bottom:6px">Specific trade lot (optional) <?= ops_admin_help_button('Specific trade lot', 'Leave blank to attach the document to the whole holding — use this for statements that cover multiple trades (e.g. IG activity statements).') ?></div>
          <select name="trade_id" style="width:100%">
            <option value="0">All trades on holding</option>
            <?php foreach ($recentTrades as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= ops_h((string)$t['trade_date']) ?> — <?= ap_num((int)$t['units'], 0) ?> shares @ <?= ap_money((float)$t['price_cents_per_unit']) ?> (<?= ops_h((string)$t['trade_type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><div class="muted" style="margin-bottom:6px">Document type</div>
          <select name="document_type" style="width:100%">
            <option value="ig_statement">IG statement</option>
            <option value="broker_confirmation">Broker confirmation</option>
            <option value="chess_statement">CHESS statement</option>
            <option value="asx_announcement">ASX announcement</option>
            <option value="valuation">Valuation</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label><div class="muted" style="margin-bottom:6px">PDF file <?= ops_admin_help_button('PDF file', 'Maximum 20 MB. Only PDF files are accepted. The file will be SHA-256 hashed and stored in the private document vault.') ?></div>
          <input type="file" name="trade_document" accept="application/pdf" style="width:100%">
        </label>
        <label style="display:flex;align-items:center;gap:8px;margin-top:24px">
          <input type="checkbox" name="is_legacy_seed" value="1">
          This is a legacy seed / trust forming property document
          <?= ops_admin_help_button('Legacy seed document', 'Tick for initial trust property documents (e.g. the IG statement for the opening LGM parcel). A standalone chain attestation record will be created because these lots are never minted into tokens.') ?>
        </label>
        <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:6px">Notes</div><textarea name="notes" rows="3" style="width:100%"></textarea></label>
        <div style="grid-column:1/-1"><button type="submit">Upload and hash document</button></div>
      </form>
    </div>
  </div>

  <?php if ($holdingDocs): ?>
  <div class="card">
    <div class="card-head"><h2>Document vault <?= ops_admin_help_button('Document vault', 'All PDF documents uploaded for this holding. Each document is SHA-256 hashed on upload and anchored in the evidence vault. Chain attestation status shows whether the hash has been submitted to the blockchain record.') ?></h2></div>
    <div class="card-body table-wrap">
      <div class="table-wrap"><table>
        <thead><tr><th>Ref</th><th>Type</th><th>File</th><th>SHA-256</th><th>Trade lot</th><th>Attestation</th><th>Uploaded</th></tr></thead>
        <tbody>
          <?php foreach ($holdingDocs as $d): ?>
            <tr>
              <td class="mono" style="font-size:0.8em"><?= ops_h((string)$d['document_ref']) ?></td>
              <td><?= ops_h((string)$d['document_type']) ?></td>
              <td><?= ops_h((string)$d['original_filename']) ?><div class="muted"><?= number_format((int)$d['file_size_bytes'] / 1024, 0) ?> KB</div></td>
              <td class="mono" style="font-size:0.75em;word-break:break-all"><?= ops_h((string)$d['sha256_hash']) ?></td>
              <td><?= $d['trade_ref'] ? ops_h((string)$d['trade_ref']) . '<div class="muted">' . ops_h((string)$d['trade_date']) . '</div>' : '<span class="muted">All trades</span>' ?></td>
              <td><span class="chip"><?= ops_h((string)$d['attestation_status']) ?></span>
                <?php if ($d['chain_handoff_id']): ?><div class="muted" style="margin-top:4px">Handoff #<?= (int)$d['chain_handoff_id'] ?></div><?php endif; ?>
                <?php if ($d['chain_tx_hash']): ?><div class="muted mono" style="font-size:0.75em;margin-top:4px"><?= ops_h(substr((string)$d['chain_tx_hash'],0,20)) ?>…</div><?php endif; ?>
              </td>
              <td><?= ops_h((string)$d['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php
$body = ob_get_clean();
ops_render_page('ASX Purchases', 'asx_purchases', $body, $flash, $flashType);

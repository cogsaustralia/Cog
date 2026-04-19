<?php
declare(strict_types=1);
ob_start(); // Outer buffer — catches any accidental pre-doctype output
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$adminUserId = ops_admin_id();
$flash = null; $flashType = 'ok';

function asx_money(int|float $cents): string {
    $val = ((float)$cents) / 100;
    $dp  = ($val > 0 && $val < 1.00) ? 4 : 2;
    return '$' . number_format($val, $dp);
}
function asx_num($n, int $dp = 0): string { return number_format((float)$n, $dp); }

$hasLiveView = ops_table_exists($pdo, 'v_foundation_asx_holdings_live');
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_holding') {
            $id = (int)($_POST['holding_id'] ?? 0);
            $ticker = strtoupper(trim((string)($_POST['ticker'] ?? '')));
            $company = trim((string)($_POST['company_name'] ?? ''));
            $hin = trim((string)($_POST['chess_hin'] ?? ''));
            $funded = (string)($_POST['funded_by_stream'] ?? 'beneficiary');
            if (!in_array($funded, ['beneficiary','donation','mixed'], true)) $funded = 'beneficiary';
            $poorEsg = !empty($_POST['is_poor_esg_target']) ? 1 : 0;
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($ticker === '') throw new RuntimeException('ASX code is required.');
            if (!preg_match('/^(ASX:)?[A-Z0-9.]{2,12}$/', $ticker)) throw new RuntimeException('Use an ASX code such as ASX:LGM.');
            if ($company === '') throw new RuntimeException('Company name is required.');
            if (strpos($ticker, 'ASX:') !== 0) $ticker = 'ASX:' . $ticker;

            $dup = ops_fetch_one($pdo, 'SELECT id FROM asx_holdings WHERE ticker = ? AND id <> ? LIMIT 1', [$ticker, $id]);
            if ($dup) throw new RuntimeException('That ASX code already exists.');

            if ($id > 0) {
                $st = $pdo->prepare('UPDATE asx_holdings SET ticker=?, company_name=?, chess_hin=?, funded_by_stream=?, is_poor_esg_target=?, notes=?, updated_at=NOW() WHERE id=?');
                $st->execute([$ticker,$company,$hin !== '' ? $hin : null,$funded,$poorEsg,$notes !== '' ? $notes : null,$id]);
                $flash = 'ASX holding updated.';
            } else {
                $st = $pdo->prepare('INSERT INTO asx_holdings (ticker, company_name, chess_hin, units_held, average_cost_cents, total_cost_cents, funded_by_stream, is_poor_esg_target, notes, created_at, updated_at) VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?, NOW(), NOW())');
                $st->execute([$ticker,$company,$hin !== '' ? $hin : null,$funded,$poorEsg,$notes !== '' ? $notes : null]);
                $id = (int)$pdo->lastInsertId();
                $flash = 'ASX holding created.';
            }
            header('Location: ./asx_holdings.php?edit=' . $id . '&saved=1'); exit;
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

if (isset($_GET['saved']) && !$flash) { $flash = 'ASX holding saved.'; }

$summary = ['lines'=>0,'shares'=>0,'book'=>0,'backed'=>0,'minted'=>0,'available'=>0];
if ($hasLiveView) {
    foreach (ops_fetch_all($pdo, 'SELECT * FROM v_foundation_asx_holdings_live ORDER BY asx_code') as $r) {
        $summary['lines']++;
        $summary['shares'] += (float)($r['shares_held'] ?? 0);
        $summary['book']   += (float)($r['total_book_value_cents'] ?? 0);
        $summary['backed'] += (float)($r['cogs_backed'] ?? 0);
        $summary['minted'] += (float)($r['cogs_minted'] ?? 0);
        $summary['available'] += (float)($r['cogs_available_to_back'] ?? 0);
    }
}

$rows = $hasLiveView
    ? ops_fetch_all($pdo, "SELECT h.id, live.asx_code, live.company_name, live.shares_held, live.average_price_per_share_cents, live.total_book_value_cents, live.cogs_backed, live.cogs_minted, live.cogs_available_to_back, h.chess_hin, h.funded_by_stream, h.is_poor_esg_target, h.notes FROM v_foundation_asx_holdings_live live INNER JOIN asx_holdings h ON h.id = live.holding_id ORDER BY live.asx_code ASC")
    : ops_fetch_all($pdo, 'SELECT * FROM asx_holdings ORDER BY ticker ASC');
$editRow = null;
foreach ($rows as $r) { if ((int)($r['id'] ?? 0) === $editId) { $editRow = $r; break; } }
if (!$editRow) {
    $editRow = ['id'=>'','asx_code'=>'','ticker'=>'','company_name'=>'','chess_hin'=>'','funded_by_stream'=>'beneficiary','is_poor_esg_target'=>0,'notes'=>''];
}
$editTicker = (string)($editRow['asx_code'] ?? $editRow['ticker'] ?? '');

ob_end_clean(); // Discard outer buffer — eliminates any pre-doctype output
ob_start();     // Inner buffer — captures page body for ops_render_page
?>
<div class="grid" style="gap:18px">
  <?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('ASX holdings register', 'What this page does', 'Use this page to create and maintain the live registry of ASX-listed shareholdings held by the partnership. Each line represents one listed company and becomes the roll-up source for share count, weighted average purchase price, total book value, and future token-backing capacity.', [
      'Create one registry line per ASX company, for example ASX:LGM.',
      'Maintain company identity, HIN/CHESS reference, stewardship stream, and notes.',
      'Review live share count, book value, and backing capacity once purchase lots have been entered.',
      'Move to ASX Purchases after creating the holding to record actual trade lots.',
  ]),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_workflow_panel('Typical workflow', 'Start by creating the listed company record, then record purchase lots on the purchases page. The live totals here recalculate from those lots.', [
      ['title' => 'Create or update the holding line', 'body' => 'Record the ASX code, company name, and custody context once for the company.'],
      ['title' => 'Enter purchase lots', 'body' => 'Use the ASX Purchases page to record blocks of shares, trade dates, cost, and settlement status.'],
      ['title' => 'Review live totals', 'body' => 'This page then shows shares held, weighted average cost, total book value, and how much ASX token capacity remains.'],
      ['title' => 'Use the totals for backing control', 'body' => 'Stage 3 will allocate settled book value to ASX token minting at $4 of shares per ASX COG$.'],
  ]),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_guide_panel('How to read this page', 'The top cards show the current live foundation position, while the table below shows each ASX company line individually.', [
      ['title' => 'Holdings lines', 'body' => 'How many separate ASX company holdings are currently registered.'],
      ['title' => 'Shares held', 'body' => 'The live total of settled shares recorded across the ASX purchase ledger.'],
      ['title' => 'Book value', 'body' => 'The total recorded cost basis for settled ASX share purchases.'],
      ['title' => 'COG$ backed / minted / available', 'body' => 'Backed means value already reserved for token support, minted means already issued, and available means still unallocated at the $4-per-token rule.'],
  ]),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_status_panel('Field guide', 'These fields control the identity and stewardship posture of the holding line.', [
      ['label' => 'ASX code', 'body' => 'Use the exchange code form, for example ASX:LGM. This is the label shown in the live asset pool.'],
      ['label' => 'Funded by stream', 'body' => 'Identifies whether the holding is mainly attributable to beneficiary capital, donation flow, or a mixed funding basis.'],
      ['label' => 'Poor ESG target', 'body' => 'Flags that the holding is being stewarded as a poor-ESG target under the partnership strategy.'],
      ['label' => 'CHESS / HIN ref', 'body' => 'Optional operational reference for custody tracing.'],
  ]),
]) ?>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
    <div class="card"><div class="card-head"><h2>Holding lines <?= ops_admin_help_button('Holding lines', 'The number of separate listed equity lines currently registered for the partnership.') ?></h2></div><div class="card-body"><div style="font-size:1.8rem;font-weight:800"><?= asx_num($summary['lines']) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>Shares held <?= ops_admin_help_button('Shares held', 'Settled shares currently recorded across all ASX purchase lots.') ?></h2></div><div class="card-body"><div style="font-size:1.8rem;font-weight:800"><?= asx_num($summary['shares'], 0) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>Book value <?= ops_admin_help_button('Book value', 'Total recorded cost basis of settled ASX share purchases.') ?></h2></div><div class="card-body"><div style="font-size:1.8rem;font-weight:800"><?= asx_money($summary['book']) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ available <?= ops_admin_help_button('COG$ available', 'Unallocated ASX token capacity calculated at 1 ASX COG$ = $4 of settled ASX shares.') ?></h2></div><div class="card-body"><div style="font-size:1.8rem;font-weight:800"><?= asx_num($summary['available'], 0) ?></div></div></div>
  </div>

  <div class="card" id="holding-form">
    <div class="card-head"><h2><?= $editId > 0 ? 'Edit ASX holding' : 'Add ASX holding' ?> <?= ops_admin_help_button('ASX holding form', 'Create the company line once, then use ASX Purchases to add actual trade lots. The live totals below come from the purchase ledger.') ?></h2></div>
    <div class="card-body">
      <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="save_holding">
        <input type="hidden" name="holding_id" value="<?= ops_h((string)$editRow['id']) ?>">
        <label><div class="muted" style="margin-bottom:6px">ASX code <?= ops_admin_help_button('ASX code', 'Use the exchange code form such as ASX:LGM.') ?></div><input type="text" name="ticker" value="<?= ops_h($editTicker) ?>" placeholder="ASX:LGM" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Company name</div><input type="text" name="company_name" value="<?= ops_h((string)$editRow['company_name']) ?>" placeholder="Legacy Minerals Holdings Limited" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">CHESS / HIN ref</div><input type="text" name="chess_hin" value="<?= ops_h((string)($editRow['chess_hin'] ?? '')) ?>" placeholder="Optional" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Funded by stream <?= ops_admin_help_button('Funded by stream', 'This is a stewardship classification, not a bank-account field.') ?></div><select name="funded_by_stream" style="width:100%"><option value="beneficiary"<?= (($editRow['funded_by_stream'] ?? '') === 'beneficiary') ? ' selected' : '' ?>>Beneficiary</option><option value="donation"<?= (($editRow['funded_by_stream'] ?? '') === 'donation') ? ' selected' : '' ?>>Donation</option><option value="mixed"<?= (($editRow['funded_by_stream'] ?? '') === 'mixed') ? ' selected' : '' ?>>Mixed</option></select></label>
        <label style="display:flex;align-items:center;gap:8px;margin-top:24px"><input type="checkbox" name="is_poor_esg_target" value="1"<?= !empty($editRow['is_poor_esg_target']) ? ' checked' : '' ?>> Poor ESG stewardship target</label>
        <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:6px">Notes</div><textarea name="notes" rows="4" style="width:100%"><?= ops_h((string)($editRow['notes'] ?? '')) ?></textarea></label>
        <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap"><button type="submit">Save holding</button><a class="mini-btn secondary" href="./asx_holdings.php">Clear</a><a class="mini-btn secondary" href="./asx_purchases.php">Go to ASX Purchases</a></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Live ASX holdings <?= ops_admin_help_button('Live ASX holdings', 'These totals update from the ASX purchase ledger. Pending or failed trades should not inflate the settled book value if the purchase page is used correctly.') ?></h2></div>
    <div class="card-body table-wrap">
      <table>
        <thead><tr><th>ASX code</th><th>Company</th><th>Shares</th><th>Avg price/share</th><th>Total book value</th><th>COG$ backed</th><th>COG$ minted</th><th>COG$ available</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="muted">No ASX holdings registered yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= ops_h((string)($r['asx_code'] ?? $r['ticker'] ?? '')) ?></strong></td>
            <td><?= ops_h((string)($r['company_name'] ?? '')) ?></td>
            <td><?= asx_num((float)($r['shares_held'] ?? $r['units_held'] ?? 0), 0) ?></td>
            <td><?= isset($r['average_price_per_share_cents']) ? asx_money((float)$r['average_price_per_share_cents']) : asx_money((float)($r['average_cost_cents'] ?? 0)) ?></td>
            <td><?= isset($r['total_book_value_cents']) ? asx_money((float)$r['total_book_value_cents']) : asx_money((float)($r['total_cost_cents'] ?? 0)) ?></td>
            <td><?= asx_num((float)($r['cogs_backed'] ?? 0), 0) ?></td>
            <td><?= asx_num((float)($r['cogs_minted'] ?? 0), 0) ?></td>
            <td><?= asx_num((float)($r['cogs_available_to_back'] ?? 0), 0) ?></td>
            <td>
              <a class="mini-btn secondary" href="./asx_holdings.php?edit=<?= (int)$r['id'] ?>#holding-form">Edit</a>
              <a class="mini-btn secondary" href="./asx_purchases.php?holding_id=<?= (int)$r['id'] ?>">Purchases</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('ASX Holdings', 'asx_holdings', $body, $flash, $flashType);

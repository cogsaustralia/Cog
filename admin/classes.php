<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
$tokenCatalogPath = __DIR__ . '/includes/admin_token_catalog.php';
if (is_file($tokenCatalogPath)) {
    require_once $tokenCatalogPath;
}
ops_require_admin();
$pdo = ops_db();

if (!function_exists('trust_catalog_row')) {
    function trust_catalog_row(array $row): array { return $row; }
}
if (!function_exists('trust_catalog_rows')) {
    function trust_catalog_rows(array $rows): array { return $rows; }
}
if (!function_exists('trust_code_only')) {
    function trust_code_only(array $row): string { return (string)($row['admin_code'] ?? ($row['class_code'] ?? '')); }
}
if (!function_exists('trust_limit_for_code')) {
    function trust_limit_for_code(string $code, ?PDO $pdo = null): int {
        $code = strtoupper($code);
        // Identity tokens are always limited to 1 unit
        if (in_array($code, ['PERSONAL_SNFT', 'KIDS_SNFT', 'BUSINESS_BNFT'], true)) return 1;
        // If we have a DB connection, read max_units from the live token_classes row
        if ($pdo !== null) {
            try {
                $row = $pdo->prepare('SELECT max_units FROM token_classes WHERE class_code = ? LIMIT 1');
                $row->execute([$code]);
                $max = $row->fetchColumn();
                if ($max !== false && (int)$max > 0) return (int)$max;
            } catch (Throwable $ignored) {}
        }
        // Hardcoded fallbacks for offline/no-DB context
        if ($code === 'LR_COG' || $code === 'RESIDENT_COG') return 1000;
        return 999999;
    }
}
if (!function_exists('trust_payment_required_for_code')) {
    function trust_payment_required_for_code(string $code, int $fallback = 0): int {
        $code = strtoupper($code);
        if (in_array($code, ['PERSONAL_SNFT', 'KIDS_SNFT', 'BUSINESS_BNFT', 'PAY_IT_FORWARD_COG', 'DONATION_COG'], true)) return 1;
        if (in_array($code, ['LANDHOLDER_COG', 'ASX_INVESTMENT_COG', 'RWA_COG', 'LR_COG'], true)) return 0;
        return $fallback;
    }
}

if (!function_exists('h')) {
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('q_rows')) {
function q_rows(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}
if (!function_exists('q_one')) {
function q_one(PDO $pdo, string $sql, array $params = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}
if (!function_exists('has_col')) {
function has_col(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
}
if (!function_exists('money_cents')) {
function money_cents(int $cents): string { return '$' . number_format($cents / 100, 2); }
}
if (!function_exists('type_label')) {
function type_label(string $memberType): string {
    return match ($memberType) {
        'both' => 'COG$ FT',
        'personal' => 'Personal NFT',
        'business' => 'Business NFT',
        default => $memberType,
    };
}
}
if (!function_exists('unit_price_form')) {
function unit_price_form($row): string {
    return number_format(((int)($row['unit_price_cents'] ?? 0)) / 100, 2, '.', '');
}
}

$hasAdminCode = has_col($pdo, 'token_classes', 'admin_code');
$hasUnitClassCode = has_col($pdo, 'token_classes', 'unit_class_code');

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_class') {
    admin_csrf_verify();
    try {
        $id = (int)($_POST['class_id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['class_code'] ?? '')));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $adminCode = trim((string)($_POST['admin_code'] ?? ''));
        $unitClassCode = trim((string)($_POST['unit_class_code'] ?? ''));
        $memberType = (string)($_POST['member_type'] ?? 'both');
        $unitPriceCents = (int)round(((float)($_POST['unit_price'] ?? 0)) * 100);
        $businessUnitPriceCentsRaw = trim((string)($_POST['business_unit_price'] ?? ''));
        $businessUnitPriceCents = $businessUnitPriceCentsRaw !== '' ? (int)round(((float)$businessUnitPriceCentsRaw) * 100) : null;
        $minUnits = max(0, (int)($_POST['min_units'] ?? 0));
        $maxUnits = trust_limit_for_code($code, $pdo);
        $stepUnits = max(1, (int)($_POST['step_units'] ?? 1));
        $displayOrder = (int)($_POST['display_order'] ?? 999);
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $isLocked = !empty($_POST['is_locked']) ? 1 : 0;
        $approvalRequired = !empty($_POST['approval_required']) ? 1 : 0;
        $paymentRequired = trust_payment_required_for_code($code, !empty($_POST['payment_required']) ? 1 : 0);
        $walletVisible = !empty($_POST['wallet_visible_by_default']) ? 1 : 0;
        $walletEditable = !empty($_POST['wallet_editable_by_default']) ? 1 : 0;
        $adminCreatable = !empty($_POST['admin_creatable']) ? 1 : 0;

        if ($code === '') throw new RuntimeException('Machine class code is required.');
        if ($displayName === '') throw new RuntimeException('Display name is required.');
        if (!in_array($memberType, ['both', 'personal', 'business'], true)) $memberType = 'both';
        if ($minUnits > $maxUnits) throw new RuntimeException('Minimum units cannot exceed the maximum.');

        if ($id > 0) {
            $existing = q_one($pdo, 'SELECT * FROM token_classes WHERE id = ? LIMIT 1', [$id]);
            if (!$existing) throw new RuntimeException('COG$ Class not found.');
            if (!empty($existing['is_system_class']) && (string)$existing['class_code'] !== $code) {
                throw new RuntimeException('System class_code values cannot be changed.');
            }

            $sql = 'UPDATE token_classes SET class_code=?, display_name=?, member_type=?, unit_price_cents=?, business_unit_price_cents=?, min_units=?, max_units=?, step_units=?, display_order=?, is_active=?, is_locked=?, approval_required=?, payment_required=?, wallet_visible_by_default=?, wallet_editable_by_default=?, admin_creatable=?';
            $params = [$code, $displayName, $memberType, $unitPriceCents, $businessUnitPriceCents, $minUnits, $maxUnits, $stepUnits, $displayOrder, $isActive, $isLocked, $approvalRequired, $paymentRequired, $walletVisible, $walletEditable, $adminCreatable];
            if ($hasAdminCode) { $sql .= ', admin_code=?'; $params[] = $adminCode !== '' ? $adminCode : $code; }
            if ($hasUnitClassCode) { $sql .= ', unit_class_code=?'; $params[] = $unitClassCode; }
            $sql .= ', updated_at=? WHERE id=?';
            $params[] = ops_now();
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            $flash = 'COG$ Class updated.';
        } else {
            $fields = ['class_code','display_name','member_type','unit_price_cents','business_unit_price_cents','min_units','max_units','step_units','display_order','is_active','is_locked','approval_required','payment_required','wallet_visible_by_default','wallet_editable_by_default','is_system_class','admin_creatable'];
            $placeholders = ['?','?','?','?','?','?','?','?','?','?','?','?','?','?','?','0','?'];
            $params = [$code,$displayName,$memberType,$unitPriceCents,$businessUnitPriceCents,$minUnits,$maxUnits,$stepUnits,$displayOrder,$isActive,$isLocked,$approvalRequired,$paymentRequired,$walletVisible,$walletEditable,$adminCreatable];
            if ($hasAdminCode) { $fields[] = 'admin_code'; $placeholders[] = '?'; $params[] = $adminCode !== '' ? $adminCode : $code; }
            if ($hasUnitClassCode) { $fields[] = 'unit_class_code'; $placeholders[] = '?'; $params[] = $unitClassCode; }
            $fields[] = 'created_by_admin_id'; $placeholders[] = '?'; $params[] = function_exists('ops_admin_id') ? ops_admin_id() : null;
            $fields[] = 'created_at'; $placeholders[] = '?'; $params[] = ops_now();
            $fields[] = 'updated_at'; $placeholders[] = '?'; $params[] = ops_now();
            $sql = 'INSERT INTO token_classes (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
            $pdo->prepare($sql)->execute($params);
            $flash = 'COG$ Class created.';
            $id = (int)$pdo->lastInsertId();
        }

        header('Location: ./classes.php?edit=' . urlencode((string)$id) . '&popout=1&saved=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$selectCols = 'tc.*';
if (!$hasAdminCode) $selectCols .= ', NULL AS admin_code';
if (!$hasUnitClassCode) $selectCols .= ', NULL AS unit_class_code';
$rows = q_rows($pdo, "SELECT $selectCols,
    COALESCE(COUNT(DISTINCT rl.member_id), 0) AS member_count,
    COALESCE(SUM(rl.requested_units), 0) AS reserved_total,
    COALESCE(SUM(rl.approved_units), 0) AS issued_total
FROM token_classes tc
LEFT JOIN member_reservation_lines rl ON rl.token_class_id = tc.id
GROUP BY tc.id
ORDER BY tc.display_order ASC, tc.id ASC");
$rows = trust_catalog_rows($rows);

$editId = (int)($_GET['edit'] ?? 0);
$showPopout = !empty($_GET['popout']) || !empty($_POST['force_popout']);
$editRow = null;
foreach ($rows as $r) {
    if ((int)$r['id'] === $editId) { $editRow = $r; break; }
}
if (!$editRow) {
    $editRow = [
        'id' => '', 'class_code' => '', 'admin_code' => '', 'unit_class_code' => '', 'display_name' => '',
        'member_type' => 'both', 'unit_price_cents' => 0, 'min_units' => 0, 'max_units' => 999999,
        'step_units' => 1, 'display_order' => 999, 'is_active' => 1, 'is_locked' => 0,
        'approval_required' => 1, 'payment_required' => 1, 'wallet_visible_by_default' => 1,
        'wallet_editable_by_default' => 1, 'admin_creatable' => 1, 'is_system_class' => 0,
    ];
}
if (isset($_GET['saved'])) $flash = 'COG$ Class saved.';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>COG$ Token / Unit Classes — Admin</title>
<style>
tr.clickable{cursor:pointer}
tr.clickable:hover{background:rgba(255,255,255,.035)}
.kicker{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.chip{display:inline-block;padding:.35rem .6rem;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:12px}
.notice{padding:12px 14px;border-radius:14px;margin-bottom:12px}
.overlay{position:fixed;inset:0;background:rgba(3,8,14,.62);backdrop-filter:blur(4px);display:none;align-items:flex-start;justify-content:flex-end;padding:24px;z-index:1000}
.overlay.open{display:flex}
.drawer{width:min(560px,100%);max-height:calc(100vh - 48px);overflow:auto;background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:24px;padding:18px;box-shadow:0 24px 60px rgba(0,0,0,.38)}
.drawer-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}
.xbtn{border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);border-radius:12px;padding:.6rem .8rem;cursor:pointer}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:flex;flex-direction:column;gap:6px}
.field.span-2{grid-column:1 / -1}
@media(max-width:980px){.form-grid{grid-template-columns:1fr}.overlay{padding:12px}}
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('classes'); ?>
<main class="main">
  <?php ops_admin_help_assets_once(); ?>
  <?= ops_admin_collapsible_help('Page guide & workflow', [
    ops_admin_info_panel('Token class administration', 'What this page does', 'Use this page to define and maintain the COG$ class catalog used across reservation, issuance, wallet visibility, approval requirements, and admin processing. It is a structural page, so edits here affect how classes behave everywhere else in the system.', [
      'Use the table to review the current catalog and click a row to edit its configuration.',
      'Use the drawer to add or update class settings such as price, limits, visibility, and approval posture.',
      'Identity classes keep their hard limits and should be changed with particular care.',
    ]),
    ops_admin_workflow_panel('Typical workflow', 'Class changes should be controlled because they affect reservation, issuance, wallet display, and operational rules.', [
      ['title' => 'Review the current catalog', 'body' => 'Check the class code, type, prices, totals, and active status before making a change.'],
      ['title' => 'Open the correct class', 'body' => 'Click an existing row to edit it or start a new class when the catalog genuinely needs expansion.'],
      ['title' => 'Save and verify downstream impact', 'body' => 'After saving, confirm that the affected wallet, approval, or admin flows still behave as expected.'],
    ]),
    ops_admin_status_panel('How to read this page', 'The class table is a compact summary of structural class settings and activity totals.', [
      ['label' => 'Order', 'body' => 'Display order used when the class catalog is rendered in admin or wallet contexts.'],
      ['label' => 'Code / Type', 'body' => 'The machine/admin code and whether the class behaves like an NFT-style identity class or a fungible class.'],
      ['label' => 'Totals', 'body' => 'Activity totals tied to reservation or issuance depending on the class type.'],
      ['label' => 'Status', 'body' => 'Whether the class is active and whether it is locked for editing/operational purposes.'],
    ]),
  ]) ?>
  <div class="card">
    <div class="card-body">
    <div class="kicker">Token administration</div>
    <h1 style="margin:0 0 8px">COG$ Token / Unit Classes <?= ops_admin_help_button('COG$ Token / Unit Classes', 'This page manages the structural token-class catalog used by the system. Class settings here affect prices, limits, approval posture, wallet display, and processing rules.') ?></h1>
    <p class="muted" style="margin:0">Click a class row to edit it. The editor opens as a popout panel.</p>
    </div>
  </div>

  <?php if ($flash): ?><div class="notice ok"><?=h($flash)?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice err"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <div class="actions" style="margin-bottom:12px">
      <button type="button" class="btn" id="openCreate">Add COG$ Class</button><?= ops_admin_help_button('Add COG$ Class', 'Use this when the catalog genuinely needs a new class. Creating a class changes the system-level configuration, so only add one when the legal and operational model supports it.') ?>
      <span class="help">NFT rows show total issued. FT rows show total reserved and total issued.</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Order<?= ops_admin_help_button('Order', 'Display order used when rendering the class catalog.') ?></th>
            <th>Code<?= ops_admin_help_button('Code', 'Short class code / admin code shown in the catalog summary.') ?></th>
            <th>Type<?= ops_admin_help_button('Type', 'Whether the class is treated like a personal NFT, business NFT, or fungible COG$ class.') ?></th>
            <th>Personal price<?= ops_admin_help_button('Personal price', 'Price applied when the class is used in personal-member context.') ?></th><th>Business price<?= ops_admin_help_button('Business price', 'Price applied when the class is used in business context, where different pricing is allowed.') ?></th>
            <th>Totals<?= ops_admin_help_button('Totals', 'Summary of reserved and/or issued units depending on the class type.') ?></th>
            <th>Status<?= ops_admin_help_button('Status', 'Shows whether the class is active and whether it is locked.') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $isNft = in_array((string)$r['class_code'], ['PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT'], true); ?>
          <tr class="clickable"
              data-edit-url="./classes.php?edit=<?=urlencode((string)$r['id'])?>&popout=1"
              data-id="<?=h($r['id'])?>"
              data-class-code="<?=h($r['class_code'])?>"
              data-admin-code="<?=h($r['admin_code'] ?? '')?>"
              data-unit-class-code="<?=h($r['unit_class_code'] ?? '')?>"
              data-display-name="<?=h($r['display_name'])?>"
              data-member-type="<?=h($r['member_type'])?>"
              data-unit-price="<?=h(unit_price_form($r))?>"
              data-min-units="<?=h($r['min_units'] ?? 0)?>"
              data-max-units="<?=h($r['max_units'] ?? 0)?>"
              data-step-units="<?=h($r['step_units'] ?? 1)?>"
              data-display-order="<?=h($r['display_order'] ?? 999)?>"
              data-is-active="<?=!empty($r['is_active']) ? '1' : '0'?>"
              data-is-locked="<?=!empty($r['is_locked']) ? '1' : '0'?>"
              data-approval-required="<?=!empty($r['approval_required']) ? '1' : '0'?>"
              data-payment-required="<?=!empty($r['payment_required']) ? '1' : '0'?>"
              data-wallet-visible="<?=!empty($r['wallet_visible_by_default']) ? '1' : '0'?>"
              data-wallet-editable="<?=!empty($r['wallet_editable_by_default']) ? '1' : '0'?>"
              data-admin-creatable="<?=!empty($r['admin_creatable']) ? '1' : '0'?>"
              data-is-system-class="<?=!empty($r['is_system_class']) ? '1' : '0'?>">
            <td style="color:var(--muted,#9fb0c1);font-size:12px"><?=h($r['display_order'])?></td>
            <td><span class="chip"><?=h(trust_code_only($r))?></span></td>
            <td><?=h(type_label((string)$r['member_type']))?></td>
            <td><?=h(money_cents((int)($r['unit_price_cents'] ?? 0)))?></td>
            <td>
              <?php if ($isNft): ?>
                Issued <?=number_format((int)($r['issued_total'] ?? 0))?>
              <?php else: ?>
                Reserved <?=number_format((int)($r['reserved_total'] ?? 0))?><br><span class="muted">Issued <?=number_format((int)($r['issued_total'] ?? 0))?></span>
              <?php endif; ?>
            </td>
            <td><?=!empty($r['is_active']) ? 'Active' : 'Inactive'?><?php if (!empty($r['is_locked'])): ?><br><span class="muted">Locked</span><?php endif; ?></td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="6">No COG$ Classes found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</div>

<div class="overlay<?= $showPopout ? ' open' : '' ?>" id="classOverlay" aria-hidden="<?= $showPopout ? 'false' : 'true' ?>">
  <div class="drawer">
    <div class="drawer-head">
      <div>
        <div class="kicker">Edit token / unit class</div>
        <h2 style="margin:0" id="drawerTitle"><?= (int)($editRow['id'] ?? 0) > 0 ? 'Edit Token Class' : 'Add Token Class' ?></h2>
      </div>
      <button type="button" class="xbtn" id="closeOverlay">Close</button>
    </div>

    <form method="post" id="classForm">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="save_class">
      <input type="hidden" name="class_id" id="f_class_id" value="<?=h($editRow['id'])?>">
      <input type="hidden" name="force_popout" value="1">
      <div class="form-grid">
        <div class="field"><label>Machine class_code<?= ops_admin_help_button('Machine class_code', 'The protected machine identifier for the class. System class codes should only be changed where the model explicitly permits it.') ?></label><input id="f_class_code" name="class_code" value="<?=h($editRow['class_code'])?>"></div>
        <div class="field"><label>Display name<?= ops_admin_help_button('Display name', 'Human-readable class name shown to operators or users where this class is displayed.') ?></label><input id="f_display_name" name="display_name" value="<?=h($editRow['display_name'])?>"></div>
        <div class="field"><label>Code<?= ops_admin_help_button('Code', 'Short admin-facing code used in summaries and internal displays.') ?></label><input id="f_admin_code" name="admin_code" value="<?=h($editRow['admin_code'] ?? '')?>"></div>
        <div class="field"><label>Group / Unit Class<?= ops_admin_help_button('Group / Unit Class', 'Logical grouping or unit-class tag used to organize the catalog.') ?></label><input id="f_unit_class_code" name="unit_class_code" value="<?=h($editRow['unit_class_code'] ?? '')?>"></div>
        <div class="field"><label>Type<?= ops_admin_help_button('Type', 'Controls whether the class is treated as personal, business, or both.') ?></label><select id="f_member_type" name="member_type"><option value="both" <?=($editRow['member_type'] ?? 'both')==='both'?'selected':''?>>COG$ FT</option><option value="personal" <?=($editRow['member_type'] ?? '')==='personal'?'selected':''?>>Personal NFT</option><option value="business" <?=($editRow['member_type'] ?? '')==='business'?'selected':''?>>Business NFT</option></select></div>
        <div class="field"><label>Unit price — personal members (AUD)<?= ops_admin_help_button('Unit price — personal members', 'Price used when the class is applied in personal-member flows.') ?></label><input id="f_unit_price" type="number" step="0.01" min="0" name="unit_price" value="<?=h(unit_price_form($editRow))?>"></div>
        <div class="field"><label>Business price (AUD) — blank = same as unit price<?= ops_admin_help_button('Business price', 'Optional separate price for business flows. Leave blank to use the personal price.') ?></label><input id="f_business_unit_price" type="number" step="0.01" min="0" name="business_unit_price" value="<?=h(isset($editRow['business_unit_price_cents'])&&$editRow['business_unit_price_cents']!==null ? number_format(((int)$editRow['business_unit_price_cents'])/100,2,'.',',') : '')?>"></div>
        <div class="field"><label>Min units<?= ops_admin_help_button('Min units', 'Lowest allowed unit quantity for the class.') ?></label><input id="f_min_units" type="number" min="0" name="min_units" value="<?=h($editRow['min_units'] ?? 0)?>"></div>
        <div class="field"><label>Max units<?= ops_admin_help_button('Max units', 'Highest allowed unit quantity. Some classes are hard-limited by system rules.') ?></label><input id="f_max_units" type="number" value="<?=h($editRow['max_units'] ?? 0)?>" readonly></div>
        <div class="field"><label>Step units<?= ops_admin_help_button('Step units', 'Increment step used when units are adjusted.') ?></label><input id="f_step_units" type="number" min="1" name="step_units" value="<?=h($editRow['step_units'] ?? 1)?>"></div>
        <div class="field"><label>Display order<?= ops_admin_help_button('Display order', 'Sorting position in class listings.') ?></label><input id="f_display_order" type="number" name="display_order" value="<?=h($editRow['display_order'] ?? 999)?>"></div>
        <div class="field"><label><input id="f_is_active" type="checkbox" name="is_active" value="1" <?=!empty($editRow['is_active'])?'checked':''?>> Active</label></div>
        <div class="field"><label><input id="f_is_locked" type="checkbox" name="is_locked" value="1" <?=!empty($editRow['is_locked'])?'checked':''?>> Locked</label></div>
        <div class="field"><label><input id="f_approval_required" type="checkbox" name="approval_required" value="1" <?=!empty($editRow['approval_required'])?'checked':''?>> Approval required</label></div>
        <div class="field"><label><input id="f_payment_required" type="checkbox" name="payment_required" value="1" <?=!empty($editRow['payment_required'])?'checked':''?>> Payment required</label></div>
        <div class="field"><label><input id="f_wallet_visible" type="checkbox" name="wallet_visible_by_default" value="1" <?=!empty($editRow['wallet_visible_by_default'])?'checked':''?>> Wallet visible</label></div>
        <div class="field"><label><input id="f_wallet_editable" type="checkbox" name="wallet_editable_by_default" value="1" <?=!empty($editRow['wallet_editable_by_default'])?'checked':''?>> Wallet editable</label></div>
        <div class="field span-2"><label><input id="f_admin_creatable" type="checkbox" name="admin_creatable" value="1" <?=!empty($editRow['admin_creatable'])?'checked':''?>> Admin creatable / group processing control</label></div>
      </div>
      <div class="actions" style="margin-top:16px">
        <button type="submit" class="btn">Save COG$ Class</button><?= ops_admin_help_button('Save COG$ Class', 'Saving writes the class configuration into the live token catalog. Verify downstream wallet, approval, and admin behavior after structural changes.') ?>
        <button type="button" class="btn secondary" id="clearCreate">New class</button>
      </div>
      <div class="help" style="margin-top:12px">System class_code values remain protected. Identity classes keep their hard limit of 1, LR COG remains capped at 1,000, and all other classes default to 999,999.</div>
    </form>
  </div>
</div>

<script>
(function(){
  const overlay = document.getElementById('classOverlay');
  const closeBtn = document.getElementById('closeOverlay');
  const openCreate = document.getElementById('openCreate');
  const clearCreate = document.getElementById('clearCreate');
  const title = document.getElementById('drawerTitle');
  const form = document.getElementById('classForm');
  if (!overlay || !form) return;

  const fields = {
    class_id: document.getElementById('f_class_id'),
    class_code: document.getElementById('f_class_code'),
    admin_code: document.getElementById('f_admin_code'),
    unit_class_code: document.getElementById('f_unit_class_code'),
    display_name: document.getElementById('f_display_name'),
    member_type: document.getElementById('f_member_type'),
    unit_price: document.getElementById('f_unit_price'),
    min_units: document.getElementById('f_min_units'),
    max_units: document.getElementById('f_max_units'),
    step_units: document.getElementById('f_step_units'),
    display_order: document.getElementById('f_display_order'),
    is_active: document.getElementById('f_is_active'),
    is_locked: document.getElementById('f_is_locked'),
    approval_required: document.getElementById('f_approval_required'),
    payment_required: document.getElementById('f_payment_required'),
    wallet_visible: document.getElementById('f_wallet_visible'),
    wallet_editable: document.getElementById('f_wallet_editable'),
    admin_creatable: document.getElementById('f_admin_creatable')
  };

  function openOverlay(){ overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false'); }
  function closeOverlayNow(){ overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); }
  function setCheckbox(el, val){ if (el) el.checked = String(val) === '1'; }
  function fillFromRow(row){
    title.textContent = 'Edit COG$ Class';
    fields.class_id.value = row.dataset.id || '';
    fields.class_code.value = row.dataset.classCode || '';
    fields.admin_code.value = row.dataset.adminCode || '';
    fields.unit_class_code.value = row.dataset.unitClassCode || '';
    fields.display_name.value = row.dataset.displayName || '';
    fields.member_type.value = row.dataset.memberType || 'both';
    fields.unit_price.value = row.dataset.unitPrice || '0.00';
    fields.min_units.value = row.dataset.minUnits || '0';
    fields.max_units.value = row.dataset.maxUnits || '0';
    fields.step_units.value = row.dataset.stepUnits || '1';
    fields.display_order.value = row.dataset.displayOrder || '999';
    setCheckbox(fields.is_active, row.dataset.isActive);
    setCheckbox(fields.is_locked, row.dataset.isLocked);
    setCheckbox(fields.approval_required, row.dataset.approvalRequired);
    setCheckbox(fields.payment_required, row.dataset.paymentRequired);
    setCheckbox(fields.wallet_visible, row.dataset.walletVisible);
    setCheckbox(fields.wallet_editable, row.dataset.walletEditable);
    setCheckbox(fields.admin_creatable, row.dataset.adminCreatable);
    openOverlay();
  }
  function clearForCreate(){
    title.textContent = 'Add COG$ Class';
    fields.class_id.value = '';
    fields.class_code.value = '';
    fields.admin_code.value = '';
    fields.unit_class_code.value = '';
    fields.display_name.value = '';
    fields.member_type.value = 'both';
    fields.unit_price.value = '0.00';
    fields.min_units.value = '0';
    fields.max_units.value = '999999';
    fields.step_units.value = '1';
    fields.display_order.value = '999';
    fields.is_active.checked = true;
    fields.is_locked.checked = false;
    fields.approval_required.checked = true;
    fields.payment_required.checked = true;
    fields.wallet_visible.checked = true;
    fields.wallet_editable.checked = true;
    fields.admin_creatable.checked = true;
    openOverlay();
  }

  document.querySelectorAll('tr.clickable').forEach(function(row){
    row.addEventListener('click', function(e){
      if (e.target.closest('a,button,input,select,label')) return;
      fillFromRow(row);
    });
  });
  if (openCreate) openCreate.addEventListener('click', clearForCreate);
  if (clearCreate) clearCreate.addEventListener('click', clearForCreate);
  if (closeBtn) closeBtn.addEventListener('click', closeOverlayNow);
  overlay.addEventListener('click', function(e){ if (e.target === overlay) closeOverlayNow(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeOverlayNow(); });
})();
</script>
</body>
</html>

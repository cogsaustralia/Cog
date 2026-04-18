<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

if (!function_exists('h')) {
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('rows')) {
function rows(PDO $pdo, string $sql, array $params=[]): array { $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
}
if (!function_exists('one')) {
function one(PDO $pdo, string $sql, array $params=[]): ?array { $st=$pdo->prepare($sql); $st->execute($params); $row=$st->fetch(PDO::FETCH_ASSOC); return $row ?: null; }
}
if (!function_exists('now_dt')) {
function now_dt(): string { return date('Y-m-d H:i:s'); }
}

if (!function_exists('class_meta')) {
function class_meta(string $code): array {
    $map = [
        'PERSONAL_SNFT'      => ['code' => 'S-NFT',             'opens_member' => true,  'payable_now' => true],
        'KIDS_SNFT'          => ['code' => 'kS-NFT',            'opens_member' => false, 'payable_now' => true],
        'BUSINESS_BNFT'      => ['code' => 'B-NFT',             'opens_member' => true,  'payable_now' => true],
        'LANDHOLDER_COG'     => ['code' => 'LANDHOLDER_COG',    'opens_member' => false, 'payable_now' => false],
        'ASX_INVESTMENT_COG' => ['code' => 'ASX_INVESTMENT_COG','opens_member' => false, 'payable_now' => false],
        'PAY_IT_FORWARD_COG' => ['code' => 'PAY_IT_FORWARD_COG','opens_member' => false, 'payable_now' => true],
        'DONATION_COG'       => ['code' => 'DONATION_COG',      'opens_member' => false, 'payable_now' => true],
        'RWA_COG'            => ['code' => 'RWA_COG',           'opens_member' => false, 'payable_now' => false],
        'LR_COG'             => ['code' => 'LR_COG',            'opens_member' => false, 'payable_now' => false],
        'BUS_PROP_COG'       => ['code' => 'BUS_PROP_COG',      'opens_member' => false, 'payable_now' => false],
        'COM_COG'            => ['code' => 'COM_COG',           'opens_member' => false, 'payable_now' => false],
    ];
    return $map[$code] ?? ['code' => $code, 'opens_member' => false, 'payable_now' => false];
}
}

if (!function_exists('fetch_request_row')) {
function fetch_request_row(PDO $pdo, int $requestId=0, int $memberId=0, int $tokenClassId=0): ?array {
    $sql = "
        SELECT ar.*, m.full_name, m.member_type, m.member_number, m.abn, m.email,
               m.wallet_status, m.signup_payment_status, m.stewardship_status,
               mrl.payment_status, mrl.approval_status,
               mrl.requested_units AS line_requested_units,
               mrl.paid_units, mrl.approved_units,
               tc.class_code, tc.display_name, tc.unit_price_cents
        FROM approval_requests ar
        INNER JOIN members m ON m.id = ar.member_id
        LEFT JOIN member_reservation_lines mrl
            ON mrl.member_id = ar.member_id AND mrl.token_class_id = ar.token_class_id
        LEFT JOIN token_classes tc ON tc.id = ar.token_class_id
    ";
    if ($requestId > 0) {
        return one($pdo, $sql . " WHERE ar.id = ? LIMIT 1", [$requestId]);
    }
    if ($memberId > 0 && $tokenClassId > 0) {
        return one($pdo, $sql . " WHERE ar.member_id = ? AND ar.token_class_id = ? ORDER BY ar.id DESC LIMIT 1", [$memberId, $tokenClassId]);
    }
    return null;
}
}

if (!function_exists('ensure_request_exists')) {
function ensure_request_exists(PDO $pdo, int $adminId, int $memberId, int $tokenClassId): ?array {
    $existing = fetch_request_row($pdo, 0, $memberId, $tokenClassId);
    if ($existing) return $existing;
    $line  = one($pdo, "SELECT * FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1", [$memberId, $tokenClassId]);
    $token = one($pdo, "SELECT * FROM token_classes WHERE id = ? LIMIT 1", [$tokenClassId]);
    if (!$line || !$token) return null;
    $meta = class_meta((string)$token['class_code']);
    $reqType = $meta['payable_now'] ? 'signup_payment' : 'manual_approval';
    $reqUnits = (int)$line['requested_units'];
    $reqValue = $reqUnits * (int)($token['unit_price_cents'] ?? 0);
    $pdo->prepare("
        INSERT INTO approval_requests
            (member_id, token_class_id, request_type, requested_units, requested_value_cents, request_status, notes, created_by_admin_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
    ")->execute([$memberId, $tokenClassId, $reqType, $reqUnits, $reqValue,
        $meta['payable_now'] ? 'Auto-rebuilt for payable COG$' : 'Auto-rebuilt for reserved COG$',
        $adminId ?: null, now_dt(), now_dt()]);
    return fetch_request_row($pdo, (int)$pdo->lastInsertId(), 0, 0);
}
}

if (!function_exists('sbadge')) {
function sbadge(string $text): string {
    return '<span class="chip">'.h($text).'</span>';
}
}

// ── POST handler ─────────────────────────────────────────────────────────────
$flash = null; $error = null;
$adminId = function_exists('ops_current_admin_id') ? (int)ops_current_admin_id($pdo) : 0;
$view    = (string)($_GET['view'] ?? 'member');  // 'member' or 'token'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action       = (string)($_POST['action'] ?? '');
        $requestId    = (int)($_POST['request_id'] ?? 0);
        $memberId     = (int)($_POST['member_id'] ?? 0);
        $tokenClassId = (int)($_POST['token_class_id'] ?? 0);
        $notes        = trim((string)($_POST['notes'] ?? ''));

        $req = fetch_request_row($pdo, $requestId, 0, 0);
        if (!$req && $memberId > 0 && $tokenClassId > 0) $req = fetch_request_row($pdo, 0, $memberId, $tokenClassId);
        if (!$req && $memberId > 0 && $tokenClassId > 0) $req = ensure_request_exists($pdo, $adminId, $memberId, $tokenClassId);
        if (!$req) throw new RuntimeException('Approval request could not be found or rebuilt. Refresh and try again.');

        $meta = class_meta((string)($req['class_code'] ?? ''));
        $line = one($pdo, "SELECT * FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1",
            [(int)$req['member_id'], (int)$req['token_class_id']]);
        if (!$line) throw new RuntimeException('COG$ reservation line not found for this member.');

        $pdo->beginTransaction();

        if ($action === 'approve') {
            if ($meta['payable_now']) {
                $requested = (int)$line['requested_units'];
                $paid      = (int)$line['paid_units'];
                if ($requested > 0 && $paid < $requested) {
                    throw new RuntimeException('This COG$ cannot be approved until payment has been recorded for the full reserved amount.');
                }
                $approvedUnits = max((float)$line['approved_units'], (float)$requested);
            } else {
                $approvedUnits = max((float)$line['approved_units'], (float)$line['requested_units']);
            }
            $pdo->prepare("UPDATE approval_requests SET request_status='approved', reviewed_by_admin_id=?, reviewed_at=NOW(), mint_status=?, signoff_status='pending_manual_signoff', notes=CONCAT(COALESCE(notes,''),?), updated_at=NOW() WHERE id=?")
                ->execute([$adminId ?: null, (function_exists('ops_asset_backing_token_mode') && ops_asset_backing_token_mode($pdo, (int)$req['token_class_id'])) ? 'awaiting_asset_backing' : 'prepared', $notes !== '' ? "\nAPPROVED: ".$notes : '', (int)$req['id']]);
            $pdo->prepare("UPDATE member_reservation_lines SET approval_status='approved', approved_units=?, approved_at=NOW(), approved_by_admin_id=?, updated_at=NOW() WHERE member_id=? AND token_class_id=?")
                ->execute([$approvedUnits, $adminId ?: null, (int)$req['member_id'], (int)$req['token_class_id']]);
            if ($meta['opens_member']) {
                $pdo->prepare("UPDATE members SET wallet_status='active', stewardship_status='active', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$req['member_id']]);
            }
            $mq = one($pdo, "SELECT id FROM mint_queue WHERE approval_request_id=? LIMIT 1", [(int)$req['id']]);
            if ($mq) {
                $pdo->prepare("UPDATE mint_queue SET approved_units=?, queue_status=?, notes=?, signed_off_by_admin_id=?, signed_off_at=NOW(), created_by_admin_id=COALESCE(created_by_admin_id,?), updated_at=NOW(), live_status='not_live' WHERE id=?")
                    ->execute([$approvedUnits, (function_exists('ops_asset_backing_token_mode') && ops_asset_backing_token_mode($pdo, (int)$req['token_class_id'])) ? 'awaiting_asset_backing' : 'prepared', $notes ?: null, $adminId ?: null, $adminId ?: null, (int)$mq['id']]);
            } else {
                $pdo->prepare("INSERT INTO mint_queue (approval_request_id,member_id,token_class_id,approved_units,queue_status,notes,manual_signoff_lane,signed_off_by_admin_id,signed_off_at,created_by_admin_id,created_at,updated_at,live_status) VALUES (?,?,?,?,?,?,'manual_general',?,NOW(),?,NOW(),NOW(),'not_live')")
                    ->execute([(int)$req['id'], (int)$req['member_id'], (int)$req['token_class_id'], $approvedUnits, (function_exists('ops_asset_backing_token_mode') && ops_asset_backing_token_mode($pdo, (int)$req['token_class_id'])) ? 'awaiting_asset_backing' : 'prepared', $notes ?: null, $adminId ?: null, $adminId ?: null]);
            }
            if (function_exists('ops_log_wallet_activity')) {
                ops_log_wallet_activity($pdo, (int)$req['member_id'], (int)$req['token_class_id'], 'approval_approved', 'admin', $adminId ?: null,
                    ['approval_request_id' => (int)$req['id'], 'cog' => $meta['code']]);
            }
            if (function_exists('ops_asset_backing_sync_approval_state')) { ops_asset_backing_sync_approval_state($pdo, (int)$req['id']); }
            $needsBacking = function_exists('ops_asset_backing_status_for_approval') ? !empty(ops_asset_backing_status_for_approval($pdo, (int)$req['id'])['required']) : false;
            $flash = $needsBacking ? 'COG$ approved. This stewardship class now needs asset backing before it can move into execution.' : 'COG$ approved and the flow chain has been advanced.';

        } elseif ($action === 'hold') {
            $pdo->prepare("UPDATE approval_requests SET request_status='pending', notes=CONCAT(COALESCE(notes,''),?), updated_at=NOW() WHERE id=?")
                ->execute([$notes !== '' ? "\nHOLD: ".$notes : '', (int)$req['id']]);
            $flash = 'COG$ approval left pending with note.';

        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE approval_requests SET request_status='rejected', reviewed_by_admin_id=?, reviewed_at=NOW(), mint_status='not_queued', notes=CONCAT(COALESCE(notes,''),?), updated_at=NOW() WHERE id=?")
                ->execute([$adminId ?: null, $notes !== '' ? "\nREJECTED: ".$notes : '', (int)$req['id']]);
            $pdo->prepare("UPDATE member_reservation_lines SET approval_status='rejected', updated_at=NOW() WHERE member_id=? AND token_class_id=?")
                ->execute([(int)$req['member_id'], (int)$req['token_class_id']]);
            if (function_exists('ops_log_wallet_activity')) {
                ops_log_wallet_activity($pdo, (int)$req['member_id'], (int)$req['token_class_id'], 'approval_rejected', 'admin', $adminId ?: null,
                    ['approval_request_id' => (int)$req['id'], 'cog' => $meta['code']]);
            }
            $flash = 'COG$ approval rejected.';
        } else {
            throw new RuntimeException('Unsupported action.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── Data load ─────────────────────────────────────────────────────────────────
// ── Filter params ─────────────────────────────────────────────────────────────
$fSearch = trim((string)($_GET['search'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));
$fClass  = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string)($_GET['class'] ?? ''))));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$allRows = rows($pdo, "
    SELECT ar.*, m.full_name, m.member_type, m.member_number, m.abn, m.email,
           m.wallet_status, m.signup_payment_status, m.stewardship_status,
           mrl.payment_status, mrl.approval_status,
           mrl.requested_units AS line_requested_units, mrl.paid_units, mrl.approved_units,
           tc.class_code, tc.display_name, tc.unit_price_cents
    FROM approval_requests ar
    LEFT JOIN members m ON m.id = ar.member_id
    LEFT JOIN member_reservation_lines mrl
        ON mrl.member_id = ar.member_id AND mrl.token_class_id = ar.token_class_id
    LEFT JOIN token_classes tc ON tc.id = ar.token_class_id
    ORDER BY m.full_name ASC, tc.display_order ASC, ar.id ASC
    LIMIT 500
");

foreach ($allRows as &$row) {
    $meta = class_meta((string)($row['class_code'] ?? ''));
    $row['admin_code']   = $meta['code'];
    $row['opens_member'] = $meta['opens_member'];
    $row['payable_now']  = $meta['payable_now'];
}
unset($row);

// PHP-side filter
if ($fSearch !== '' || $fStatus !== '' || $fClass !== '') {
    $needle = strtolower($fSearch);
    $allRows = array_values(array_filter($allRows, function($r) use ($needle, $fStatus, $fClass) {
        if ($needle !== '') {
            $hay = strtolower(implode(' ', [
                $r['full_name'] ?? '', $r['email'] ?? '', $r['member_number'] ?? '', $r['abn'] ?? ''
            ]));
            if (strpos($hay, $needle) === false) return false;
        }
        if ($fStatus !== '' && ($r['request_status'] ?? '') !== $fStatus) return false;
        if ($fClass  !== '' && ($r['class_code'] ?? '') !== $fClass)  return false;
        return true;
    }));
}

// ── Group by Member ───────────────────────────────────────────────────────────
$memberGroups = []; // [memberId => [meta, pending=>[], processed=>[]]]
foreach ($allRows as $row) {
    $mid = (int)($row['member_id'] ?? 0);
    if (!isset($memberGroups[$mid])) {
        $memberGroups[$mid] = [
            'member_id'             => $mid,
            'full_name'             => (string)($row['full_name'] ?? ''),
            'member_type'           => (string)($row['member_type'] ?? ''),
            'member_number'         => (string)($row['member_number'] ?? ''),
            'abn'                   => (string)($row['abn'] ?? ''),
            'email'                 => (string)($row['email'] ?? ''),
            'wallet_status'         => (string)($row['wallet_status'] ?? ''),
            'signup_payment_status' => (string)($row['signup_payment_status'] ?? ''),
            'stewardship_status'    => (string)($row['stewardship_status'] ?? ''),
            'pending'               => [],
            'processed'             => [],
        ];
    }
    $isPending = ($row['request_status'] ?? '') === 'pending';
    $memberGroups[$mid][$isPending ? 'pending' : 'processed'][] = $row;
}
$approvalAcceptance = function_exists('ops_member_acceptance_map') ? ops_member_acceptance_map($pdo, array_keys($memberGroups)) : [];

// ── Group by Token Class ──────────────────────────────────────────────────────
$tokenGroups = []; // [classCode => [meta, pending=>[], processed=>[]]]
foreach ($allRows as $row) {
    $code = (string)($row['class_code'] ?? 'UNKNOWN');
    if (!isset($tokenGroups[$code])) {
        $tokenGroups[$code] = [
            'class_code'   => $code,
            'display_name' => (string)($row['display_name'] ?? $code),
            'admin_code'   => (string)($row['admin_code'] ?? $code),
            'payable_now'  => !empty($row['payable_now']),
            'opens_member' => !empty($row['opens_member']),
            'pending'      => [],
            'processed'    => [],
        ];
    }
    $isPending = ($row['request_status'] ?? '') === 'pending';
    $tokenGroups[$code][$isPending ? 'pending' : 'processed'][] = $row;
}

// Sort token groups by display_name
uasort($tokenGroups, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));

// ── Render helpers ────────────────────────────────────────────────────────────
if (!function_exists('action_form')) {
function action_form(array $r, string $view): string {
    $csrf = h(admin_csrf_token());
    $rid  = h((string)($r['id'] ?? ''));
    $mid  = h((string)($r['member_id'] ?? ''));
    $tcid = h((string)($r['token_class_id'] ?? ''));
    $back = h("?view={$view}");
    return "<form method='post' action='{$back}'>
      <input type='hidden' name='_csrf' value='{$csrf}'>
      <input type='hidden' name='request_id' value='{$rid}'>
      <input type='hidden' name='member_id' value='{$mid}'>
      <input type='hidden' name='token_class_id' value='{$tcid}'>
      <textarea name='notes' placeholder='Optional note'></textarea>
      <div class='btns' style='margin-top:8px'>
        <button type='submit' name='action' value='approve' class='btn-approve'>Approve</button>
        <button type='submit' name='action' value='hold' class='btn-hold'>Hold</button>
        <button type='submit' name='action' value='reject' class='btn-reject'>Reject</button>
      </div></form>";
}
}

if (!function_exists('cog_rows')) {
function cog_rows(array $rows = [], bool $allowActions = false, string $view = 'member', bool $showMember = false): void {
    if (!$rows) { echo '<p class="empty-row">No COG$ in this group.</p>'; return; }
    echo '<table><thead><tr>';
    if ($showMember) echo '<th>Member</th>';
    echo '<th>COG$</th><th>Reserved</th><th>Paid</th><th>Approved</th><th>Status</th>';
    echo $allowActions ? '<th>Action</th>' : '<th>Reviewed</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $isPaid   = (int)($r['paid_units'] ?? 0) >= (int)($r['requested_units'] ?? 0);
        $payClass = !empty($r['payable_now']) ? ($isPaid ? 'tag-paid' : 'tag-unpaid') : 'tag-free';
        $payLabel = !empty($r['payable_now']) ? ($isPaid ? 'Paid' : 'Payment due') : 'No fee';
        echo '<tr>';
        if ($showMember) {
            $ref = ($r['member_type'] ?? '') === 'business' ? ($r['abn'] ?? '') : ($r['member_number'] ?? '');
            echo '<td><strong>'.h((string)($r['full_name'] ?? '—')).'</strong><div class="sub">'.h($ref).'</div></td>';
        }
        echo '<td><strong>'.h((string)($r['display_name'] ?? $r['admin_code'] ?? '')).'</strong>';
        echo '<div class="sub">'.h((string)($r['admin_code'] ?? '')).'</div>';
        if (!empty($r['opens_member'])) echo '<span class="tag tag-opener">Opens member</span>';
        echo '</td>';
        echo '<td>'.number_format((int)($r['requested_units'] ?? 0)).'</td>';
        echo '<td>'.number_format((int)($r['paid_units'] ?? 0)).'</td>';
        echo '<td>'.number_format((float)($r['approved_units'] ?? 0), 4).'</td>';
        echo '<td>';
        echo sbadge((string)($r['request_status'] ?? ''));
        echo '<span class="tag '.$payClass.'">'.$payLabel.'</span>';
        global $pdo;
        if ($pdo instanceof PDO && function_exists('ops_asset_backing_status_for_approval')) {
            $backing = ops_asset_backing_status_for_approval($pdo, (int)($r['id'] ?? 0));
            if (!empty($backing['required'])) {
                $label = !empty($backing['is_fully_backed']) ? 'Asset backed' : 'Awaiting backing';
                $cls = !empty($backing['is_fully_backed']) ? 'tag-paid' : 'tag-unpaid';
                echo '<div style="margin-top:6px"><span class="tag '.$cls.'">'.h($label).'</span> <a class="sub" href="./asset_backing.php#approval-'.(int)($r['id'] ?? 0).'">Open backing</a></div>';
            }
        }
        echo '</td>';
        if ($allowActions) {
            echo '<td>'.action_form($r, $view).'</td>';
        } else {
            echo '<td class="sub">'.h((string)($r['reviewed_at'] ?? '—')).'</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}
}

$pendingMemberCount  = count(array_filter($memberGroups, fn($m) => !empty($m['pending'])));
$pendingTokenClasses = count(array_filter($tokenGroups,  fn($t) => !empty($t['pending'])));

// ── Pagination: slice groups for current view/page ─────────────────────────────
$memberGroupsAll = $memberGroups;
$tokenGroupsAll  = $tokenGroups;
$totalMemberGroups = count($memberGroupsAll);
$totalTokenGroups  = count($tokenGroupsAll);
$totalMemberPages  = max(1, (int)ceil($totalMemberGroups / $perPage));
$totalTokenPages   = max(1, (int)ceil($totalTokenGroups  / $perPage));
$memberPage = min($page, $totalMemberPages);
$tokenPage  = min($page, $totalTokenPages);
$memberGroups = array_slice($memberGroupsAll, ($memberPage - 1) * $perPage, $perPage, true);
$tokenGroups  = array_slice($tokenGroupsAll,  ($tokenPage  - 1) * $perPage, $perPage, true);

if (!function_exists('render_pager')) {
    function render_pager(string $base, int $page, int $totalPages, int $total, string $label = 'result'): string {
        if ($totalPages <= 1 && $total <= 20) return '';
        $sfx = $total !== 1 ? 's' : '';
        $ue  = fn(int $pg): string => htmlspecialchars($base . 'page=' . $pg, ENT_QUOTES, 'UTF-8');
        $o   = '<div class="pager"><span class="pg-info">' . number_format($total) . ' ' . $label . $sfx . '</span>';
        if ($page > 1) {
            $o .= '<a href="' . $ue(1) . '">«</a><a href="' . $ue($page - 1) . '">‹ Prev</a>';
        } else { $o .= '<span>«</span><span>‹ Prev</span>'; }
        for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++) {
            $o .= $pg === $page
                ? '<span class="pg-current">' . $pg . '</span>'
                : '<a href="' . $ue($pg) . '">' . $pg . '</a>';
        }
        if ($page < $totalPages) {
            $o .= '<a href="' . $ue($page + 1) . '">Next ›</a><a href="' . $ue($totalPages) . '">»</a>';
        } else { $o .= '<span>Next ›</span><span>»</span>'; }
        return $o . '</div>';
    }
}

// Build pager base URLs (preserve filter params, reset page on view switch)
$filterParts = array_filter(['search' => $fSearch, 'status' => $fStatus, 'class' => $fClass], fn($v) => $v !== '');
$filterQsRaw = $filterParts ? '&' . http_build_query($filterParts) : '';
$memberPagerBase = 'approvals.php?view=member' . $filterQsRaw . '&';
$tokenPagerBase  = 'approvals.php?view=token'  . $filterQsRaw . '&';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./assets/admin.min.css">
<title>Approvals</title>
<style>.main{padding:20px;min-width:0}
.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:20px;padding:16px;margin-bottom:16px}

/* View toggle */
.view-tabs{display:flex;gap:0;margin-bottom:20px;border:1px solid var(--line);border-radius:12px;overflow:hidden;width:fit-content}
.view-tab{padding:9px 22px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;border-right:1px solid var(--line);transition:all .15s}
.view-tab:last-child{border-right:none}
.view-tab.active{background:rgba(212,178,92,.15);color:var(--gold)}
.view-tab:hover:not(.active){background:rgba(255,255,255,.04);color:var(--text)}

/* Accordion member/token cards */
.acc-card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:16px;margin-bottom:10px;overflow:hidden}
.acc-header{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;transition:background .15s}
.acc-header:hover{background:rgba(255,255,255,.03)}
.acc-header.open{border-bottom:1px solid var(--line)}
.acc-chevron{margin-left:auto;font-size:11px;color:var(--muted);transition:transform .2s;flex-shrink:0}
.acc-header.open .acc-chevron{transform:rotate(180deg)}
.acc-body{display:none;padding:16px 18px}
.acc-body.open{display:block}

/* Member header info */
.member-name{font-size:15px;font-weight:600}
.member-ref{font-size:12px;color:var(--muted);margin-top:2px}
.member-chips{display:flex;flex-wrap:wrap;gap:6px;margin-left:auto;margin-right:12px}

/* Token header info */
.token-name{font-size:15px;font-weight:600}
.token-sub{font-size:12px;color:var(--muted);margin-top:2px}

/* Section label */
.section-label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:14px 0 8px;padding-top:10px;border-top:1px solid var(--line)}
.section-label:first-child{margin-top:0;padding-top:0;border-top:none}

/* COG$ table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left;vertical-align:top;font-size:13px}
th{color:var(--muted);font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
td.sub{color:var(--muted);font-size:12px}

/* Tags / chips */
.chip{display:inline-block;padding:3px 9px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);font-size:11px;margin:2px 3px 2px 0;white-space:nowrap}
.tag{display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin:2px 3px 2px 0}
.tag-opener{background:rgba(90,158,212,.12);color:#7ab8e8;border:1px solid rgba(90,158,212,.22)}
.tag-paid{background:var(--okb);color:var(--ok);border:1px solid rgba(82,184,122,.22)}
.tag-unpaid{background:var(--warnb);color:var(--warn);border:1px solid rgba(200,144,26,.22)}
.tag-free{background:rgba(255,255,255,.04);color:var(--muted);border:1px solid var(--line)}
.count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;font-size:11px;font-weight:700;background:rgba(212,178,92,.15);color:var(--gold);border:1px solid rgba(212,178,92,.2)}
.count-badge.pending{background:rgba(200,144,26,.15);color:var(--warn);border-color:rgba(200,144,26,.25)}

/* Action forms */
textarea{width:100%;background:#0f1720;border:1px solid var(--line);color:var(--text);padding:.7rem .8rem;border-radius:10px;font:inherit;min-height:56px;resize:vertical}
.btns{display:flex;gap:7px;flex-wrap:wrap;margin-top:7px}
button{padding:7px 14px;border-radius:9px;font:inherit;font-weight:700;font-size:12px;cursor:pointer;border:1px solid transparent;transition:opacity .15s}
button:hover{opacity:.82}
.btn-approve{background:#d4b25c;color:#201507}
.btn-hold{background:rgba(255,255,255,.07);color:var(--text);border-color:var(--line)}
.btn-reject{background:var(--errb);color:var(--err);border-color:rgba(196,96,96,.25)}

/* Feedback */
.msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-size:13px}
.ok{background:var(--okb);color:var(--ok);border:1px solid rgba(82,184,122,.3)}
.err{background:var(--errb);color:var(--err);border:1px solid rgba(196,96,96,.3)}
.empty-row{color:var(--muted);font-size:13px;padding:8px 0;margin:0}
.sub{color:var(--muted);font-size:12px}

/* Section summary counts */
.section-hd{display:flex;align-items:center;gap:10px;margin:20px 0 10px}
.section-hd h2{margin:0;font-size:16px}

@media(max-width:820px){.member-chips{display:none}.main{padding:12px}}
</style>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="admin-shell">
<?php admin_sidebar_render('approvals'); ?>
<main class="main">

  <div class="card">
    <h1 style="margin:0 0 6px">COG$ Approvals <?= ops_admin_help_button('Approvals', 'Use Approvals to review and sign off reservation lines once intake and payment requirements are satisfied. This page does not record money received and it does not publish ledger outcomes. After approval, the next live operator page is Execution.') ?></h1>
    <p class="sub">This is the authoritative approvals surface for reservation sign-off before execution. Switch between Partner view (one card per partner, COG$ listed inside) and COG$ view (one card per token class, partners listed inside).</p>
  </div>

  <?= ops_admin_info_panel('Intake · Step 2', 'What this page does', 'Use this page to decide whether a reservation line is ready to progress into execution. It sits after payment or evidence collection and before any execution batch is created.', [
    'Approve when the line is ready to move into execution.',
    'Hold when more evidence or operator review is needed.',
    'Reject when the line should not proceed in its current state.'
  ]) ?>

  <?= ops_admin_workflow_panel('Typical workflow', 'Approvals is the sign-off lane between intake and execution.', [
    ['title' => 'Check intake status', 'body' => 'Confirm payment, JVPA, KYC, and other evidence is in a usable state.'],
    ['title' => 'Review the line', 'body' => 'Assess the COG$ line in either Partner view or COG$ view.'],
    ['title' => 'Approve, hold, or reject', 'body' => 'Record the decision with notes so later operators know why the line moved or stopped.'],
    ['title' => 'Move to execution', 'body' => 'Approved lines can then be turned into execution requests and batched.']
  ]) ?>

  <?= ops_admin_guide_panel('How to use the two views', 'Both views act on the same underlying approval records.', [
    ['title' => 'By Partner', 'body' => 'Best when you want to review one Partner\'s full intake picture and all lines together.'],
    ['title' => 'By COG$', 'body' => 'Best when you want to review one token class across many Partners in one pass.']
  ]) ?>

  <?= ops_admin_status_panel('Status guide', 'These indicators tell you whether the line is operationally ready.', [
    ['label' => 'Wallet / Stewardship', 'body' => 'Shows the broader member record state around the approval lane.'],
    ['label' => 'Payment', 'body' => 'Shows whether any paid-now requirement has been satisfied before approval.'],
    ['label' => 'JVPA', 'body' => 'Shows whether the backend partnership acceptance trail is complete enough to support the approval.'],
    ['label' => 'Approve / Hold / Reject', 'body' => 'Approve moves the line forward, Hold pauses it for more review, Reject closes it out in its current form.']
  ]) ?>

  <?php if ($flash): ?><div class="msg ok"><?=h($flash)?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

  <form method="get" style="margin-bottom:0">
  <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?=h($view)?>"> <?php endif; ?>
  <div class="filter-bar">
    <div class="filter-group">
      <label>Name / email / member no.</label>
      <input type="text" name="search" value="<?=h($fSearch)?>" placeholder="Search…" style="min-width:200px">
    </div>
    <div class="filter-group">
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach (['pending','approved','rejected','held'] as $st): ?>
          <option value="<?=h($st)?>"<?=$fStatus===$st?' selected':''?>><?=ucfirst($st)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group">
      <label>Token class</label>
      <select name="class">
        <option value="">All classes</option>
        <?php
        $distinctClasses = array_unique(array_filter(array_column(
            rows($pdo, "SELECT DISTINCT class_code FROM token_classes ORDER BY class_code"), 'class_code'
        )));
        foreach ($distinctClasses as $cc): ?>
          <option value="<?=h($cc)?>"<?=$fClass===$cc?' selected':''?>><?=h($cc)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:6px;align-items:flex-end">
      <button type="submit" class="btn btn-sm" style="background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)">Filter</button>
      <a href="approvals.php<?=$view?'?view='.h($view):''?>" class="btn btn-sm">Reset</a>
    </div>
    <?php if ($fSearch !== '' || $fStatus !== '' || $fClass !== ''): ?>
      <span style="font-size:11px;color:var(--gold);align-self:center"><?=count($allRows)?> match<?=count($allRows)!==1?'es':''?></span>
    <?php endif; ?>
  </div>
  </form>

  <!-- View toggle -->
  <?php
  $filterQs = http_build_query(array_filter([
      'search' => $fSearch, 'status' => $fStatus, 'class' => $fClass
  ], fn($v) => $v !== ''));
  $filterQs = $filterQs ? '&' . $filterQs : '';
  ?>
  <div class="view-tabs">
    <a class="view-tab <?=$view==='member'?'active':''?>" href="?view=member<?=$filterQs?>">By Partner</a>
    <a class="view-tab <?=$view==='token'?'active':''?>"  href="?view=token<?=$filterQs?>">By COG$</a>
  </div>

<?php if ($view === 'member'): ?>
<!-- ═══════════════════════ MEMBER VIEW ════════════════════════════════════ -->

  <div class="section-hd">
    <h2>Pending</h2>
    <span class="count-badge pending"><?=count(array_filter($memberGroups, fn($m) => !empty($m['pending'])))?> partners</span>
  </div>

  <?php
  $hasPending = false;
  foreach ($memberGroups as $mid => $m):
    if (empty($m['pending'])) continue;
    $hasPending = true;
    $ref = $m['member_type'] === 'business' ? $m['abn'] : $m['member_number'];
    $uid = 'mp-'.$mid;
    $pendingCount = count($m['pending']);
    $acceptance = $approvalAcceptance[(int)$mid] ?? null;
    $acceptanceLabel = function_exists('ops_acceptance_status_label') ? ops_acceptance_status_label($acceptance) : '—';
    $acceptanceTone = function_exists('ops_acceptance_status_tone') ? ops_acceptance_status_tone($acceptance) : 'warn';
  ?>
  <div class="acc-card">
    <div class="acc-header" onclick="toggle('<?=$uid?>')">
      <div>
        <div class="member-name"><?=h($m['full_name'])?></div>
        <div class="member-ref"><?=h($ref)?> &middot; <?=h($m['email'])?></div>
      </div>
      <div class="member-chips">
        <?=sbadge('Wallet: '.$m['wallet_status'])?>
        <?=sbadge('Payment: '.$m['signup_payment_status'])?>
        <span class="chip" style="border-color:<?= $acceptanceTone==='ok' ? 'rgba(82,184,122,.35)' : ($acceptanceTone==='warn' ? 'rgba(200,144,26,.35)' : 'rgba(196,96,96,.35)') ?>;color:<?= $acceptanceTone==='ok' ? 'var(--ok)' : ($acceptanceTone==='warn' ? 'var(--warn)' : 'var(--bad)') ?>">JVPA <?= ops_admin_help_button('JVPA in approvals', 'Approvals should not outrun the intake evidence trail. This indicator shows whether the backend partnership acceptance record is complete, legacy, or missing.') ?>: <?=h($acceptanceLabel)?></span>
      </div>
      <span class="count-badge pending"><?=$pendingCount?> COG$</span>
      <span class="acc-chevron">▼</span>
    </div>
    <div class="acc-body" id="<?=$uid?>">
      <?php if ($acceptanceTone !== 'ok'): ?><div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.06);font-size:.82rem;color:var(--text);background:<?= $acceptanceTone==='bad' ? 'rgba(196,96,96,.10)' : 'rgba(200,144,26,.10)' ?>">This approval lane is ahead of the JVPA acceptance trail. Finish the intake evidence record before treating the Partner as fully compliant.</div><?php endif; ?>
      <div class="table-wrap">
        <?php cog_rows($m['pending'], true, 'member'); ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$hasPending): ?><p class="empty-row">No pending approvals.</p><?php endif; ?>

  <div class="section-hd" style="margin-top:28px">
    <h2>Processed</h2>
    <span class="count-badge"><?=count(array_filter($memberGroups, fn($m) => !empty($m['processed'])))?> partners</span>
  </div>

  <?php
  $hasProcessed = false;
  foreach ($memberGroups as $mid => $m):
    if (empty($m['processed'])) continue;
    $hasProcessed = true;
    $ref = $m['member_type'] === 'business' ? $m['abn'] : $m['member_number'];
    $uid = 'mr-'.$mid;
    $processedCount = count($m['processed']);
    $acceptance = $approvalAcceptance[(int)$mid] ?? null;
    $acceptanceLabel = function_exists('ops_acceptance_status_label') ? ops_acceptance_status_label($acceptance) : '—';
    $acceptanceTone = function_exists('ops_acceptance_status_tone') ? ops_acceptance_status_tone($acceptance) : 'warn';
  ?>
  <div class="acc-card">
    <div class="acc-header" onclick="toggle('<?=$uid?>')">
      <div>
        <div class="member-name"><?=h($m['full_name'])?></div>
        <div class="member-ref"><?=h($ref)?> &middot; <?=h($m['email'])?></div>
      </div>
      <div class="member-chips">
        <?=sbadge('Wallet: '.$m['wallet_status'])?>
        <?=sbadge('Stewardship: '.$m['stewardship_status'])?>
      </div>
      <span class="count-badge"><?=$processedCount?> COG$</span>
      <span class="acc-chevron">▼</span>
    </div>
    <div class="acc-body" id="<?=$uid?>">
      <?php if ($acceptanceTone !== 'ok'): ?><div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.06);font-size:.82rem;color:var(--text);background:<?= $acceptanceTone==='bad' ? 'rgba(196,96,96,.10)' : 'rgba(200,144,26,.10)' ?>">This approval lane is ahead of the JVPA acceptance trail. Finish the intake evidence record before treating the Partner as fully compliant.</div><?php endif; ?>
      <div class="table-wrap">
        <?php cog_rows($m['processed'], false, 'member'); ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$hasProcessed): ?><p class="empty-row">No processed approvals yet.</p><?php endif; ?>
  <?= render_pager($memberPagerBase, $memberPage, $totalMemberPages, $totalMemberGroups, 'partner') ?>

<?php else: ?>
<!-- ═══════════════════════ TOKEN / COG$ VIEW ══════════════════════════════ -->

  <div class="section-hd">
    <h2>Pending</h2>
    <span class="count-badge pending"><?=count(array_filter($tokenGroups, fn($t) => !empty($t['pending'])))?> COG$ classes</span>
  </div>

  <?php
  $hasPending = false;
  foreach ($tokenGroups as $code => $t):
    if (empty($t['pending'])) continue;
    $hasPending = true;
    $uid = 'tp-'.preg_replace('/\W/', '_', $code);
    $pendingCount = count($t['pending']);
  ?>
  <div class="acc-card">
    <div class="acc-header" onclick="toggle('<?=$uid?>')">
      <div>
        <div class="token-name"><?=h($t['display_name'])?></div>
        <div class="token-sub"><?=h($t['admin_code'])?>
          <?php if ($t['payable_now']): ?>&middot; <span style="color:var(--warn)">Payment required</span><?php else: ?>&middot; <span class="sub">No fee / reservation</span><?php endif; ?>
          <?php if ($t['opens_member']): ?>&middot; <span style="color:#7ab8e8">Opens membership</span><?php endif; ?>
        </div>
      </div>
      <span class="count-badge pending" style="margin-left:auto;margin-right:12px"><?=$pendingCount?> member<?=$pendingCount===1?'':'s'?></span>
      <span class="acc-chevron">▼</span>
    </div>
    <div class="acc-body" id="<?=$uid?>">
      <div class="table-wrap">
        <?php cog_rows($t['pending'], true, 'token', true); ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$hasPending): ?><p class="empty-row">No pending approvals.</p><?php endif; ?>

  <div class="section-hd" style="margin-top:28px">
    <h2>Processed</h2>
    <span class="count-badge"><?=count(array_filter($tokenGroups, fn($t) => !empty($t['processed'])))?> COG$ classes</span>
  </div>

  <?php
  $hasProcessed = false;
  foreach ($tokenGroups as $code => $t):
    if (empty($t['processed'])) continue;
    $hasProcessed = true;
    $uid = 'tr-'.preg_replace('/\W/', '_', $code);
    $processedCount = count($t['processed']);
  ?>
  <div class="acc-card">
    <div class="acc-header" onclick="toggle('<?=$uid?>')">
      <div>
        <div class="token-name"><?=h($t['display_name'])?></div>
        <div class="token-sub"><?=h($t['admin_code'])?></div>
      </div>
      <span class="count-badge" style="margin-left:auto;margin-right:12px"><?=$processedCount?> member<?=$processedCount===1?'':'s'?></span>
      <span class="acc-chevron">▼</span>
    </div>
    <div class="acc-body" id="<?=$uid?>">
      <div class="table-wrap">
        <?php cog_rows($t['processed'], false, 'token', true); ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$hasProcessed): ?><p class="empty-row">No processed approvals yet.</p><?php endif; ?>
  <?= render_pager($tokenPagerBase, $tokenPage, $totalTokenPages, $totalTokenGroups, 'COG$ class') ?>

<?php endif; ?>

</main>
</div>

<script>
function toggle(id) {
  var hdr  = document.querySelector('[onclick="toggle(\'' + id + '\')"]');
  var body = document.getElementById(id);
  if (!hdr || !body) return;
  var isOpen = body.classList.toggle('open');
  hdr.classList.toggle('open', isOpen);
}
// Auto-open first pending card for convenience
document.addEventListener('DOMContentLoaded', function() {
  var first = document.querySelector('.acc-header');
  if (first) first.click();
});
</script>
</body>
</html>

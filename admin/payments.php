<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/includes/admin_token_catalog.php';

ops_require_admin();
$pdo = ops_db();

if (!function_exists('h')) {
    function h($v): string { return ops_h($v); }
}
if (!function_exists('rows')) {
    function rows(PDO $pdo, string $sql, array $params = []): array {
        return ops_fetch_all($pdo, $sql, $params);
    }
}
if (!function_exists('one')) {
    function one(PDO $pdo, string $sql, array $params = []): ?array {
        return ops_fetch_one($pdo, $sql, $params);
    }
}

// ── Token codes that require payment at join time (paid today, not reservations) ──
// PERSONAL_SNFT, KIDS_SNFT, BUSINESS_BNFT = identity tokens ($4 / $1 / $4)
// DONATION_COG, PAY_IT_FORWARD_COG        = voluntary paid-today contributions ($4 each)
$paidTodayCodes = ['PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT','DONATION_COG','PAY_IT_FORWARD_COG'];

// ── Filter params ──────────────────────────────────────────────────────────────
$fSearch = trim((string)($_GET['search'] ?? ''));
$fClass  = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string)($_GET['class'] ?? ''))));

$flash = null;
$error = null;
$adminId = ops_admin_id();

// ── POST: receive and record payments ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action       = (string)($_POST['action'] ?? '');
        $memberId     = (int)($_POST['member_id'] ?? 0);
        $tcId         = (int)($_POST['token_class_id'] ?? 0);
        $units        = (int)($_POST['units'] ?? 0);
        $ref          = trim((string)($_POST['payment_ref'] ?? ''));
        $method       = strtoupper(trim((string)($_POST['payment_method'] ?? '')));
        $amountInput  = trim((string)($_POST['amount_paid'] ?? ''));
        $note         = trim((string)($_POST['note'] ?? ''));
        $validMethods = ['CASH','EFT','CRYPTO','PAYPAL','STRIPE'];

        if (!in_array($method, $validMethods, true)) {
            throw new RuntimeException('Select a valid payment method: Cash, EFT, Crypto, or PayPal.');
        }
        if ($amountInput === '' || !is_numeric($amountInput)) {
            throw new RuntimeException('Amount paid is required.');
        }
        $amountCents = (int) round(((float)$amountInput) * 100);
        if ($amountCents <= 0) {
            throw new RuntimeException('Amount paid must be greater than zero.');
        }

        if ($action === 'mark_paid') {
            if ($memberId <= 0 || $tcId <= 0 || $units < 1) {
                throw new RuntimeException('Member, token class, and unit count are required.');
            }

            $line = one($pdo,
                'SELECT mrl.*, tc.class_code, tc.display_name, tc.unit_price_cents
                   FROM member_reservation_lines mrl
                   JOIN token_classes tc ON tc.id = mrl.token_class_id
                  WHERE mrl.member_id = ? AND mrl.token_class_id = ?
                  LIMIT 1',
                [$memberId, $tcId]
            );
            if (!$line) {
                throw new RuntimeException('Reservation line not found.');
            }

            $outstanding = max(0, (int)$line['requested_units'] - (int)$line['paid_units']);
            if ($units > $outstanding) {
                throw new RuntimeException('Units received cannot exceed the outstanding units.');
            }

            $newPaid = (int)$line['paid_units'] + $units;
            $nowPaid = min($newPaid, (int)$line['requested_units']);
            $paymentNote = 'Method: ' . $method . ($note !== '' ? "\n" . $note : '');

            $pdo->beginTransaction();

            $pdo->prepare('
                INSERT INTO payments (
                    member_id, payment_type, amount_cents, currency_code, payment_status,
                    external_reference, notes, received_at, created_by_admin_id, created_at, updated_at
                ) VALUES (?, \'manual\', ?, \'AUD\', \'paid\', ?, ?, NOW(), ?, NOW(), NOW())
            ')->execute([$memberId, $amountCents, ($ref !== '' ? $ref : null), $paymentNote, $adminId]);
            $paymentId = (int)$pdo->lastInsertId();

            if (ops_has_table($pdo, 'payment_allocations')) {
                $pdo->prepare('
                    INSERT INTO payment_allocations (
                        payment_id, member_id, token_class_id, units_allocated, amount_cents, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ')->execute([$paymentId, $memberId, $tcId, $units, $amountCents]);
            }

            $pdo->prepare('
                UPDATE member_reservation_lines
                SET paid_units      = ?,
                    payment_status  = CASE WHEN ? >= requested_units THEN \'paid\' ELSE \'pending\' END,
                    updated_at      = NOW()
                WHERE member_id = ? AND token_class_id = ?
            ')->execute([$nowPaid, $nowPaid, $memberId, $tcId]);

            $isIdentity = in_array($line['class_code'] ?? '', ['PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT'], true);
            if ($isIdentity && $nowPaid >= (int)$line['requested_units']) {
                $pdo->prepare('
                    UPDATE members
                    SET signup_payment_status = \'paid\',
                        updated_at = NOW()
                    WHERE id = ?
                ')->execute([$memberId]);
            }

            if (ops_has_table($pdo, 'wallet_activity')) {
                ops_log_wallet_activity($pdo, $memberId, $tcId, 'payment_received', 'admin', $adminId, [
                    'units_recorded'  => $units,
                    'total_paid'      => $nowPaid,
                    'amount_cents'    => $amountCents,
                    'payment_method'  => $method,
                    'payment_ref'     => $ref ?: null,
                    'payment_id'      => $paymentId,
                    'note'            => $note ?: null,
                ]);
            }

            $pdo->commit();

            // ── Accounting: auto-create admin/investment split records ────
            try {
                require_once __DIR__ . '/includes/AccountingHooks.php';
                AccountingHooks::onPaymentConfirmed($pdo, $paymentId, $adminId);
            } catch (Throwable $e) {
                error_log('[admin/payments] accounting hook failed: ' . $e->getMessage());
            }

            $flash = 'Payment recorded: ' . $units . ' unit(s), $' . number_format($amountCents / 100, 2) . ', ' . $method . '.';

        } elseif ($action === 'mark_all_paid') {
            if ($memberId <= 0) {
                throw new RuntimeException('Member ID required.');
            }
            $lines = rows($pdo,
                'SELECT mrl.*, tc.class_code, tc.display_name, tc.unit_price_cents FROM member_reservation_lines mrl
                  JOIN token_classes tc ON tc.id = mrl.token_class_id
                 WHERE mrl.member_id = ?
                   AND tc.class_code IN (\'' . implode("','", $paidTodayCodes) . '\')
                   AND mrl.requested_units > mrl.paid_units',
                [$memberId]
            );
            if (empty($lines)) {
                throw new RuntimeException('No outstanding lines found for this member.');
            }

            $totalDueCents = 0;
            foreach ($lines as $line) {
                $totalDueCents += max(0, ((int)$line['requested_units'] - (int)$line['paid_units'])) * (int)$line['unit_price_cents'];
            }
            if ($totalDueCents <= 0) {
                throw new RuntimeException('No outstanding amount found for this member.');
            }

            $paymentNote = 'Method: ' . $method . ($note !== '' ? "\n" . $note : '');

            $pdo->beginTransaction();
            $pdo->prepare('
                INSERT INTO payments (
                    member_id, payment_type, amount_cents, currency_code, payment_status,
                    external_reference, notes, received_at, created_by_admin_id, created_at, updated_at
                ) VALUES (?, \'manual\', ?, \'AUD\', \'paid\', ?, ?, NOW(), ?, NOW(), NOW())
            ')->execute([$memberId, $amountCents, ($ref !== '' ? $ref : null), $paymentNote, $adminId]);
            $paymentId = (int)$pdo->lastInsertId();

            $remainingAlloc = $amountCents;
            $totalUnits = 0;
            $lastIndex = count($lines) - 1;
            foreach ($lines as $idx => $line) {
                $outstanding = (int)$line['requested_units'] - (int)$line['paid_units'];
                if ($outstanding < 1) continue;

                $lineDueCents = $outstanding * (int)$line['unit_price_cents'];
                if ($idx === $lastIndex) {
                    $allocCents = max(0, $remainingAlloc);
                } else {
                    $allocCents = (int) floor(($amountCents * $lineDueCents) / $totalDueCents);
                    $remainingAlloc -= $allocCents;
                }

                if (ops_has_table($pdo, 'payment_allocations')) {
                    $pdo->prepare('
                        INSERT INTO payment_allocations (
                            payment_id, member_id, token_class_id, units_allocated, amount_cents, created_at
                        ) VALUES (?, ?, ?, ?, ?, NOW())
                    ')->execute([$paymentId, $memberId, (int)$line['token_class_id'], $outstanding, $allocCents]);
                }

                $pdo->prepare('
                    UPDATE member_reservation_lines
                    SET paid_units     = requested_units,
                        payment_status = \'paid\',
                        updated_at     = NOW()
                    WHERE member_id = ? AND token_class_id = ?
                ')->execute([$memberId, (int)$line['token_class_id']]);

                if (in_array($line['class_code'], ['PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT'], true)) {
                    $pdo->prepare('UPDATE members SET signup_payment_status = \'paid\', updated_at = NOW() WHERE id = ?')
                        ->execute([$memberId]);
                }
                if (ops_has_table($pdo, 'wallet_activity')) {
                    ops_log_wallet_activity($pdo, $memberId, (int)$line['token_class_id'], 'payment_received', 'admin', $adminId, [
                        'units_recorded' => $outstanding,
                        'amount_cents'   => $allocCents,
                        'payment_method' => $method,
                        'payment_ref'    => $ref ?: null,
                        'payment_id'     => $paymentId,
                        'note'           => $note ?: null,
                        'bulk'           => true,
                    ]);
                }
                $totalUnits += $outstanding;
            }
            $pdo->commit();

            // ── Accounting: auto-create admin/investment split records ────
            try {
                require_once __DIR__ . '/includes/AccountingHooks.php';
                AccountingHooks::onPaymentConfirmed($pdo, $paymentId, $adminId);
            } catch (Throwable $e) {
                error_log('[admin/payments] accounting hook (bulk) failed: ' . $e->getMessage());
            }

            $flash = 'Payment recorded: all outstanding lines marked paid (' . $totalUnits . ' units, $' . number_format($amountCents / 100, 2) . ', ' . $method . ').';

        } elseif ($action === 'cancel_line') {
            if ($memberId <= 0 || $tcId <= 0) {
                throw new RuntimeException('Member and token class are required.');
            }
            $line = one($pdo,
                'SELECT mrl.requested_units, mrl.paid_units, tc.class_code, tc.display_name
                   FROM member_reservation_lines mrl
                   JOIN token_classes tc ON tc.id = mrl.token_class_id
                  WHERE mrl.member_id = ? AND mrl.token_class_id = ? LIMIT 1',
                [$memberId, $tcId]
            );
            if (!$line) throw new RuntimeException('Line not found.');
            if ((int)$line['paid_units'] > 0) throw new RuntimeException('Cannot cancel — this line has ' . $line['paid_units'] . ' paid unit(s). Mark as refunded instead.');
            $pdo->prepare('DELETE FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ?')
                ->execute([$memberId, $tcId]);
            // Decrement legacy columns
            $legacyMap = ['DONATION_COG'=>'donation_tokens','PAY_IT_FORWARD_COG'=>'pay_it_forward_tokens','KIDS_SNFT'=>'kids_tokens'];
            $col = $legacyMap[(string)$line['class_code']] ?? '';
            if ($col !== '') {
                try {
                    $units = (int)$line['requested_units'];
                    $pdo->prepare("UPDATE snft_memberships SET {$col} = GREATEST(0, {$col} - ?), tokens_total = GREATEST(0, tokens_total - ?), updated_at = NOW() WHERE id = ?")
                        ->execute([$units, $units, $memberId]);
                } catch (Throwable $ignore) {}
            }
            $flash = 'Cancelled: ' . $line['requested_units'] . ' × ' . $line['display_name'] . ' (unpaid line removed).';
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── Data: all unpaid paid-today lines ─────────────────────────────────────────

// ── POST: set Stripe payment URL for a member ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_stripe_url') {
    admin_csrf_verify();
    try {
        $memberId  = (int)($_POST['member_id'] ?? 0);
        $stripeUrl = trim((string)($_POST['stripe_url'] ?? ''));
        if ($memberId <= 0) throw new RuntimeException('Member ID required.');
        if ($stripeUrl !== '' && !str_starts_with($stripeUrl, 'https://')) {
            throw new RuntimeException('Stripe URL must start with https://');
        }
        $row = one($pdo, 'SELECT meta_json FROM members WHERE id = ? LIMIT 1', [$memberId]);
        $meta = $row ? (json_decode((string)($row['meta_json'] ?? '{}'), true) ?: []) : [];
        $meta['stripe_payment_url'] = $stripeUrl;
        $pdo->prepare('UPDATE members SET meta_json = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $memberId]);
        $flash = $stripeUrl !== ''
            ? "Stripe payment link saved. It will appear in the member's vault immediately."
            : 'Stripe payment link cleared.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$payWhere  = '';
$payParams = [];
if ($fSearch !== '') {
    $payWhere .= ' AND (m.full_name LIKE ? OR m.email LIKE ? OR m.member_number LIKE ?)';
    $s = '%' . $fSearch . '%';
    $payParams[] = $s; $payParams[] = $s; $payParams[] = $s;
}
if ($fClass !== '' && in_array($fClass, $paidTodayCodes, true)) {
    $payWhere .= ' AND tc.class_code = ?';
    $payParams[] = $fClass;
}

try { $unpaidRows = rows($pdo, "
    SELECT
        m.id            AS member_id,
        m.full_name,
        m.member_type,
        m.member_number,
        m.abn,
        m.email,
        m.signup_payment_status,
        m.meta_json,
        tc.id           AS token_class_id,
        tc.class_code,
        tc.display_name,
        tc.unit_price_cents,
        mrl.requested_units,
        mrl.paid_units,
        mrl.payment_status,
        (mrl.requested_units - mrl.paid_units) AS outstanding_units
    FROM members m
    INNER JOIN member_reservation_lines mrl ON mrl.member_id = m.id
    INNER JOIN token_classes tc ON tc.id = mrl.token_class_id
    WHERE tc.class_code IN ('PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT','DONATION_COG','PAY_IT_FORWARD_COG')
      AND mrl.requested_units > mrl.paid_units
      $payWhere
    ORDER BY m.id DESC, tc.class_code ASC
    LIMIT 100
", $payParams);
} catch (Throwable $e) { $unpaidRows = []; $error = 'Data query failed: ' . $e->getMessage(); }

// Group by member so we can show all their outstanding lines together
$byMember = [];
foreach ($unpaidRows as $row) {
    $mid = (int)$row['member_id'];
    if (!isset($byMember[$mid])) {
        $memberMeta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
        $byMember[$mid] = [
            'member_id'             => $mid,
            'full_name'             => $row['full_name'],
            'member_type'           => $row['member_type'],
            'member_number'         => $row['member_number'],
            'abn'                   => $row['abn'],
            'email'                 => $row['email'],
            'signup_payment_status' => $row['signup_payment_status'],
            'stripe_url'            => (string)($memberMeta['stripe_payment_url'] ?? ''),
            'lines'                 => [],
            'total_outstanding_cents' => 0,
        ];
    }
    $byMember[$mid]['lines'][] = $row;
    $byMember[$mid]['total_outstanding_cents'] +=
        ((int)$row['outstanding_units']) * ((int)$row['unit_price_cents']);
}
$paymentAcceptance = function_exists('ops_member_acceptance_map') ? ops_member_acceptance_map($pdo, array_keys($byMember)) : [];

// ── Business D/PIF pending payment intents ────────────────────────────────
// Business members place D/PIF orders via vault/payment-intent which creates
// a pending 'adjustment' payment row — no member_reservation_lines entry.
// Show these separately so admin can mark them paid.
$bizPendingPayments = [];
try {
    $bizRows = rows($pdo,
        "SELECT p.id, p.member_id, p.amount_cents, p.external_reference,
                p.notes, p.created_at,
                b.id AS biz_id, b.abn, b.legal_name, b.email,
                b.signup_payment_status
           FROM payments p
           JOIN bnft_memberships b ON b.id = p.member_id
          WHERE p.payment_type  = 'adjustment'
            AND p.payment_status = 'pending'
            AND p.received_at IS NULL
            AND p.notes LIKE 'Member payment intent:%'
          ORDER BY b.id ASC, p.id ASC"
    );
    foreach ($bizRows as $r) {
        $bid = (int)$r['biz_id'];
        if (!isset($bizPendingPayments[$bid])) {
            $bizPendingPayments[$bid] = [
                'biz_id'                => $bid,
                'abn'                   => $r['abn'],
                'legal_name'            => $r['legal_name'],
                'email'                 => $r['email'],
                'signup_payment_status' => $r['signup_payment_status'],
                'payments'              => [],
                'total_cents'           => 0,
            ];
        }
        $bizPendingPayments[$bid]['payments'][] = $r;
        $bizPendingPayments[$bid]['total_cents'] += (int)$r['amount_cents'];
    }
} catch (Throwable $e) { /* table may not exist yet */ }

// Handle mark-paid for a business pending payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_biz_payment_paid') {
    admin_csrf_verify();
    try {
        $payId  = (int)($_POST['payment_id'] ?? 0);
        $method = sanitize_input($_POST['payment_method'] ?? 'EFT');
        $ref    = sanitize_input($_POST['payment_ref']    ?? '');
        if ($payId < 1) throw new RuntimeException('Payment ID required.');
        $pdo->prepare(
            "UPDATE payments SET payment_status='paid', received_at=UTC_TIMESTAMP(),
             notes=CONCAT(COALESCE(notes,''),' [Marked paid by admin — method: ',?,' ref: ',?,']'),
             updated_at=UTC_TIMESTAMP()
             WHERE id=? AND payment_status='pending'"
        )->execute([$method, $ref ?: 'manual', $payId]);
        $flash = 'Business payment #'.$payId.' marked as paid.';
        // Re-query so the page reflects the change
        header('Location: '.$_SERVER['REQUEST_URI']);
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Payments | COG$ Admin</title>
<style>
.member-block{border:1px solid var(--line);border-radius:16px;margin-bottom:14px;overflow:hidden}
.member-hd{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:14px 18px;background:rgba(255,255,255,.03);border-bottom:1px solid var(--line);flex-wrap:wrap}
.member-hd-left strong{display:block;font-size:.98rem}
.member-hd-left .meta{font-size:.78rem;color:var(--muted);margin-top:2px}
.member-hd-right{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap}
.total-due{font-family:Georgia,serif;font-size:1.15rem;font-weight:600;color:var(--gold)}
.lines-table{width:100%;border-collapse:collapse}
.lines-table th,.lines-table td{padding:9px 14px;text-align:left;border-bottom:1px dashed rgba(255,255,255,.07);font-size:.85rem;vertical-align:middle}
.lines-table th{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.lines-table tr:last-child td{border-bottom:none}
.chip{display:inline-block;padding:2px 9px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);font-size:.72rem}
.chip.identity{border-color:rgba(212,178,92,.35);color:var(--gold)}
.chip.donation{border-color:rgba(82,184,122,.35);color:#b8efc8}
.chip.pif{border-color:rgba(86,147,220,.3);color:rgba(160,200,240,.9)}
.btn{display:inline-block;padding:.6rem .95rem;border-radius:10px;font-weight:700;border:1px solid rgba(212,178,92,.35);background:linear-gradient(180deg,#d4b25c,#b98b2f);color:#201507;cursor:pointer;font:inherit;font-size:.78rem;white-space:nowrap}
.btn.secondary{background:rgba(255,255,255,.05);color:var(--text);border-color:var(--line)}
.btn.sm{padding:.45rem .75rem;font-size:.73rem}
.mark-paid-form{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.mark-paid-form input[type=number]{width:60px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:5px 7px;color:var(--text);font:inherit;font-size:.8rem;text-align:right}
.mark-paid-form input[type=text]{width:130px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:5px 9px;color:var(--text);font:inherit;font-size:.8rem}
.mark-paid-form input::placeholder{color:var(--muted);opacity:.7}
.bulk-form{padding:12px 14px;border-top:1px solid var(--line);background:rgba(255,255,255,.02);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bulk-form label{font-size:.78rem;color:var(--muted)}
.msg{padding:12px 14px;border-radius:12px;margin-bottom:14px}
.ok{background:rgba(47,143,87,.12);border:1px solid rgba(47,143,87,.35);color:var(--ok)}
.err{background:rgba(200,61,75,.12);border:1px solid rgba(200,61,75,.35);color:var(--bad)}
.empty{color:var(--muted);font-size:.88rem;padding:16px 0}
.ref-tag{font-family:monospace;font-size:.75rem;color:var(--muted)}
@media(max-width:1100px){.shell{grid-template-columns:minmax(0,1fr)}.main{padding:16px;padding-top:54px}}
</style>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="shell">
<?php admin_sidebar_render('payments'); ?>
<main class="main">

  <div class="topbar">
    <div>
      <span class="eyebrow">Admin · Payments</span>
      <h1>Payments <?= ops_admin_help_button('Payments', 'Use Payments to record money actually received for paid-now items. This page does not approve reservation-only COG$ classes and it does not publish wallet outcomes. After payment is recorded, move to Approvals for sign-off and then Execution for ledger-style processing.') ?></h1>
      <p style="color:var(--muted);margin:4px 0 0;font-size:.88rem">
        This is the authoritative payment-recording surface for paid-now token lines: identity tokens (S-NFT, Kids S-NFT, B-NFT), Donation COG$, and Pay It Forward COG$. Reservation-only classes (ASX, RWA, Landholder) are intentionally excluded because no payment is due at this stage.
      </p>
    </div>
    <a class="btn secondary" href="<?=h(admin_url('dashboard.php'))?>">Dashboard</a>
  </div>

  <?= ops_admin_collapsible_help('Page guide & workflow', [
    ops_admin_info_panel('Intake · Step 1', 'What this page does', 'Record payment that has actually been received for paid-now lines. Use it when money has cleared and you need the Member record to move into the approval lane.', [
      'Use this page for S-NFT, Kids S-NFT, B-NFT, Donation COG$, and Pay It Forward COG$.',
      'Do not use this page to approve reservation-only classes such as ASX, RWA, or Landholder COG$.',
      'After payment is recorded, the next operator page is Approvals.',
    ]),
    ops_admin_workflow_panel('Typical workflow', 'This page is the first live operator step in the intake path.', [
      ['title' => 'Confirm intake evidence', 'body' => 'Check JVPA, KYC, and any supporting intake status before taking payment.'],
      ['title' => 'Record payment', 'body' => 'Choose the payment method, reference, amount, and units actually received.'],
      ['title' => 'Advance to approvals', 'body' => 'Once payment is recorded, use Approvals to sign off the reservation line.'],
      ['title' => 'Send to execution later', 'body' => 'Execution happens only after approvals are complete.'],
    ]),
    ops_admin_status_panel('Field and status guide', 'Use these notes to interpret the most important fields on this page.', [
      ['label' => 'Signup payment', 'body' => 'Shows whether the entry payment required to open the Member record is complete.'],
      ['label' => 'JVPA acceptance', 'body' => 'Shows whether the backend acceptance trail is complete enough to support taking payment.'],
      ['label' => 'Outstanding', 'body' => 'The remaining units still waiting for payment on this line.'],
      ['label' => 'Mark paid', 'body' => 'Use only after funds are actually received. This records payment; it does not approve or publish anything.'],
    ]),
  ]) ?>

  <?php if ($flash): ?><div class="msg ok"><?=h($flash)?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

  <form method="get" style="margin-bottom:0">
  <div class="filter-bar">
    <div class="filter-group">
      <label>Name / email / member no.</label>
      <input type="text" name="search" value="<?=h($fSearch)?>" placeholder="Search…" style="min-width:200px">
    </div>
    <div class="filter-group">
      <label>Token class</label>
      <select name="class">
        <option value="">All classes</option>
        <?php foreach ($paidTodayCodes as $cc): ?>
          <option value="<?=h($cc)?>"<?=$fClass===$cc?' selected':''?>><?=h($cc)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:6px;align-items:flex-end">
      <button type="submit" class="btn btn-sm" style="background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)">Filter</button>
      <a href="payments.php" class="btn btn-sm">Reset</a>
    </div>
  </div>
  </form>

  <?php if (empty($byMember)): ?>
    <?php if ($fSearch !== '' || $fClass !== ''): ?>
      <div class="card"><div class="card-body"><p class="empty">No outstanding payments match the current filter.</p></div></div>
    <?php else: ?>
      <div class="card"><div class="card-body"><p class="empty">✓ No outstanding payments. All paid-today token lines are up to date.</p></div></div>
    <?php endif; ?>
  <?php else: ?>
    <p style="font-size:.82rem;color:var(--muted);margin:0 0 14px">
      <?= count($byMember) ?> member<?= count($byMember) !== 1 ? 's' : '' ?> with outstanding paid-today lines.
      Enter the payment method, bank reference, amount paid, and units received, then click <strong>Mark paid</strong>.
    </p>

    <?php foreach ($byMember as $mem):
      $ref = $mem['member_type'] === 'business' ? $mem['abn'] : $mem['member_number'];
      $totalDollars = $mem['total_outstanding_cents'] / 100;
      $acceptance = $paymentAcceptance[(int)($mem['member_id'] ?? 0)] ?? null;
      $acceptanceLabel = function_exists('ops_acceptance_status_label') ? ops_acceptance_status_label($acceptance) : '—';
      $acceptanceTone = function_exists('ops_acceptance_status_tone') ? ops_acceptance_status_tone($acceptance) : 'warn';
    ?>
    <div class="member-block">

      <div class="member-hd">
        <div class="member-hd-left">
          <strong><?=h($mem['full_name'])?></strong>
          <div class="meta">
            <?=h($ref)?>
            <?php if ($mem['email']): ?> · <?=h($mem['email'])?><?php endif; ?>
          </div>
          <div class="meta" style="margin-top:3px">
            Signup payment: <strong style="color:<?=$mem['signup_payment_status']==='paid'?'var(--ok)':'var(--bad)'?>"><?=h($mem['signup_payment_status'] ?: 'unpaid')?></strong>
          </div>
          <div class="meta" style="margin-top:3px">
            JVPA acceptance <?= ops_admin_help_button('JVPA acceptance', 'This indicator shows whether the membership acceptance trail is complete in the backend. A missing or legacy JVPA record is a warning that intake evidence still needs attention before the Member is treated as fully compliant.') ?>: <strong style="color:<?= $acceptanceTone==='ok' ? 'var(--ok)' : ($acceptanceTone==='warn' ? 'var(--warn)' : 'var(--bad)') ?>"><?=h($acceptanceLabel)?></strong>
            <?php if(!empty($acceptance['accepted_version'])): ?> · <?=h($acceptance['accepted_version'])?><?php endif; ?>
          </div>
        </div>
        <div class="member-hd-right">
          <span>Total outstanding:</span>
          <span class="total-due">$<?= number_format($totalDollars, 2) ?></span>
        </div>
      </div>
      <?php if ($acceptanceTone !== 'ok'): ?>
      <div style="padding:10px 14px;background:<?= $acceptanceTone==='bad' ? 'rgba(196,96,96,.10)' : 'rgba(200,144,26,.10)' ?>;border-top:1px solid rgba(255,255,255,.06);font-size:.8rem;color:var(--text)">
        <?= $acceptanceTone==='bad' ? 'Payment should not be taken until the JVPA acceptance record is completed.' : 'This Member entry still needs the JVPA acceptance trail finished in the backend.' ?>
      </div>
      <?php endif; ?>

      <table class="lines-table">
        <thead>
          <tr>
            <th>Token type</th>
            <th>Requested</th>
            <th>Paid</th>
            <th>Outstanding</th>
            <th>Unit price</th>
            <th>Amount due</th>
            <th>Mark paid <?= ops_admin_help_button('Mark paid', 'This action records payment that has been received for the outstanding units on this line. It does not approve the line and it does not push anything into execution.') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($mem['lines'] as $line):
          $code = $line['class_code'];
          $chipClass = in_array($code, ['PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT'], true) ? 'identity'
                     : ($code === 'DONATION_COG' ? 'donation' : 'pif');
          $adminCode = trust_code_only($line);
          $priceDollars = ((int)$line['unit_price_cents']) / 100;
          $outstanding  = (int)$line['outstanding_units'];
          $amountDue    = $outstanding * $priceDollars;
        ?>
          <tr>
            <td>
              <span class="chip <?=h($chipClass)?>"><?=h($adminCode)?></span>
              <div style="font-size:.72rem;color:var(--muted);margin-top:3px"><?=h($line['display_name'])?></div>
            </td>
            <td><?= (int)$line['requested_units'] ?></td>
            <td><?= (int)$line['paid_units'] ?></td>
            <td style="font-weight:700;color:var(--bad)"><?= $outstanding ?></td>
            <td>$<?= number_format($priceDollars, 2) ?></td>
            <td style="font-weight:600;color:var(--gold)">$<?= number_format($amountDue, 2) ?></td>
            <td>
              <form method="post" class="mark-paid-form">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="action"         value="mark_paid">
                <input type="hidden" name="member_id"      value="<?= (int)$mem['member_id'] ?>">
                <input type="hidden" name="token_class_id" value="<?= (int)$line['token_class_id'] ?>">
                <select name="payment_method" title="Payment method" required style="background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:5px 9px;color:var(--text);font:inherit;font-size:.8rem">
                  <option value="STRIPE">Stripe</option>
                  <option value="EFT">EFT</option>
                  <option value="CASH">Cash</option>
                  <option value="CRYPTO">Crypto</option>
                  <option value="PAYPAL">PayPal</option>
                </select>
                <input type="text" name="payment_ref" value="" placeholder="Bank ref" title="Bank or payment reference">
                <input type="number" name="amount_paid" value="<?= number_format($amountDue, 2, '.', '') ?>" min="0.01" step="0.01" title="Amount paid (AUD)" style="width:96px">
                <input type="number" name="units" value="<?= $outstanding ?>" min="1" max="<?= $outstanding ?>" title="Units received">
                <button type="submit" class="btn sm">Mark paid</button>
              </form>
              <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('Cancel this unpaid line?')">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="cancel_line">
                <input type="hidden" name="member_id" value="<?= (int)$mem['member_id'] ?>">
                <input type="hidden" name="token_class_id" value="<?= (int)$line['token_class_id'] ?>">
                <button type="submit" class="btn sm" style="background:rgba(196,96,96,.15);border-color:rgba(196,96,96,.3);color:#e88">Cancel</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Bulk: mark all lines for this member paid in one action -->
      <div class="bulk-form">
        <form method="post" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"
              onsubmit="return confirm('Mark ALL outstanding lines paid for <?=h(addslashes($mem['full_name']))?>?')">
          <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
          <input type="hidden" name="action"    value="mark_all_paid">
          <input type="hidden" name="member_id" value="<?= (int)$mem['member_id'] ?>">
          <select name="payment_method" title="Payment method" required style="background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:7px 9px;color:var(--text);font:inherit;font-size:.8rem">
            <option value="STRIPE">Stripe</option>
            <option value="EFT">EFT</option>
            <option value="CASH">Cash</option>
            <option value="CRYPTO">Crypto</option>
            <option value="PAYPAL">PayPal</option>
          </select>
          <input type="text" name="payment_ref" value="" placeholder="Bank ref" title="Bank or payment reference" style="width:140px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:7px 9px;color:var(--text);font:inherit;font-size:.8rem">
          <input type="number" name="amount_paid" value="<?= number_format($totalDollars, 2, '.', '') ?>" min="0.01" step="0.01" title="Amount paid (AUD)" style="width:120px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:7px 9px;color:var(--text);font:inherit;font-size:.8rem;text-align:right">
          <button type="submit" class="btn" style="background:linear-gradient(180deg,#4a9e6a,#357a50);border-color:rgba(80,200,120,.3);color:#fff">
            Mark all paid — $<?= number_format($totalDollars, 2) ?>
          </button>
        </form>
      </div>


      <!-- Stripe payment URL management -->
      <form method="post" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 14px;background:rgba(80,120,200,.05);border-top:1px dashed rgba(86,147,220,.2)">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="set_stripe_url">
        <input type="hidden" name="member_id" value="<?= (int)$mem['member_id'] ?>">
        <span style="font-size:.75rem;font-weight:600;color:rgba(160,200,240,.8);white-space:nowrap;letter-spacing:.04em;text-transform:uppercase">Stripe link</span>
        <input type="url" name="stripe_url"
          value="<?= ops_h($mem['stripe_url'] ?? '') ?>"
          placeholder="https://buy.stripe.com/…"
          style="flex:1;min-width:280px;background:rgba(255,255,255,.06);border:1px solid rgba(86,147,220,.25);border-radius:8px;padding:6px 10px;color:var(--text);font:inherit;font-size:.82rem"
        >
        <button type="submit" class="btn sm" style="background:rgba(86,147,220,.15);border-color:rgba(86,147,220,.35);color:rgba(160,200,240,.9)">Save link</button>
        <?php if (!empty($mem['stripe_url'])): ?>
          <a href="<?= ops_h($mem['stripe_url']) ?>" target="_blank" rel="noopener"
             style="font-size:.76rem;color:rgba(160,200,240,.7);text-decoration:underline">Open →</a>
        <?php endif; ?>
      </form>

    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($bizPendingPayments)): ?>
  <div style="margin-top:28px">
    <div class="card-head"><h2>Business — Pending Community Contributions <?= ops_admin_help_button('Business pending community contributions', 'These are business-side Donation COG$ and Pay It Forward payment intents still awaiting payment confirmation. Recording them here updates the money-received side only; any further sign-off happens elsewhere.') ?></h2></div>
  <div class="card-body">

    <p style="font-size:.82rem;color:var(--muted);margin:0 0 14px">
      Donation COG$ and Pay It Forward COG$ orders placed by business members awaiting payment.
    </p>
    <?php foreach ($bizPendingPayments as $biz): ?>
    <div class="member-block">
      <div class="member-hd">
        <div class="member-hd-left">
          <strong><?=h($biz['legal_name'])?></strong>
          <div class="meta">ABN <?=h($biz['abn'])?><?php if ($biz['email']): ?> · <?=h($biz['email'])?><?php endif; ?></div>
          <div class="meta" style="margin-top:3px">
            Signup: <strong style="color:<?=$biz['signup_payment_status']==='paid'?'var(--ok)':'var(--bad)'?>"><?=h($biz['signup_payment_status']?:'unpaid')?></strong>
          </div>
        </div>
        <div class="member-hd-right">
          <span>Total outstanding:</span>
          <span class="total-due">$<?= number_format($biz['total_cents'] / 100, 2) ?></span>
        </div>
      </div>
      <table class="lines-table">
        <thead>
          <tr><th>Reference</th><th>Description</th><th>Amount</th><th>Date</th><th>Mark paid <?= ops_admin_help_button('Mark paid', 'This action records payment that has been received for the outstanding units on this line. It does not approve the line and it does not push anything into execution.') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($biz['payments'] as $pay):
          $notes = (string)($pay['notes'] ?? '');
          $desc  = preg_match('/Member payment intent:\s*(.+?)\.\s*Reference:/i', $notes, $nm) ? $nm[1] : $notes;
        ?>
          <tr>
            <td class="ref-tag"><?=h($pay['external_reference'] ?? '—')?></td>
            <td><?=h($desc)?></td>
            <td style="font-weight:600;color:var(--gold)">$<?= number_format((int)$pay['amount_cents'] / 100, 2) ?></td>
            <td style="font-size:.78rem;color:var(--muted)"><?=h(substr((string)($pay['created_at']??''),0,10))?></td>
            <td>
              <form method="post" class="mark-paid-form">
                <input type="hidden" name="_csrf"         value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="action"        value="mark_biz_payment_paid">
                <input type="hidden" name="payment_id"   value="<?= (int)$pay['id'] ?>">
                <select name="payment_method" title="Payment method" style="background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:5px 9px;color:var(--text);font:inherit;font-size:.8rem">
                  <option value="EFT">EFT</option>
                  <option value="STRIPE">Stripe</option>
                  <option value="CASH">Cash</option>
                  <option value="CRYPTO">Crypto</option>
                </select>
                <input type="text"  name="payment_ref" placeholder="Bank ref" style="width:120px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:8px;padding:5px 9px;color:var(--text);font:inherit;font-size:.8rem">
                <button type="submit" class="btn sm">Mark paid</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</main>
</div>
</body>
</html>

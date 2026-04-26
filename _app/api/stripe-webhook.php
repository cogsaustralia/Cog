<?php
/**
 * Stripe Webhook Handler
 * /_app/api/stripe-webhook.php
 *
 * Standalone — does NOT go through index.php router.
 * Must read raw body before any other input processing.
 *
 * Stripe Dashboard → Developers → Webhooks → Add endpoint:
 *   URL: https://cogsaustralia.org/_app/api/stripe-webhook.php
 *   Events: checkout.session.completed
 *
 * Copy the signing secret to .env as STRIPE_WEBHOOK_SECRET=whsec_...
 */
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);

// ── Read raw body FIRST before any parsing ───────────────────────────────────
$rawBody = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

// ── Bootstrap (DB + helpers) ─────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/mailer.php';
require_once __DIR__ . '/services/JvpaAcceptanceService.php';

// ── Webhook secret from env ──────────────────────────────────────────────────
$webhookSecret = (string)env('STRIPE_WEBHOOK_SECRET', '');
if ($webhookSecret === '') {
    http_response_code(500);
    error_log('[stripe-webhook] STRIPE_WEBHOOK_SECRET not configured');
    echo json_encode(['error' => 'Webhook secret not configured']);
    exit;
}

// ── Verify Stripe signature (manual HMAC — no SDK required) ─────────────────
// Stripe-Signature header format: t=timestamp,v1=sig1[,v1=sig2...]
function stripe_verify_signature(string $payload, string $sigHeader, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2) + ['', ''];
        $parts[$k][] = $v;
    }
    $timestamp = (int)($parts['t'][0] ?? 0);
    $signatures = $parts['v1'] ?? [];
    if ($timestamp === 0 || empty($signatures)) return false;
    // Reject events older than 5 minutes
    if (abs(time() - $timestamp) > 300) return false;
    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!stripe_verify_signature($rawBody, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    error_log('[stripe-webhook] Signature verification failed');
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// ── Parse event ──────────────────────────────────────────────────────────────
$event = json_decode($rawBody, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$type   = (string)($event['type'] ?? '');
$object = $event['data']['object'] ?? [];

// ── Respond 200 immediately — Stripe retries on timeout ─────────────────────
// We process synchronously here since the work is fast (two DB writes).
// For heavy processing, echo 200 and flush() before the DB work.
http_response_code(200);
header('Content-Type: application/json');

if ($type !== 'checkout.session.completed') {
    echo json_encode(['ok' => true, 'handled' => false, 'type' => $type]);
    exit;
}

// ── Extract identifiers ───────────────────────────────────────────────────────
$clientRef    = trim((string)($object['client_reference_id'] ?? ''));  // member_number
$customerEmail = strtolower(trim((string)($object['customer_details']['email'] ?? $object['customer_email'] ?? '')));
$amountTotal   = (int)($object['amount_total'] ?? 0);   // in cents
$currency      = strtoupper((string)($object['currency'] ?? 'AUD'));
$sessionId     = (string)($object['id'] ?? '');
$paymentIntent = (string)($object['payment_intent'] ?? '');

if ($clientRef === '' && $customerEmail === '') {
    error_log('[stripe-webhook] No client_reference_id or email in session: ' . $sessionId);
    echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'no_identifier']);
    exit;
}

try {
    $db = getDB();

    // ── Idempotency: refuse to re-process a Stripe event we've already handled.
    // Stripe retries on connection drop, timeout, or any non-2xx response.
    // Without this guard the gift_pool path would double-INSERT to `payments`
    // and `payment_allocations`, double-fire AccountingHooks, and queue
    // duplicate emails. INSERT IGNORE on a UNIQUE PRIMARY KEY gives atomic
    // at-most-once semantics: rowCount=1 means we won; rowCount=0 means
    // another delivery already inserted it (duplicate or concurrent retry).
    //
    // Wrapped in try/catch so a missing migration table degrades gracefully
    // back to the pre-fix behaviour (signup paths still have their own
    // signup_payment_status idempotency; only gift_pool is exposed).
    $eventId = (string)($event['id'] ?? '');
    if ($eventId !== '') {
        try {
            $idStmt = $db->prepare(
                'INSERT IGNORE INTO stripe_processed_events (event_id, event_type, received_at)
                 VALUES (?, ?, UTC_TIMESTAMP())'
            );
            $idStmt->execute([$eventId, $type]);
            if ($idStmt->rowCount() === 0) {
                error_log('[stripe-webhook] Duplicate event ignored: ' . $eventId . ' type=' . $type);
                echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'duplicate_event', 'event_id' => $eventId]);
                exit;
            }
        } catch (Throwable $idemErr) {
            // Table may not exist yet (pre-migration). Log and proceed.
            error_log('[stripe-webhook] stripe_processed_events check skipped: ' . $idemErr->getMessage());
        }
    }

    // ── Find the member ───────────────────────────────────────────────────────
    $member = null;

    // 1. Try member_number (client_reference_id) first — most reliable
    if ($clientRef !== '') {
        $stmt = $db->prepare('SELECT id, member_number, full_name, email, signup_payment_status FROM members WHERE member_number = ? AND member_type = ? LIMIT 1');
        $stmt->execute([$clientRef, 'personal']);
        $member = $stmt->fetch() ?: null;
    }
    // 2. Fallback to email lookup
    if (!$member && $customerEmail !== '') {
        $stmt = $db->prepare('SELECT id, member_number, full_name, email, signup_payment_status FROM members WHERE LOWER(email) = ? AND member_type = ? LIMIT 1');
        $stmt->execute([$customerEmail, 'personal']);
        $member = $stmt->fetch() ?: null;
    }

    if (!$member) {
        error_log('[stripe-webhook] Member not found — ref: ' . $clientRef . ' email: ' . $customerEmail);
        echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'member_not_found']);
        exit;
    }

    $memberId     = (int)$member['id'];
    $memberNumber = (string)$member['member_number'];
    $now          = date('Y-m-d H:i:s');

    // ── Determine purchase type from metadata ────────────────────────────────
    $metadata     = $object['metadata'] ?? [];
    $purchaseType = (string)($metadata['purchase_type'] ?? 'signup');
    $metaItems    = (string)($metadata['items'] ?? '');  // e.g. "donation_tokens:2,pay_it_forward_tokens:3"

    // ── GIFT POOL PURCHASE ───────────────────────────────────────────────────
    if ($purchaseType === 'gift_pool' && $metaItems !== '') {
        $db->beginTransaction();

        $classCodeMap = [
            'donation_tokens'       => 'DONATION_COG',
            'pay_it_forward_tokens' => 'PAY_IT_FORWARD_COG',
            'kids_tokens'           => 'KIDS_SNFT',
        ];

        // Parse items: "donation_tokens:2,pay_it_forward_tokens:3"
        foreach (explode(',', $metaItems) as $entry) {
            [$cls, $qty] = explode(':', $entry, 2) + ['', '0'];
            $qty = max(0, (int)$qty);
            $code = $classCodeMap[$cls] ?? '';
            if ($code === '' || $qty < 1) continue;

            // Mark the reservation line as paid for these units
            try {
                $db->prepare("
                    UPDATE member_reservation_lines mrl
                    INNER JOIN token_classes tc ON tc.id = mrl.token_class_id
                    SET mrl.paid_units = LEAST(mrl.paid_units + ?, mrl.requested_units),
                        mrl.payment_status = CASE
                            WHEN LEAST(mrl.paid_units + ?, mrl.requested_units) >= mrl.requested_units THEN 'paid'
                            ELSE 'pending'
                        END,
                        mrl.updated_at = ?
                    WHERE mrl.member_id = ?
                      AND tc.class_code = ?
                ")->execute([$qty, $qty, $now, $memberId, $code]);
            } catch (Throwable $e) {
                error_log('[stripe-webhook] gift_pool line update failed for ' . $code . ': ' . $e->getMessage());
            }
        }

        // Record payment
        $giftPayId = 0;
        try {
            $db->prepare("
                INSERT INTO payments
                  (member_id, payment_type, amount_cents, currency_code, payment_status,
                   external_reference, notes, created_at, updated_at)
                VALUES (?, 'gift_pool', ?, ?, 'paid', ?, ?, ?, ?)
            ")->execute([
                $memberId, $amountTotal, $currency,
                $sessionId . ($paymentIntent ? '|' . $paymentIntent : ''),
                'Stripe Checkout — gift pool purchase. Items: ' . $metaItems . '. ref: ' . $clientRef,
                $now, $now,
            ]);
            $giftPayId = (int)$db->lastInsertId();

            // ── Record payment_allocations per token class for accounting hooks
            if ($giftPayId > 0) {
                foreach (explode(',', $metaItems) as $gEntry) {
                    [$gCls, $gQty] = explode(':', $gEntry, 2) + ['', '0'];
                    $gQty = max(0, (int)$gQty);
                    $gCode = $classCodeMap[$gCls] ?? '';
                    if ($gCode === '' || $gQty < 1) continue;
                    $gTcRow = $db->prepare("SELECT id, unit_price_cents FROM token_classes WHERE class_code = ? LIMIT 1");
                    $gTcRow->execute([$gCode]);
                    $gTc = $gTcRow->fetch();
                    if ($gTc) {
                        $gAllocCents = $gQty * (int)$gTc['unit_price_cents'];
                        $db->prepare("
                            INSERT INTO payment_allocations
                              (payment_id, member_id, token_class_id, units_allocated, amount_cents, created_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ")->execute([$giftPayId, $memberId, (int)$gTc['id'], $gQty, $gAllocCents, $now]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[stripe-webhook] gift_pool payment record failed: ' . $e->getMessage());
        }

        $db->commit();

        // ── Accounting: auto-create admin/investment split records ────────────
        if ($giftPayId > 0) {
            try {
                require_once dirname(__DIR__, 2) . '/admin/includes/AccountingHooks.php';
                AccountingHooks::onPaymentConfirmed($db, $giftPayId, null);
            } catch (Throwable $e) {
                error_log('[stripe-webhook] accounting hook (gift_pool) failed: ' . $e->getMessage());
            }
        }

        // Email notifications for gift pool payment
        try {
            recordWalletEvent($db, 'snft_member', $memberNumber, 'payment_received',
                'Stripe payment received: $' . number_format($amountTotal / 100, 2) . ' for ' . $metaItems);
            // Member confirmation
            queueEmail($db, 'snft_member', $memberId, (string)$member['email'], 'payment_intent_member',
                'COG$ Payment Confirmed', [
                    'full_name' => (string)$member['full_name'],
                    'member_number' => $memberNumber,
                    'token_class' => $metaItems,
                    'units' => 0,
                    'amount' => number_format($amountTotal / 100, 2),
                    'reference' => $sessionId,
                ]);
            // Admin notification
            queueEmail($db, 'snft_member', $memberId, MAIL_ADMIN_EMAIL, 'payment_intent_admin',
                'Payment Received — ' . $memberNumber, [
                    'full_name' => (string)$member['full_name'],
                    'member_number' => $memberNumber,
                    'token_class' => $metaItems,
                    'units' => 0,
                    'amount' => number_format($amountTotal / 100, 2),
                    'reference' => $sessionId,
                ]);
            processEmailQueue($db, 2);
        } catch (Throwable $emailErr) {
            error_log('[stripe-webhook] email notification failed: ' . $emailErr->getMessage());
        }

        error_log('[stripe-webhook] Gift pool payment confirmed — member: ' . $memberNumber . ' items: ' . $metaItems . ' amount: ' . $amountTotal . ' cents');
        echo json_encode(['ok' => true, 'handled' => true, 'type' => 'gift_pool', 'member_number' => $memberNumber]);
        exit;
    }

    // ── BNFT BUSINESS SIGNUP ──────────────────────────────────────────────
    if ($purchaseType === 'bnft_signup') {
        $abn = trim((string)($metadata['abn'] ?? $clientRef));
        if ($abn === '') {
            error_log('[stripe-webhook] BNFT signup — no ABN in metadata or client_reference_id');
            echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'no_abn']);
            exit;
        }

        $biz = null;
        $stmt = $db->prepare('SELECT id, abn, legal_name, email, signup_payment_status FROM bnft_memberships WHERE abn = ? LIMIT 1');
        $stmt->execute([$abn]);
        $biz = $stmt->fetch() ?: null;

        if (!$biz) {
            error_log('[stripe-webhook] BNFT signup — business not found for ABN: ' . $abn);
            echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'business_not_found']);
            exit;
        }

        if (($biz['signup_payment_status'] ?? '') === 'paid') {
            error_log('[stripe-webhook] BNFT already paid — ABN: ' . $abn);
            echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'already_paid']);
            exit;
        }

        $bizId = (int)$biz['id'];
        $now = date('Y-m-d H:i:s');

        $db->beginTransaction();

        // Mark business paid
        $db->prepare("UPDATE bnft_memberships SET signup_payment_status='paid', wallet_status='active', updated_at=? WHERE id=?")
            ->execute([$now, $bizId]);

        // Record payment
        $bnftPayId = 0;
        try {
            $db->prepare("
                INSERT INTO payments
                  (member_id, payment_type, amount_cents, currency_code, payment_status,
                   external_reference, notes, created_at, updated_at)
                VALUES (?, 'bnft_signup', ?, ?, 'paid', ?, ?, ?, ?)
            ")->execute([
                0, $amountTotal, $currency,
                $sessionId . ($paymentIntent ? '|' . $paymentIntent : ''),
                'BNFT business signup — ABN: ' . $abn . ' — ' . ($biz['legal_name'] ?? ''),
                $now, $now,
            ]);
            $bnftPayId = (int)$db->lastInsertId();

            if ($bnftPayId > 0) {
                $bnftTcId = $db->query("SELECT id FROM token_classes WHERE class_code = 'BUSINESS_BNFT' LIMIT 1")->fetchColumn();
                if ($bnftTcId) {
                    $db->prepare("
                        INSERT INTO payment_allocations
                          (payment_id, member_id, token_class_id, units_allocated, amount_cents, created_at)
                        VALUES (?, 0, ?, 1, ?, ?)
                    ")->execute([$bnftPayId, (int)$bnftTcId, $amountTotal, $now]);
                }
            }
        } catch (Throwable $e) {
            error_log('[stripe-webhook] BNFT payment record failed: ' . $e->getMessage());
        }

        $db->commit();

        // Accounting hook
        if ($bnftPayId > 0) {
            try {
                require_once dirname(__DIR__, 2) . '/admin/includes/AccountingHooks.php';
                AccountingHooks::onPaymentConfirmed($db, $bnftPayId, null);
            } catch (Throwable $e) {
                error_log('[stripe-webhook] accounting hook (bnft) failed: ' . $e->getMessage());
            }
        }

        // Wallet event + emails
        try {
            recordWalletEvent($db, 'bnft_business', $abn, 'payment_received',
                'Stripe BNFT signup payment received: $' . number_format($amountTotal / 100, 2));
            $bankPayload = [
                'full_name'     => (string)($biz['legal_name'] ?? ''),
                'member_number' => $abn,
                'token_class'   => 'Business B-NFT membership',
                'units'         => 1,
                'amount'        => number_format($amountTotal / 100, 2),
                'reference'     => $sessionId,
                'pay_id'        => '0494 578 706',
                'bank_name'     => 'The Trustee for COGS of Australia Foundation Hybrid Trust',
                'bank_bsb'      => '182-182',
                'bank_account'  => '035 249 275',
            ];
            if (!empty($biz['email'])) {
                queueEmail($db, 'bnft_business', $bizId, (string)$biz['email'], 'payment_intent_member',
                    'COG$ Business Membership — Payment Confirmed', $bankPayload);
            }
            $adminEmail = defined('MAIL_ADMIN_EMAIL') ? MAIL_ADMIN_EMAIL : '';
            if ($adminEmail !== '' && $adminEmail !== ($biz['email'] ?? '')) {
                queueEmail($db, 'bnft_business', $bizId, $adminEmail, 'payment_intent_admin',
                    'BNFT Payment Received — ABN ' . $abn, $bankPayload);
            }
            processEmailQueue($db, 4);
        } catch (Throwable $e) {
            error_log('[stripe-webhook] BNFT email failed: ' . $e->getMessage());
        }

        error_log('[stripe-webhook] BNFT payment confirmed — ABN: ' . $abn . ' amount: ' . $amountTotal . ' cents');
        echo json_encode(['ok' => true, 'handled' => true, 'type' => 'bnft_signup', 'abn' => $abn]);
        exit;
    }

    // ── BNFT GIFT POOL (D, pS for businesses) ───────────────────────────────
    if ($purchaseType === 'bnft_gift_pool' && $metaItems !== '') {
        $abn = trim((string)($metadata['member_number'] ?? $clientRef));
        $biz = null;
        if ($abn !== '') {
            $stmt = $db->prepare('SELECT id, abn, legal_name, email FROM bnft_memberships WHERE abn = ? LIMIT 1');
            $stmt->execute([$abn]);
            $biz = $stmt->fetch() ?: null;
        }
        if (!$biz) {
            error_log('[stripe-webhook] BNFT gift pool — business not found for ABN: ' . $abn);
            echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'business_not_found']);
            exit;
        }
        $bizId = (int)$biz['id'];
        $now = date('Y-m-d H:i:s');

        $classColumnMap = [
            'donation_tokens'       => 'donation_tokens',
            'pay_it_forward_tokens' => 'pay_it_forward_tokens',
        ];

        $db->beginTransaction();

        // Update bnft_memberships token columns directly
        foreach (explode(',', $metaItems) as $entry) {
            [$cls, $qty] = explode(':', $entry, 2) + ['', '0'];
            $qty = max(0, (int)$qty);
            $col = $classColumnMap[$cls] ?? '';
            if ($col === '' || $qty < 1) continue;
            try {
                $db->prepare("UPDATE bnft_memberships SET {$col} = {$col} + ?, updated_at = ? WHERE id = ?")->execute([$qty, $now, $bizId]);
            } catch (Throwable $e) {
                error_log('[stripe-webhook] bnft_gift_pool column update failed for ' . $col . ': ' . $e->getMessage());
            }
        }

        // Recalculate reservation value
        try {
            $bRow = $db->prepare('SELECT invest_tokens, donation_tokens, pay_it_forward_tokens FROM bnft_memberships WHERE id = ? LIMIT 1');
            $bRow->execute([$bizId]);
            $bData = $bRow->fetch();
            if ($bData) {
                $newVal = 40 + ((int)$bData['invest_tokens'] * 40) + ((int)$bData['donation_tokens'] * 4) + ((int)$bData['pay_it_forward_tokens'] * 4);
                $db->prepare('UPDATE bnft_memberships SET reservation_value = ?, updated_at = ? WHERE id = ?')->execute([$newVal, $now, $bizId]);
            }
        } catch (Throwable $e) {}

        // Record payment
        $gpPayId = 0;
        try {
            $db->prepare("INSERT INTO payments (member_id, payment_type, amount_cents, currency_code, payment_status, external_reference, notes, created_at, updated_at) VALUES (0, 'bnft_gift_pool', ?, ?, 'paid', ?, ?, ?, ?)")
                ->execute([$amountTotal, $currency, $sessionId . ($paymentIntent ? '|' . $paymentIntent : ''), 'BNFT gift pool — ABN: ' . $abn . ' — items: ' . $metaItems, $now, $now]);
            $gpPayId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[stripe-webhook] bnft_gift_pool payment record failed: ' . $e->getMessage());
        }

        $db->commit();

        // Accounting hook
        if ($gpPayId > 0) {
            try {
                require_once dirname(__DIR__, 2) . '/admin/includes/AccountingHooks.php';
                AccountingHooks::onPaymentConfirmed($db, $gpPayId, null);
            } catch (Throwable $e) {
                error_log('[stripe-webhook] accounting hook (bnft_gift_pool) failed: ' . $e->getMessage());
            }
        }

        // Emails
        try {
            recordWalletEvent($db, 'bnft_business', $abn, 'payment_received',
                'Stripe BNFT gift pool payment: $' . number_format($amountTotal / 100, 2) . ' for ' . $metaItems);
            $bankPayload = [
                'full_name'     => (string)($biz['legal_name'] ?? ''),
                'member_number' => $abn,
                'token_class'   => $metaItems,
                'units'         => 0,
                'amount'        => number_format($amountTotal / 100, 2),
                'reference'     => $sessionId,
                'pay_id'        => '0494 578 706',
                'bank_name'     => 'The Trustee for COGS of Australia Foundation Hybrid Trust',
                'bank_bsb'      => '182-182',
                'bank_account'  => '035 249 275',
            ];
            if (!empty($biz['email'])) {
                queueEmail($db, 'bnft_business', $bizId, (string)$biz['email'], 'payment_intent_member',
                    'COG$ Business Payment Confirmed', $bankPayload);
            }
            $adminEmail = defined('MAIL_ADMIN_EMAIL') ? MAIL_ADMIN_EMAIL : '';
            if ($adminEmail !== '') {
                queueEmail($db, 'bnft_business', $bizId, $adminEmail, 'payment_intent_admin',
                    'BNFT Gift Pool Payment — ABN ' . $abn, $bankPayload);
            }
            processEmailQueue($db, 4);
        } catch (Throwable $e) {
            error_log('[stripe-webhook] bnft_gift_pool email failed: ' . $e->getMessage());
        }

        error_log('[stripe-webhook] BNFT gift pool confirmed — ABN: ' . $abn . ' items: ' . $metaItems . ' amount: ' . $amountTotal);
        echo json_encode(['ok' => true, 'handled' => true, 'type' => 'bnft_gift_pool', 'abn' => $abn]);
        exit;
    }

    // ── SIGNUP FEE (original flow) ───────────────────────────────────────────
    // Skip if already paid
    if ($member['signup_payment_status'] === 'paid') {
        error_log('[stripe-webhook] Already paid — member: ' . $memberNumber . ' session: ' . $sessionId);
        echo json_encode(['ok' => true, 'handled' => false, 'reason' => 'already_paid']);
        exit;
    }

    $db->beginTransaction();

    // ── Mark member paid ──────────────────────────────────────────────────────
    $db->prepare('UPDATE members SET signup_payment_status = ?, updated_at = ? WHERE id = ?')
       ->execute(['paid', $now, $memberId]);

    // ── Also update snft_memberships if that table exists ────────────────────
    try {
        $db->prepare('UPDATE snft_memberships SET signup_payment_status = ?, wallet_status = ?, updated_at = ? WHERE member_number = ?')
           ->execute(['paid', 'active', $now, $memberNumber]);
    } catch (Throwable $e) {
        // Table may not exist — non-fatal
    }

    // ── Mark PERSONAL_SNFT reservation line as paid ───────────────────────────
    try {
        $stmt = $db->prepare("
            UPDATE member_reservation_lines mrl
            INNER JOIN token_classes tc ON tc.id = mrl.token_class_id
            SET mrl.paid_units = mrl.requested_units,
                mrl.payment_status = 'paid',
                mrl.updated_at = ?
            WHERE mrl.member_id = ?
              AND tc.class_code = 'PERSONAL_SNFT'
        ");
        $stmt->execute([$now, $memberId]);
    } catch (Throwable $e) {
        error_log('[stripe-webhook] reservation line update failed: ' . $e->getMessage());
    }

    // ── Record payment in payments table if it exists ─────────────────────────
    $signupPayId = 0;
    try {
        $db->prepare("
            INSERT INTO payments
              (member_id, payment_type, amount_cents, currency_code, payment_status,
               external_reference, notes, created_at, updated_at)
            VALUES (?, 'signup_fee', ?, ?, 'paid', ?, ?, ?, ?)
        ")->execute([
            $memberId,
            $amountTotal,
            $currency,
            $sessionId . ($paymentIntent ? '|' . $paymentIntent : ''),
            'Stripe Buy Button — auto-confirmed via webhook. ref: ' . $clientRef,
            $now, $now,
        ]);
        $signupPayId = (int)$db->lastInsertId();

        // ── Also record payment_allocation so AccountingHooks can find the token class
        if ($signupPayId > 0) {
            $snftTcId = $db->query("SELECT id FROM token_classes WHERE class_code = 'PERSONAL_SNFT' LIMIT 1")->fetchColumn();
            if ($snftTcId) {
                $db->prepare("
                    INSERT INTO payment_allocations
                      (payment_id, member_id, token_class_id, units_allocated, amount_cents, created_at)
                    VALUES (?, ?, ?, 1, ?, ?)
                ")->execute([$signupPayId, $memberId, (int)$snftTcId, $amountTotal, $now]);
            }
        }
    } catch (Throwable $e) {
        // payments table may not exist yet — non-fatal
    }

    // ── Log to wallet_activity ────────────────────────────────────────────────
    try {
        $tcStmt = $db->prepare("SELECT id FROM token_classes WHERE class_code = 'PERSONAL_SNFT' LIMIT 1");
        $tcStmt->execute();
        $tcId = (int)($tcStmt->fetchColumn() ?: 0);

        $db->prepare("
            INSERT INTO wallet_activity
              (member_id, token_class_id, action_type, actor_type, payload_json, created_at)
            VALUES (?, ?, 'payment_received', 'stripe_webhook', ?, ?)
        ")->execute([
            $memberId,
            $tcId ?: null,
            json_encode([
                'stripe_session_id'    => $sessionId,
                'stripe_payment_intent'=> $paymentIntent,
                'client_reference_id'  => $clientRef,
                'customer_email'       => $customerEmail,
                'amount_cents'         => $amountTotal,
                'currency'             => $currency,
                'auto_confirmed'       => true,
            ], JSON_UNESCAPED_SLASHES),
            $now,
        ]);
    } catch (Throwable $e) {
        // wallet_activity may have missing columns — non-fatal
        error_log('[stripe-webhook] activity log failed: ' . $e->getMessage());
    }

    // ── Backfill stripe_payment_ref on JVPA acceptance record ────────────────
    // The acceptance_record_hash was computed at registration (Option A).
    // The Stripe ref was unknown at that point — write it now payment is confirmed.
    // Does NOT change or recompute the hash.
    try {
        $jvpaPartnerId = null;
        $partnerTableCheck = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'partners'"
        )->fetchColumn();
        if ((int)$partnerTableCheck > 0) {
            $pStmt = $db->prepare(
                'SELECT id FROM partners WHERE member_id = ? AND partner_kind = ? LIMIT 1'
            );
            $pStmt->execute([$memberId, 'personal']);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $jvpaPartnerId = (int)$pRow['id'];
            }
        }
        if ($jvpaPartnerId) {
            $stripeRef = ($paymentIntent !== '') ? $paymentIntent : $sessionId;
            JvpaAcceptanceService::backfillStripeRef($db, $jvpaPartnerId, $stripeRef);
        }
    } catch (Throwable $jvpaE) {
        // Non-fatal — payment succeeded, backfill can be run manually if needed.
        error_log('[stripe-webhook] JVPA stripe_payment_ref backfill failed: '
            . $jvpaE->getMessage() . ' | member_number=' . ($memberNumber ?? ''));
    }

    $db->commit();

    // ── Accounting: auto-create admin/investment split records ────────────
    if ($signupPayId > 0) {
        try {
            require_once dirname(__DIR__, 2) . '/admin/includes/AccountingHooks.php';
            AccountingHooks::onPaymentConfirmed($db, $signupPayId, null);
        } catch (Throwable $e) {
            error_log('[stripe-webhook] accounting hook (signup) failed: ' . $e->getMessage());
        }
    }

    // Email notifications for signup payment
    try {
        recordWalletEvent($db, 'snft_member', $memberNumber, 'payment_received',
            'Stripe signup payment received: $' . number_format($amountTotal / 100, 2));
        // Member confirmation
        queueEmail($db, 'snft_member', $memberId, (string)$member['email'], 'payment_intent_member',
            'COG$ Membership Payment Confirmed', [
                'full_name' => (string)$member['full_name'],
                'member_number' => $memberNumber,
                'token_class' => 'Personal S-NFT membership',
                'units' => 1,
                'amount' => number_format($amountTotal / 100, 2),
                'reference' => $sessionId,
            ]);
        // Admin notification
        queueEmail($db, 'snft_member', $memberId, MAIL_ADMIN_EMAIL, 'payment_intent_admin',
            'Signup Payment Received — ' . $memberNumber, [
                'full_name' => (string)$member['full_name'],
                'member_number' => $memberNumber,
                'token_class' => 'Personal S-NFT',
                'units' => 1,
                'amount' => number_format($amountTotal / 100, 2),
                'reference' => $sessionId,
            ]);
        processEmailQueue($db, 2);
    } catch (Throwable $emailErr) {
        error_log('[stripe-webhook] email notification failed: ' . $emailErr->getMessage());
    }

    error_log('[stripe-webhook] Payment confirmed — member: ' . $memberNumber . ' amount: ' . $amountTotal . ' cents session: ' . $sessionId);
    echo json_encode(['ok' => true, 'handled' => true, 'member_number' => $memberNumber]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[stripe-webhook] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Still return 200 to prevent Stripe retrying a non-recoverable error
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

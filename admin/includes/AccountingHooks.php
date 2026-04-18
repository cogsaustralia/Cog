<?php
/**
 * AccountingHooks.php
 * COG$ of Australia Foundation — Accounting Automation Layer
 * 
 * Drop this into your existing PHP admin panel codebase.
 * Call the appropriate method whenever a payment is confirmed or a token is minted.
 * 
 * INTEGRATION POINTS (wire these into your existing flows):
 * 
 *   1. When a Stripe payment is confirmed as 'paid':
 *      AccountingHooks::onPaymentConfirmed($pdo, $paymentId, $adminId);
 * 
 *   2. When a Donation COG$ is minted (mint_queue status → minted):
 *      AccountingHooks::onDonationCogMinted($pdo, $mintQueueId, $adminId);
 * 
 *   3. When an ASX dividend or RWA yield is manually recorded:
 *      AccountingHooks::onIncomeReceived($pdo, $incomeData, $adminId);
 * 
 *   4. When a Pay It Forward Class P is allocated to a funded recipient:
 *      AccountingHooks::onPayItForwardAllocated($pdo, $pClassPaymentId, $recipientMemberId, $adminId);
 *
 * STAGE 2 — Godley Accounting Specification v1.0
 * Soft-loads LedgerEmitter from the same includes/ directory if available.
 * If LedgerEmitter is not present, existing flows continue to work — Godley
 * emission is skipped gracefully and logged.
 *
 */

// Soft-load LedgerEmitter (Stage 2) — same includes/ directory as this file
// If absent, AccountingHooks still works; Godley emission is skipped gracefully
$__lePath = __DIR__ . '/LedgerEmitter.php';
if (!class_exists('LedgerEmitter', false) && file_exists($__lePath)) {
    require_once $__lePath;
}
unset($__lePath);

class AccountingHooks
{
    // ========================================================================
    // ENTRENCHED SPLIT RATIOS — Sub-Trust A Deed cl.6.2–6.7
    // These are constitutional constants. Do not make configurable.
    // ========================================================================

    private const SPLITS = [
        'PERSONAL_SNFT' => [
            'admin_cents'      => 300,  // $3.00 → Administration costs
            'invest_a_cents'   => 100,  // $1.00 → Sub-Trust A Community Basket
            'direct_c_cents'   => 0,    // No direct-to-C component
            'total_cents'      => 400,
        ],
        'KIDS_SNFT' => [
            'admin_cents'      => 0,    // No admin component
            'invest_a_cents'   => 100,  // $1.00 → Sub-Trust A in full
            'direct_c_cents'   => 0,
            'total_cents'      => 100,
        ],
        'BUSINESS_BNFT' => [
            'admin_cents'      => 3000, // $30.00 → Administration costs
            'invest_a_cents'   => 1000, // $10.00 → Sub-Trust A Community Basket
            'direct_c_cents'   => 0,
            'total_cents'      => 4000,
        ],
        'DONATION_COG' => [
            'admin_cents'      => 0,    // No admin component
            'invest_a_cents'   => 200,  // $2.00 → Sub-Trust A (ASX/RWA)
            'direct_c_cents'   => 200,  // $2.00 → Sub-Trust C direct (cl.6.7)
            'total_cents'      => 400,
        ],
        'PAY_IT_FORWARD_COG' => [
            'admin_cents'      => 300,  // $3.00 → Admin/setup of funded membership
            'invest_a_cents'   => 100,  // $1.00 → Sub-Trust A for recipient
            'direct_c_cents'   => 0,
            'total_cents'      => 400,
        ],
        // Tier 2 tokens — investment component only, no admin split from purchase price
        'ASX_INVESTMENT_COG' => [
            'admin_cents'      => 0,
            'invest_a_cents'   => 400,  // Full $4.00 → Sub-Trust A
            'direct_c_cents'   => 0,
            'total_cents'      => 400,
        ],
        'LANDHOLDER_COG' => [
            'admin_cents'      => 0,
            'invest_a_cents'   => 400,
            'direct_c_cents'   => 0,
            'total_cents'      => 400,
        ],
        'RWA_COG' => [
            'admin_cents'      => 0,
            'invest_a_cents'   => 400,
            'direct_c_cents'   => 0,
            'total_cents'      => 400,
        ],
        'BUS_PROP_COG' => [
            'admin_cents'      => 0,
            'invest_a_cents'   => 400,
            'direct_c_cents'   => 0,
            'total_cents'      => 400,
        ],
    ];

    // Trust account IDs (match trust_accounts seed data)
    private const ACCT_TRUST_A_OPERATING = 1;
    private const ACCT_TRUST_B_INCOMING  = 2;
    private const ACCT_TRUST_B_OUTGOING  = 3;
    private const ACCT_TRUST_C_INCOMING  = 4;
    private const ACCT_TRUST_C_OUTGOING  = 5;
    private const ACCT_TRUST_A_ADMIN_FUND = null; // Looked up dynamically

    // ========================================================================
    // 1. ON PAYMENT CONFIRMED
    //    Call when a payment row transitions to payment_status = 'paid'
    //    Creates trust_expenses and trust_transfers for the split.
    // ========================================================================

    public static function onPaymentConfirmed(PDO $pdo, int $paymentId, ?int $adminId = null): array
    {
        $log = [];

        // Fetch the payment and its allocations
        $payment = self::fetchRow($pdo,
            "SELECT p.*, m.full_name, m.member_number
             FROM payments p
             JOIN members m ON m.id = p.member_id
             WHERE p.id = ? AND p.payment_status = 'paid'",
            [$paymentId]
        );

        if (!$payment) {
            return ['error' => "Payment #{$paymentId} not found or not paid"];
        }

        $allocations = self::fetchAll($pdo,
            "SELECT pa.*, tc.class_code
             FROM payment_allocations pa
             JOIN token_classes tc ON tc.id = pa.token_class_id
             WHERE pa.payment_id = ?",
            [$paymentId]
        );

        if (empty($allocations)) {
            return ['error' => "No payment allocations found for payment #{$paymentId}"];
        }

        $pdo->beginTransaction();

        try {
            foreach ($allocations as $alloc) {
                $classCode = $alloc['class_code'];
                $split = self::SPLITS[$classCode] ?? null;

                if (!$split) {
                    $log[] = "SKIP: No split defined for class {$classCode}";
                    continue;
                }

                $units = (float) $alloc['units_allocated'];
                $memberNum = $payment['member_number'];
                $memberName = $payment['full_name'];
                $payRef = $payment['external_reference'] ?? "PAY-{$paymentId}";
                $receivedAt = $payment['received_at'] ?? $payment['created_at'];

                for ($u = 0; $u < $units; $u++) {
                    $unitSuffix = $units > 1 ? "-U" . ($u + 1) : "";
                    $refBase = "PAY{$paymentId}-{$classCode}-M{$payment['member_id']}{$unitSuffix}";

                    // --- Admin component → trust_transfers (payment to Admin Fund) ---
                    if ($split['admin_cents'] > 0) {
                        $admRef = "ADMFUND-{$refBase}";
                        $adminFundId = self::getAdminFundAccountId($pdo);

                        if ($adminFundId && !self::refExists($pdo, 'trust_transfers', 'transfer_ref', $admRef)) {
                            $stmt = $pdo->prepare(
                                "INSERT INTO trust_transfers
                                 (transfer_ref, transfer_type, source_account_id, destination_account_id,
                                  amount_cents, currency_code, status, bank_reference,
                                  transferred_at, notes, created_by_admin_id)
                                 VALUES
                                 (?, 'payment_to_admin', ?, ?, ?, 'AUD', 'completed', ?, ?, ?, ?)"
                            );
                            $stmt->execute([
                                $admRef,
                                self::ACCT_TRUST_A_OPERATING,
                                $adminFundId,
                                $split['admin_cents'],
                                $payRef,
                                $receivedAt,
                                "{$classCode} admin allocation — {$memberName} ({$memberNum}). To Admin Fund per Sub-Trust A Deed.",
                                $adminId,
                            ]);
                            $log[] = "ADMIN_FUND: {$admRef} → {$split['admin_cents']}c";
                        }
                    }

                    // --- Investment component → trust_transfers (retained in Trust A) ---
                    if ($split['invest_a_cents'] > 0) {
                        $invRef = "INV-{$refBase}";

                        if (!self::refExists($pdo, 'trust_transfers', 'transfer_ref', $invRef)) {
                            $stmt = $pdo->prepare(
                                "INSERT INTO trust_transfers
                                 (transfer_ref, transfer_type, source_account_id, destination_account_id,
                                  amount_cents, currency_code, status, bank_reference,
                                  transferred_at, notes, created_by_admin_id)
                                 VALUES
                                 (?, 'a_to_a_reinvest_bds', ?, ?, ?, 'AUD', 'completed', ?, ?, ?, ?)"
                            );
                            $stmt->execute([
                                $invRef,
                                self::ACCT_TRUST_A_OPERATING,
                                self::ACCT_TRUST_A_OPERATING,
                                $split['invest_a_cents'],
                                $payRef,
                                $receivedAt,
                                "{$classCode} investment component — {$memberName} ({$memberNum}). Retained in Sub-Trust A for Community Basket.",
                                $adminId,
                            ]);
                            $log[] = "TRANSFER: {$invRef} → {$split['invest_a_cents']}c (Trust A reinvest)";
                        }
                    }

                    // --- Donation COG$ direct-to-C component ---
                    if ($split['direct_c_cents'] > 0) {
                        $result = self::createDonationDirectTransfer(
                            $pdo, $refBase, $split, $payment, $receivedAt, $payRef, $adminId
                        );
                        $log = array_merge($log, $result);
                    }

                    // --- Godley ledger emission (Stage 2 — spec §6.2) ---
                    //     Soft-loaded: failure does not break existing trust_transfers/expenses writes.
                    if (class_exists('LedgerEmitter')) {
                        $godleyRef = "GDLY-{$refBase}";
                        $godleyEntries = self::buildGodleyEntries(
                            $classCode, (int) $payment['member_id']
                        );
                        if ($godleyEntries !== null) {
                            $res = \LedgerEmitter::emitTransaction(
                                $pdo, $godleyRef, 'payments', $paymentId,
                                $godleyEntries, substr($receivedAt, 0, 10)
                            );
                            if ($res['status'] === 'ok') {
                                $log[] = "GODLEY: {$godleyRef} → " . count($res['entry_ids']) . " entries";
                            } elseif ($res['status'] === 'skip') {
                                $log[] = "GODLEY_SKIP: {$godleyRef} ({$res['message']})";
                            } else {
                                $log[] = "GODLEY_ERROR: {$godleyRef} — {$res['message']}";
                                throw new \RuntimeException("Godley emission failed: {$res['message']}");
                            }
                        }
                    }
                }
            }

            $pdo->commit();
            $log[] = "COMMITTED: Payment #{$paymentId} accounting complete";

        } catch (\Throwable $e) {
            $pdo->rollBack();
            $log[] = "ROLLBACK: " . $e->getMessage();
        }

        return $log;
    }


    /**
     * Map existing token class code → Godley entry builder.
     * Returns null for classes not yet handled by the Godley ledger.
     * Spec §4.1 Unit Issue Flows.
     */
    private static function buildGodleyEntries(string $classCode, int $memberId): ?array
    {
        if (!class_exists('LedgerEmitter')) return null;
        switch ($classCode) {
            case 'PERSONAL_SNFT':
                // NB: $paymentId is passed as 0 here; real source_id populated by emitTransaction
                return \LedgerEmitter::buildSClassEntries($memberId, 0);
            case 'KIDS_SNFT':
                return \LedgerEmitter::buildKSClassEntries($memberId);
            case 'BUSINESS_BNFT':
                return \LedgerEmitter::buildBClassEntries($memberId);
            case 'DONATION_COG':
                return \LedgerEmitter::buildDClassEntries($memberId);
            case 'PAY_IT_FORWARD_COG':
                return \LedgerEmitter::buildPClassStage1Entries($memberId);
            case 'ASX_INVESTMENT_COG':
                return \LedgerEmitter::buildTier2Entries($memberId, 400, 'unit_issue_a');
            case 'LANDHOLDER_COG':
                return \LedgerEmitter::buildTier2Entries($memberId, 400, 'unit_issue_lh');
            case 'BUS_PROP_COG':
                return \LedgerEmitter::buildTier2Entries($memberId, 4000, 'unit_issue_bp');
            case 'RWA_COG':
                return \LedgerEmitter::buildTier2Entries($memberId, 400, 'unit_issue_r');
            default:
                return null;
        }
    }

    /**
     * Pay It Forward — Stage 2 allocation to recipient.
     * Spec §4.1: releases $1 from P-CLASS-SUSPENSE → STA-PARTNERS-POOL
     * and activates Beneficial Unit on recipient.
     *
     * Call after:
     *   - Recipient member account exists (members.id = $recipientMemberId)
     *   - Recipient KYC is complete
     *   - The corresponding P Class Unit has been selected for allocation
     */
    public static function onPayItForwardAllocated(
        PDO $pdo,
        int $pClassPaymentId,
        int $recipientMemberId,
        ?int $adminId = null
    ): array {
        $log = [];

        if (!class_exists('LedgerEmitter')) {
            return ['error' => 'LedgerEmitter not loaded — Stage 2 not yet deployed'];
        }

        $ref = "PCLASS-ALLOC-P{$pClassPaymentId}-M{$recipientMemberId}";
        $entries = \LedgerEmitter::buildPClassStage2Entries($recipientMemberId);

        $res = \LedgerEmitter::emitTransaction(
            $pdo, $ref, 'payments', $pClassPaymentId, $entries
        );

        if ($res['status'] === 'ok') {
            $log[] = "PCLASS_ALLOCATED: {$ref} → " . count($res['entry_ids']) . " entries";
        } else {
            $log[] = "PCLASS_ALLOC_ERROR: {$res['message']}";
        }

        return $log;
    }


    // ========================================================================
    // 2. ON DONATION COG$ MINTED
    //    Call when a Donation COG$ progresses through the mint pipeline.
    //    Creates the donation_ledger entry if not already present.
    //    The direct-to-C transfer may already exist from onPaymentConfirmed;
    //    this ensures the donation_ledger row is linked.
    // ========================================================================

    public static function onDonationCogMinted(PDO $pdo, int $mintQueueId, ?int $adminId = null): array
    {
        $log = [];

        $mq = self::fetchRow($pdo,
            "SELECT mq.*, tc.class_code, m.full_name, m.member_number
             FROM mint_queue mq
             JOIN token_classes tc ON tc.id = mq.token_class_id
             JOIN members m ON m.id = mq.member_id
             WHERE mq.id = ?",
            [$mintQueueId]
        );

        if (!$mq || $mq['class_code'] !== 'DONATION_COG') {
            return ['error' => "Mint queue #{$mintQueueId} not found or not DONATION_COG"];
        }

        $units = max(0.0001, (float) $mq['approved_units']);
        $mintedAt = $mq['processed_at'] ?? date('Y-m-d H:i:s');

        // Calculate 2-business-day deadline
        $dueBy = self::addBusinessDays($mintedAt, 2);

        $pdo->beginTransaction();

        try {
            for ($u = 0; $u < $units; $u++) {
                $unitSuffix = $units > 1 ? "-U" . ($u + 1) : "";
                $dlRef = "DL-MQ{$mintQueueId}-M{$mq['member_id']}{$unitSuffix}";

                // Check if donation_ledger entry already exists
                $existing = self::fetchRow($pdo,
                    "SELECT id FROM donation_ledger WHERE donation_cog_mint_id = ? AND member_id = ?",
                    [$mintQueueId, $mq['member_id']]
                );

                if (!$existing) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO donation_ledger
                         (donation_cog_mint_id, member_id, token_class_id, units_minted,
                          issue_price_cents, direct_to_c_cents, invested_in_a_cents,
                          transfer_to_c_status, transfer_to_c_due_by, minted_at)
                         VALUES (?, ?, ?, 1, 400, 200, 200, 'pending', ?, ?)"
                    );
                    $stmt->execute([
                        $mintQueueId,
                        $mq['member_id'],
                        $mq['token_class_id'],
                        $dueBy,
                        $mintedAt,
                    ]);

                    $donationLedgerId = (int) $pdo->lastInsertId();
                    $log[] = "DONATION_LEDGER: #{$donationLedgerId} created for MQ#{$mintQueueId}";

                    // Create the pending Trust A → Trust C direct transfer
                    $transferRef = "DTOC-MQ{$mintQueueId}-M{$mq['member_id']}{$unitSuffix}";

                    if (!self::refExists($pdo, 'trust_transfers', 'transfer_ref', $transferRef)) {
                        $stmt = $pdo->prepare(
                            "INSERT INTO trust_transfers
                             (transfer_ref, transfer_type, source_account_id, destination_account_id,
                              amount_cents, currency_code, donation_ledger_id,
                              compliance_due_by, status, notes, created_by_admin_id)
                             VALUES
                             (?, 'a_to_c_direct', ?, ?, 200, 'AUD', ?, ?, 'pending', ?, ?)"
                        );
                        $stmt->execute([
                            $transferRef,
                            self::ACCT_TRUST_A_OPERATING,
                            self::ACCT_TRUST_C_INCOMING,
                            $donationLedgerId,
                            $dueBy,
                            "Donation COG$ direct $2.00 to Sub-Trust C — {$mq['full_name']} ({$mq['member_number']}). DEADLINE: {$dueBy}. Sub-Trust A Deed cl.6.7, Declaration cl.24.1.",
                            $adminId,
                        ]);
                        $log[] = "TRANSFER: {$transferRef} → 200c (A→C direct, due {$dueBy})";
                    }
                } else {
                    $log[] = "SKIP: Donation ledger already exists for MQ#{$mintQueueId}";
                }
            }

            $pdo->commit();
            $log[] = "COMMITTED: Donation COG$ mint #{$mintQueueId} accounting complete";

        } catch (\Throwable $e) {
            $pdo->rollBack();
            $log[] = "ROLLBACK: " . $e->getMessage();
        }

        return $log;
    }


    // ========================================================================
    // 3. CONFIRM DONATION DIRECT TRANSFER
    //    Call when the $2.00 Trust A → Trust C bank transfer is executed.
    //    Updates both trust_transfers and donation_ledger.
    // ========================================================================

    public static function confirmDonationDirectTransfer(
        PDO $pdo, int $donationLedgerId, string $bankReference, ?int $adminId = null
    ): array {
        $log = [];
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        try {
            // Update donation_ledger
            $stmt = $pdo->prepare(
                "UPDATE donation_ledger
                 SET transfer_to_c_status = 'transferred',
                     transfer_to_c_at = ?,
                     updated_at = ?
                 WHERE id = ? AND transfer_to_c_status = 'pending'"
            );
            $stmt->execute([$now, $now, $donationLedgerId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return ['error' => "Donation ledger #{$donationLedgerId} not found or already transferred"];
            }

            // Update linked trust_transfer
            $stmt = $pdo->prepare(
                "UPDATE trust_transfers
                 SET status = 'completed',
                     bank_reference = ?,
                     transferred_at = ?,
                     approved_by_admin_id = ?,
                     approved_at = ?,
                     updated_at = ?
                 WHERE donation_ledger_id = ? AND transfer_type = 'a_to_c_direct'"
            );
            $stmt->execute([$bankReference, $now, $adminId, $now, $now, $donationLedgerId]);

            $pdo->commit();
            $log[] = "CONFIRMED: Donation direct transfer #{$donationLedgerId} → bank ref {$bankReference}";

        } catch (\Throwable $e) {
            $pdo->rollBack();
            $log[] = "ROLLBACK: " . $e->getMessage();
        }

        return $log;
    }


    // ========================================================================
    // 4. CHECK OVERDUE TRANSFERS
    //    Call from a daily cron or admin dashboard.
    //    Returns any transfers or donation ledger entries past their deadline.
    // ========================================================================

    public static function checkOverdue(PDO $pdo): array
    {
        $overdue = [];

        // Overdue trust transfers (any type)
        $rows = self::fetchAll($pdo,
            "SELECT id, transfer_ref, transfer_type, amount_cents,
                    compliance_due_by, status,
                    DATEDIFF(NOW(), compliance_due_by) AS days_overdue
             FROM trust_transfers
             WHERE status IN ('pending', 'approved')
               AND compliance_due_by IS NOT NULL
               AND compliance_due_by < NOW()
             ORDER BY compliance_due_by ASC"
        );
        foreach ($rows as $r) {
            $overdue[] = [
                'type'    => 'trust_transfer',
                'ref'     => $r['transfer_ref'],
                'subtype' => $r['transfer_type'],
                'amount'  => $r['amount_cents'],
                'due'     => $r['compliance_due_by'],
                'days'    => $r['days_overdue'],
            ];
        }

        // Overdue donation direct-to-C transfers
        $rows = self::fetchAll($pdo,
            "SELECT dl.id, dl.member_id, dl.direct_to_c_cents,
                    dl.transfer_to_c_due_by, dl.transfer_to_c_status,
                    DATEDIFF(NOW(), dl.transfer_to_c_due_by) AS days_overdue,
                    m.full_name, m.member_number
             FROM donation_ledger dl
             JOIN members m ON m.id = dl.member_id
             WHERE dl.transfer_to_c_status = 'pending'
               AND dl.transfer_to_c_due_by < NOW()
             ORDER BY dl.transfer_to_c_due_by ASC"
        );
        foreach ($rows as $r) {
            $overdue[] = [
                'type'    => 'donation_direct_to_c',
                'ref'     => "DL#{$r['id']}",
                'member'  => "{$r['full_name']} ({$r['member_number']})",
                'amount'  => $r['direct_to_c_cents'],
                'due'     => $r['transfer_to_c_due_by'],
                'days'    => $r['days_overdue'],
            ];
        }

        return $overdue;
    }


    // ========================================================================
    // 5. GET FUND SUMMARY
    //    Quick totals for the admin dashboard.
    // ========================================================================

    public static function getFundSummary(PDO $pdo): array
    {
        $adminFundId = self::getAdminFundAccountId($pdo);

        // Admin fund inflows (payment_to_admin + a_to_admin_dds transfers)
        $adminFundIn = (int) self::fetchValue($pdo,
            "SELECT COALESCE(SUM(amount_cents), 0) FROM trust_transfers
             WHERE transfer_type IN ('payment_to_admin','a_to_admin_dds') AND status = 'completed'"
        );

        // Admin fund outflows (expenses charged to admin fund account)
        $adminFundOut = $adminFundId ? (int) self::fetchValue($pdo,
            "SELECT COALESCE(SUM(amount_cents), 0) FROM trust_expenses
             WHERE status IN ('approved','paid') AND trust_account_id = ?",
            [$adminFundId]
        ) : 0;

        return [
            'total_received_cents' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE payment_status = 'paid'"
            ),
            'admin_fund_in_cents' => $adminFundIn,
            'admin_fund_out_cents' => $adminFundOut,
            'admin_fund_balance_cents' => $adminFundIn - $adminFundOut,
            'total_admin_expenses_cents' => $adminFundOut,
            'total_investment_retained_cents' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(amount_cents), 0) FROM trust_transfers
                 WHERE transfer_type IN ('a_to_a_reinvest_bds','a_to_a_reinvest_dds') AND status = 'completed'"
            ),
            'total_direct_to_c_cents' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(amount_cents), 0) FROM trust_transfers
                 WHERE transfer_type = 'a_to_c_direct' AND status = 'completed'"
            ),
            'pending_direct_to_c_cents' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(amount_cents), 0) FROM trust_transfers
                 WHERE transfer_type = 'a_to_c_direct' AND status = 'pending'"
            ),
            'total_to_trust_b_cents' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(amount_cents), 0) FROM trust_transfers
                 WHERE transfer_type IN ('a_to_b_bds','a_to_b_dds') AND status = 'completed'"
            ),
            'total_grants_paid_cents' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(amount_cents), 0) FROM grants WHERE status IN ('disbursed','acquitted')"
            ),
            'fn_grant_pct' => self::fetchValue($pdo,
                "SELECT COALESCE(
                   ROUND(SUM(CASE WHEN is_first_nations = 1 THEN amount_cents ELSE 0 END)
                         / GREATEST(SUM(amount_cents), 1) * 100, 2), 0)
                 FROM grants WHERE status IN ('approved','disbursed','acquitted')"
            ),
            'overdue_count' => count(self::checkOverdue($pdo)),
            'donation_ledger_pending' => (int) self::fetchValue($pdo,
                "SELECT COUNT(*) FROM donation_ledger WHERE transfer_to_c_status = 'pending'"
            ),
            'beneficial_units_total' => (int) self::fetchValue($pdo,
                "SELECT COALESCE(SUM(rl.approved_units), 0)
                 FROM member_reservation_lines rl
                 JOIN token_classes tc ON tc.id = rl.token_class_id
                 WHERE tc.class_code NOT IN ('LR_COG')
                   AND rl.approval_status = 'approved'"
            ),
        ];
    }


    // ========================================================================
    // 6. RECORD STRIPE FEE
    //    Call after a Stripe payment is confirmed. Auto-estimates the fee
    //    based on Stripe Australia standard rates and records it as a
    //    trust_expense. Tagged as estimated for monthly reconciliation.
    //
    //    Stripe AU rates (as at April 2026):
    //      Domestic cards:      1.75% + $0.30
    //      International cards: 2.9%  + $0.30
    //    Default assumes domestic. Adjust during reconciliation if needed.
    // ========================================================================

    private const STRIPE_DOMESTIC_PCT  = 0.0175;
    private const STRIPE_DOMESTIC_FLAT = 30; // cents
    private const STRIPE_INTL_PCT     = 0.029;
    private const STRIPE_INTL_FLAT    = 30; // cents

    public static function recordStripeFee(
        PDO $pdo, int $paymentId, int $amountCents, ?string $stripeRef = null,
        bool $international = false, ?int $adminId = null
    ): array {
        $log = [];

        $pctRate  = $international ? self::STRIPE_INTL_PCT : self::STRIPE_DOMESTIC_PCT;
        $flatFee  = $international ? self::STRIPE_INTL_FLAT : self::STRIPE_DOMESTIC_FLAT;
        $feeCents = (int)round(($amountCents * $pctRate) + $flatFee);

        if ($feeCents < 1) {
            return ['skip' => 'Fee is zero'];
        }

        // GST on Stripe fees: Stripe charges GST on their fees in Australia (1/11 of fee)
        $gstCents = (int)round($feeCents / 11);

        $rateLabel = $international ? '2.9% + 30c (intl)' : '1.75% + 30c (domestic)';
        $refDate   = date('Ymd');
        $refRand   = strtoupper(substr(md5((string)$paymentId . uniqid('', true)), 0, 6));
        $expRef    = 'STRIPE-FEE-' . $refDate . '-' . $refRand;

        try {
            if (self::refExists($pdo, 'trust_expenses', 'expense_ref', $expRef)) {
                return ['skip' => 'Stripe fee already recorded for this ref'];
            }

            $stmt = $pdo->prepare(
                "INSERT INTO trust_expenses
                 (expense_ref, expense_category, description, amount_cents, gst_cents,
                  currency_code, expense_date, payee_name, payee_abn, invoice_reference,
                  trust_account_id, payment_method, status, paid_at,
                  notes, created_by_admin_id, approved_by_admin_id, approved_at)
                 VALUES
                 (?, 'other', ?, ?, ?, 'AUD', CURDATE(), 'Stripe Payments Australia',
                  '84 006 257 471', ?,
                  COALESCE((SELECT id FROM trust_accounts WHERE account_code='TRUST_A_ADMIN_FUND' LIMIT 1), 1),
                  'STRIPE', 'paid', NOW(),
                  ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $expRef,
                'Stripe processing fee — payment #' . $paymentId . ' ($' . number_format($amountCents / 100, 2) . ')',
                $feeCents,
                $gstCents,
                $stripeRef,
                'ESTIMATED: ' . $rateLabel . ' on $' . number_format($amountCents / 100, 2)
                    . '. Verify against Stripe dashboard during monthly reconciliation.'
                    . ($international ? ' International card rate applied.' : ''),
                $adminId,
                $adminId,
            ]);

            $log[] = "STRIPE_FEE: {$expRef} → {$feeCents}c (GST {$gstCents}c) — estimated {$rateLabel}";

            // --- Godley ledger emission for Stripe fee ---
            if (class_exists('LedgerEmitter')) {
                $expenseId = (int) $pdo->lastInsertId();
                $godleyRef = "GDLY-{$expRef}";
                $res = \LedgerEmitter::emitTransaction(
                    $pdo, $godleyRef, 'trust_expenses', $expenseId,
                    \LedgerEmitter::buildStripeFeeEntries($feeCents, $gstCents)
                );
                if ($res['status'] === 'ok') {
                    $log[] = "GODLEY_FEE: {$godleyRef} → " . count($res['entry_ids']) . " entries";
                } elseif ($res['status'] === 'error') {
                    $log[] = "GODLEY_FEE_ERROR: {$res['message']}";
                }
            }

        } catch (\Throwable $e) {
            $log[] = "STRIPE_FEE_ERROR: " . $e->getMessage();
        }

        return $log;
    }


    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    private static function getAdminFundAccountId(PDO $pdo): ?int
    {
        static $cached = null;
        if ($cached !== null) return $cached ?: null;
        try {
            $stmt = $pdo->prepare("SELECT id FROM trust_accounts WHERE account_code = 'TRUST_A_ADMIN_FUND' LIMIT 1");
            $stmt->execute();
            $id = $stmt->fetchColumn();
            $cached = $id ? (int)$id : 0;
            return $cached ?: null;
        } catch (\Throwable $e) {
            $cached = 0;
            return null;
        }
    }

    /**
     * Create the $2.00 direct-to-C transfer for a Donation COG$ payment.
     * Called from onPaymentConfirmed when class_code = DONATION_COG.
     * The donation_ledger entry is created later in onDonationCogMinted
     * (once the token actually mints), but the transfer is created now
     * so the compliance deadline starts ticking from payment.
     */
    private static function createDonationDirectTransfer(
        PDO $pdo, string $refBase, array $split, array $payment,
        string $receivedAt, string $payRef, ?int $adminId
    ): array {
        $log = [];
        $dueBy = self::addBusinessDays($receivedAt, 2);
        $transferRef = "DTOC-{$refBase}";

        if (!self::refExists($pdo, 'trust_transfers', 'transfer_ref', $transferRef)) {
            $stmt = $pdo->prepare(
                "INSERT INTO trust_transfers
                 (transfer_ref, transfer_type, source_account_id, destination_account_id,
                  amount_cents, currency_code, compliance_due_by, status,
                  notes, created_by_admin_id)
                 VALUES
                 (?, 'a_to_c_direct', ?, ?, ?, 'AUD', ?, 'pending', ?, ?)"
            );
            $stmt->execute([
                $transferRef,
                self::ACCT_TRUST_A_OPERATING,
                self::ACCT_TRUST_C_INCOMING,
                $split['direct_c_cents'],
                $dueBy,
                "Donation COG$ $2.00 direct to Sub-Trust C — {$payment['full_name']} ({$payment['member_number']}). DEADLINE: {$dueBy}. Payment ref: {$payRef}.",
                $adminId,
            ]);
            $log[] = "TRANSFER: {$transferRef} → {$split['direct_c_cents']}c (A→C direct, due {$dueBy})";
        }

        return $log;
    }

    /**
     * Add N business days to a datetime string.
     * Skips Saturday and Sunday. Does not account for AU public holidays
     * (add a holiday table lookup if needed).
     */
    private static function addBusinessDays(string $dateStr, int $days): string
    {
        $date = new \DateTime(substr($dateStr, 0, 10));
        $added = 0;
        while ($added < $days) {
            $date->modify('+1 day');
            $dow = (int) $date->format('N'); // 1=Mon, 7=Sun
            if ($dow <= 5) {
                $added++;
            }
        }
        return $date->format('Y-m-d') . ' 23:59:59';
    }

    private static function refExists(PDO $pdo, string $table, string $col, string $val): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$col}` = ? LIMIT 1");
        $stmt->execute([$val]);
        return (bool) $stmt->fetchColumn();
    }

    private static function fetchRow(PDO $pdo, string $sql, array $params = []): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fetchValue(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

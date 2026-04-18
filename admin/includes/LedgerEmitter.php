<?php
declare(strict_types=1);

/**
 * LedgerEmitter — Godley Accounting Specification v1.0
 *
 * Central service for emitting balanced double-entry ledger_entries.
 * Every Foundation transaction passes through this class.
 *
 * Spec reference: §6.2 ledger_entries; §5 invariants I1–I12.
 * Stage 2 implementation (backend only).
 *
 * Rules:
 *   - Every emitTransaction() call must pass a balanced set of entries.
 *   - balance = SUM(debit amounts) - SUM(credit amounts) MUST equal 0.
 *   - At least 2 entries per transaction_ref.
 *   - transaction_ref must be unique (idempotent guard).
 *   - Calls fn_verify_transaction_balanced() stored procedure at commit to
 *     enforce row-sum-zero via SIGNAL SQLSTATE '45000' on violation.
 */
class LedgerEmitter
{
    /** Valid source_table values matching the ledger_entries ENUM. */
    public const SOURCES = [
        'payments', 'trust_transfers', 'trust_expenses', 'dividend_events',
        'benefit_flow_records', 'stewardship_journals', 'grants',
        'donation_ledger', 'backfill',
    ];

    /** Valid classifications — asset / liability / equity / income / expense. */
    public const CLASSIFICATIONS = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Flow category vocabulary — used by invariant views for scoping. */
    public const FLOW_CATEGORIES = [
        // Unit issues
        'unit_issue_s', 'unit_issue_ks', 'unit_issue_b', 'unit_issue_d',
        'unit_issue_p_stage1', 'unit_issue_p_stage2_allocation',
        'unit_issue_a', 'unit_issue_lh', 'unit_issue_bp', 'unit_issue_r', 'unit_issue_c',
        // Admin fund / expenses
        'payment_to_admin', 'stripe_fee', 'gst_itc', 'operating_expense', 'trustee_fee',
        // Transit within Sub-Trust A
        'sta_transit', 'donation_direct_c',
        // Income flows
        'dividend_receipt', 'bds_split', 'dds_split', 'reinvest_internal',
        'interest_income',       // interest income to STA-OPERATING
        'other_income',          // general / RWA yield income to STA-OPERATING
        'franking_credit',       // franking credit receivable from ATO (EXTERNAL-ATO asset)
        // Tax
        'tax_withholding',       // TFN withholding deducted at source — receivable from ATO
        // Sub-Trust B distributions
        'stb_distribution', 'bonus_election_netting',
        // Sub-Trust C flows
        'grant_disbursement', 'gift_received',
        // ASX
        'asx_acquisition', 'asset_swap',
        // kS-NFT conversion (proxy vote release on child turning 18)
        'ks_conversion',
        // Correction / reversal journals
        'correction_reversal',
        // Exit paths (permitted MEMBER ← STA only under I9 exceptions)
        'lawful_extinguishment', 'winding_up', 'p_class_allocation',
    ];

    /** Cached sector_key → stewardship_account.id map. */
    private static array $accountIdCache = [];

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    /**
     * Emit a balanced double-entry transaction.
     *
     * @param PDO    $pdo          Active PDO connection (not inside a transaction; we open our own).
     * @param string $transactionRef  Globally unique reference (e.g. "PAY{id}-S-M{memberId}").
     * @param string $sourceTable  One of self::SOURCES.
     * @param int    $sourceId     FK id in the source table.
     * @param array  $entries      Array of entry rows, each with:
     *                             [ 'account_key' => 'STA-OPERATING',
     *                               'type'        => 'debit' | 'credit',
     *                               'amount_cents'=> 400,
     *                               'classification'=> 'asset' | 'liability' | ...,
     *                               'flow_category' => 'unit_issue_s' ]
     * @param string $entryDate    YYYY-MM-DD; defaults to today.
     *
     * @return array{status:string, transaction_ref:string, entry_ids:array<int>, message?:string}
     *
     * @throws PDOException on database error.
     */
    public static function emitTransaction(
        PDO $pdo,
        string $transactionRef,
        string $sourceTable,
        int $sourceId,
        array $entries,
        ?string $entryDate = null
    ): array {
        $entryDate ??= date('Y-m-d');

        // Validate inputs
        $validation = self::validate($entries, $sourceTable);
        if ($validation !== null) {
            return ['status' => 'error', 'transaction_ref' => $transactionRef, 'entry_ids' => [], 'message' => $validation];
        }

        // Idempotency — skip if this transaction_ref already exists
        $existing = self::refExists($pdo, $transactionRef);
        if ($existing > 0) {
            return [
                'status' => 'skip',
                'transaction_ref' => $transactionRef,
                'entry_ids' => [],
                'message' => "transaction_ref already exists with {$existing} entries",
            ];
        }

        // Resolve account keys to IDs (auto-create member/donor sectors as needed)
        $resolved = [];
        foreach ($entries as $e) {
            $accountId = self::resolveAccountId($pdo, $e['account_key']);
            if ($accountId === null) {
                return [
                    'status' => 'error',
                    'transaction_ref' => $transactionRef,
                    'entry_ids' => [],
                    'message' => "Unknown account_key: {$e['account_key']}",
                ];
            }
            $resolved[] = $e + ['account_id' => $accountId];
        }

        // Insert all entries in one DB transaction; call fn_verify at commit
        $managedTxn = !$pdo->inTransaction();
        $entryIds = [];
        try {
            if ($managedTxn) $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO ledger_entries
                  (transaction_ref, source_table, source_id, stewardship_account_id,
                   entry_type, amount_cents, classification, flow_category, entry_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($resolved as $e) {
                $stmt->execute([
                    $transactionRef,
                    $sourceTable,
                    $sourceId,
                    $e['account_id'],
                    $e['type'],
                    $e['amount_cents'],
                    $e['classification'],
                    $e['flow_category'] ?? null,
                    $entryDate,
                ]);
                $entryIds[] = (int) $pdo->lastInsertId();
            }

            // Enforce row-sum-zero via stored procedure (SIGNAL on violation)
            $pdo->prepare("CALL fn_verify_transaction_balanced(?)")->execute([$transactionRef]);

            if ($managedTxn) $pdo->commit();

            return [
                'status' => 'ok',
                'transaction_ref' => $transactionRef,
                'entry_ids' => $entryIds,
            ];
        } catch (\Throwable $e) {
            if ($managedTxn && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'status' => 'error',
                'transaction_ref' => $transactionRef,
                'entry_ids' => [],
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve a sector account_key to its stewardship_accounts.id.
     * Auto-creates MEMBER-{id} or DONOR-{id} rows on first reference.
     *
     * @return int|null  Account ID, or null if key is unrecognized and cannot be created.
     */
    public static function resolveAccountId(PDO $pdo, string $accountKey): ?int
    {
        if (isset(self::$accountIdCache[$accountKey])) {
            return self::$accountIdCache[$accountKey];
        }

        // Direct lookup first
        $stmt = $pdo->prepare("SELECT id FROM stewardship_accounts WHERE account_key = ? LIMIT 1");
        $stmt->execute([$accountKey]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            self::$accountIdCache[$accountKey] = (int) $id;
            return (int) $id;
        }

        // Auto-create MEMBER-{id} or DONOR-{id} on demand
        if (preg_match('/^MEMBER-(\d+)$/', $accountKey, $m)) {
            return self::createMemberSector($pdo, (int) $m[1], $accountKey);
        }
        if (preg_match('/^DONOR-(\d+)$/', $accountKey, $m)) {
            return self::createDonorSector($pdo, (int) $m[1], $accountKey);
        }

        return null;
    }

    /** Clear the account ID cache — call in long-running processes if needed. */
    public static function clearCache(): void
    {
        self::$accountIdCache = [];
    }

    // ========================================================================
    // FLOW HELPERS — Canonical entry-set builders per spec §4
    // ========================================================================

    /**
     * S-Class $4.00 Personal S-NFT issue.
     * Spec §4.1: MEMBER −$4 | STA-OP +4/-4 | STA-ADMIN-FUND +$3 | STA-PARTNERS-POOL +$1
     */
    public static function buildSClassEntries(int $memberId, int $paymentId): array
    {
        return [
            ['account_key' => "MEMBER-{$memberId}",  'type' => 'credit', 'amount_cents' => 400,
             'classification' => 'equity',  'flow_category' => 'unit_issue_s'],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => 400,
             'classification' => 'asset',   'flow_category' => 'unit_issue_s'],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => 400,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STA-ADMIN-FUND',      'type' => 'debit',  'amount_cents' => 300,
             'classification' => 'asset',   'flow_category' => 'payment_to_admin'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'unit_issue_s'],
        ];
    }

    /**
     * kS-Class $1.00 Kids S-NFT issue — 100% to Partners Pool.
     */
    public static function buildKSClassEntries(int $memberId): array
    {
        return [
            ['account_key' => "MEMBER-{$memberId}",  'type' => 'credit', 'amount_cents' => 100,
             'classification' => 'equity',  'flow_category' => 'unit_issue_ks'],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'unit_issue_ks'],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'unit_issue_ks'],
        ];
    }

    /**
     * B-Class $40.00 Business BNFT issue — $30 admin, $10 pool.
     */
    public static function buildBClassEntries(int $memberId): array
    {
        return [
            ['account_key' => "MEMBER-{$memberId}",  'type' => 'credit', 'amount_cents' => 4000,
             'classification' => 'equity',  'flow_category' => 'unit_issue_b'],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => 4000,
             'classification' => 'asset',   'flow_category' => 'unit_issue_b'],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => 4000,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STA-ADMIN-FUND',      'type' => 'debit',  'amount_cents' => 3000,
             'classification' => 'asset',   'flow_category' => 'payment_to_admin'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => 1000,
             'classification' => 'asset',   'flow_category' => 'unit_issue_b'],
        ];
    }

    /**
     * D-Class $4.00 Donation COG$ issue.
     * Spec §4.1: DONOR −$4 | STA-OP +4/-4 | STC-OP +$2 direct | STA-PARTNERS-POOL +$2
     * NO Beneficial Unit activates for donor (cl.24.1); STC holds D Class BU.
     */
    public static function buildDClassEntries(int $donorId): array
    {
        return [
            ['account_key' => "DONOR-{$donorId}",    'type' => 'credit', 'amount_cents' => 400,
             'classification' => 'equity',  'flow_category' => 'unit_issue_d'],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => 400,
             'classification' => 'asset',   'flow_category' => 'unit_issue_d'],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => 400,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STC-OPERATING',       'type' => 'debit',  'amount_cents' => 200,
             'classification' => 'asset',   'flow_category' => 'donation_direct_c'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => 200,
             'classification' => 'asset',   'flow_category' => 'unit_issue_d'],
        ];
    }

    /**
     * P-Class Stage 1 — donor payment $4.00.
     * Spec §4.1 AMENDED: $1 goes to P-CLASS-SUSPENSE (not Partners Pool)
     * until recipient is allocated (invariant I11 compliance).
     */
    public static function buildPClassStage1Entries(int $donorMemberId): array
    {
        return [
            ['account_key' => "MEMBER-{$donorMemberId}", 'type' => 'credit', 'amount_cents' => 400,
             'classification' => 'equity',  'flow_category' => 'unit_issue_p_stage1'],
            ['account_key' => 'STA-OPERATING',           'type' => 'debit',  'amount_cents' => 400,
             'classification' => 'asset',   'flow_category' => 'unit_issue_p_stage1'],
            ['account_key' => 'STA-OPERATING',           'type' => 'credit', 'amount_cents' => 400,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STA-ADMIN-FUND',          'type' => 'debit',  'amount_cents' => 300,
             'classification' => 'asset',   'flow_category' => 'payment_to_admin'],
            ['account_key' => 'P-CLASS-SUSPENSE',        'type' => 'debit',  'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'unit_issue_p_stage1'],
        ];
    }

    /**
     * P-Class Stage 2 — allocation to recipient.
     * Spec §4.1 NEW: releases $1 from P-CLASS-SUSPENSE → STA-PARTNERS-POOL
     * with Beneficial Unit activating on recipient (I11 satisfied).
     */
    public static function buildPClassStage2Entries(int $recipientMemberId): array
    {
        return [
            ['account_key' => 'P-CLASS-SUSPENSE',         'type' => 'credit', 'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'p_class_allocation'],
            ['account_key' => 'STA-PARTNERS-POOL',        'type' => 'debit',  'amount_cents' => 100,
             'classification' => 'asset',   'flow_category' => 'p_class_allocation'],
            // Recipient gets the equity claim — record MEMBER credit (+Beneficial Unit)
            // balanced against a STA-OPERATING debit/credit offset to keep sum=0.
            // Rationale: the Beneficial Unit is a new equity claim on existing pool value.
            ['account_key' => "MEMBER-{$recipientMemberId}", 'type' => 'credit', 'amount_cents' => 100,
             'classification' => 'equity',  'flow_category' => 'p_class_allocation'],
            ['account_key' => 'STA-OPERATING',            'type' => 'debit',  'amount_cents' => 100,
             'classification' => 'equity',  'flow_category' => 'p_class_allocation'],
        ];
    }

    /**
     * Tier 2 unit issues (A, Lh, BP, R) — full proceeds to Partners Pool.
     * $amountCents = 400 for S-pathway, 4000 for B-pathway.
     */
    public static function buildTier2Entries(int $memberId, int $amountCents, string $classCategory): array
    {
        return [
            ['account_key' => "MEMBER-{$memberId}",  'type' => 'credit', 'amount_cents' => $amountCents,
             'classification' => 'equity',  'flow_category' => $classCategory],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => $amountCents,
             'classification' => 'asset',   'flow_category' => $classCategory],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => $amountCents,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => $amountCents,
             'classification' => 'asset',   'flow_category' => $classCategory],
        ];
    }

    /**
     * Stripe processing fee — paid out of Admin Fund.
     * $feeCents includes GST. $gstCents is the ITC-recoverable portion.
     */
    public static function buildStripeFeeEntries(int $feeCents, int $gstCents): array
    {
        $netFee = $feeCents - $gstCents;
        $entries = [
            ['account_key' => 'STA-ADMIN-FUND',      'type' => 'credit', 'amount_cents' => $feeCents,
             'classification' => 'asset',   'flow_category' => 'stripe_fee'],
            ['account_key' => 'EXTERNAL-VENDOR',     'type' => 'debit',  'amount_cents' => $netFee,
             'classification' => 'expense', 'flow_category' => 'stripe_fee'],
        ];
        if ($gstCents > 0) {
            $entries[] = ['account_key' => 'EXTERNAL-ATO', 'type' => 'debit', 'amount_cents' => $gstCents,
                          'classification' => 'asset',   'flow_category' => 'gst_itc'];
        }
        return $entries;
    }

    /**
     * Operating expense paid from Admin Fund.
     */
    public static function buildOperatingExpenseEntries(int $amountCents, int $gstCents = 0): array
    {
        $netCost = $amountCents - $gstCents;
        $entries = [
            ['account_key' => 'STA-ADMIN-FUND',  'type' => 'credit', 'amount_cents' => $amountCents,
             'classification' => 'asset',   'flow_category' => 'operating_expense'],
            ['account_key' => 'EXTERNAL-VENDOR', 'type' => 'debit',  'amount_cents' => $netCost,
             'classification' => 'expense', 'flow_category' => 'operating_expense'],
        ];
        if ($gstCents > 0) {
            $entries[] = ['account_key' => 'EXTERNAL-ATO', 'type' => 'debit', 'amount_cents' => $gstCents,
                          'classification' => 'asset',   'flow_category' => 'gst_itc'];
        }
        return $entries;
    }

    /**
     * Interest income received into STA-OPERATING.
     * Net cash debit to STA-OPERATING; credit to STA-PARTNERS-POOL as retained income.
     * If withholding applies, debit EXTERNAL-ATO and credit STA-PARTNERS-POOL for gross.
     */
    public static function buildInterestIncomeEntries(int $netCents, int $withholdingCents = 0): array
    {
        $grossCents = $netCents + $withholdingCents;
        $entries = [
            ['account_key' => 'STA-OPERATING',     'type' => 'debit',  'amount_cents' => $netCents,
             'classification' => 'asset',  'flow_category' => 'interest_income'],
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'credit', 'amount_cents' => $grossCents,
             'classification' => 'equity', 'flow_category' => 'interest_income'],
        ];
        if ($withholdingCents > 0) {
            $entries[] = ['account_key' => 'EXTERNAL-ATO', 'type' => 'debit', 'amount_cents' => $withholdingCents,
                          'classification' => 'asset',  'flow_category' => 'tax_withholding'];
        }
        return $entries;
    }

    /**
     * General / other income received into STA-OPERATING.
     * Same double-entry pattern as interest — net debit to Operating, credit to Partners Pool.
     */
    public static function buildGeneralIncomeEntries(int $netCents, int $withholdingCents = 0): array
    {
        $grossCents = $netCents + $withholdingCents;
        $entries = [
            ['account_key' => 'STA-OPERATING',     'type' => 'debit',  'amount_cents' => $netCents,
             'classification' => 'asset',  'flow_category' => 'other_income'],
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'credit', 'amount_cents' => $grossCents,
             'classification' => 'equity', 'flow_category' => 'other_income'],
        ];
        if ($withholdingCents > 0) {
            $entries[] = ['account_key' => 'EXTERNAL-ATO', 'type' => 'debit', 'amount_cents' => $withholdingCents,
                          'classification' => 'asset',  'flow_category' => 'tax_withholding'];
        }
        return $entries;
    }

    /**
     * Franking credit receivable — recorded when a franked dividend is received.
     * Franking credits are a tax offset receivable from the ATO equal to the
     * corporate tax already paid on the dividend (typically 30% of gross).
     * Recorded as: EXTERNAL-ATO debit (asset receivable) | STA-OPERATING credit
     * (reduces net income already recorded at gross).
     * SubTrustA Deed cl.16.2 — statutory trust record must reflect all income.
     *
     * @param int $frankingCents  Franking credit amount in cents
     */
    public static function buildFrankingCreditEntries(int $frankingCents): array
    {
        return [
            ['account_key' => 'EXTERNAL-ATO',     'type' => 'debit',  'amount_cents' => $frankingCents,
             'classification' => 'asset',   'flow_category' => 'franking_credit'],
            ['account_key' => 'STA-OPERATING',    'type' => 'credit', 'amount_cents' => $frankingCents,
             'classification' => 'income',  'flow_category' => 'franking_credit'],
        ];
    }

    /**
     * BDS dividend receipt — Beneficiary Distribution Stream (cl.31.1(a)).
     * 50% retained in STA-PARTNERS-POOL, 50% to STB-OPERATING.
     * Residual cent (odd dividends) accumulates to STA-PARTNERS-POOL per amended I2.
     */
    public static function buildBDSDividendEntries(int $dividendCents): array
    {
        $stbShare = intdiv($dividendCents, 2);
        $stapShare = $dividendCents - $stbShare;  // residual cent → Partners Pool

        return [
            ['account_key' => 'EXTERNAL-ASX',        'type' => 'credit', 'amount_cents' => $dividendCents,
             'classification' => 'income',  'flow_category' => 'dividend_receipt'],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => $dividendCents,
             'classification' => 'asset',   'flow_category' => 'dividend_receipt'],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => $dividendCents,
             'classification' => 'asset',   'flow_category' => 'bds_split'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => $stapShare,
             'classification' => 'asset',   'flow_category' => 'bds_split'],
            ['account_key' => 'STB-OPERATING',       'type' => 'debit',  'amount_cents' => $stbShare,
             'classification' => 'asset',   'flow_category' => 'bds_split'],
        ];
    }

    /**
     * DDS dividend receipt — Donation Dividend Stream (cl.31.1(b)).
     * 50% STB | 25% STA-PARTNERS-POOL reinvest | 25% TRUSTEE-ADMIN.
     * Residual cents accumulate to STA-PARTNERS-POOL per amended I2.
     */
    public static function buildDDSDividendEntries(int $dividendCents): array
    {
        $stbShare     = intdiv($dividendCents, 2);      // 50%
        $trusteeShare = intdiv($dividendCents, 4);      // 25%
        $stapShare    = $dividendCents - $stbShare - $trusteeShare;  // 25% + residuals

        return [
            ['account_key' => 'EXTERNAL-ASX',        'type' => 'credit', 'amount_cents' => $dividendCents,
             'classification' => 'income',  'flow_category' => 'dividend_receipt'],
            ['account_key' => 'STA-OPERATING',       'type' => 'debit',  'amount_cents' => $dividendCents,
             'classification' => 'asset',   'flow_category' => 'dividend_receipt'],
            ['account_key' => 'STA-OPERATING',       'type' => 'credit', 'amount_cents' => $dividendCents,
             'classification' => 'asset',   'flow_category' => 'dds_split'],
            ['account_key' => 'STB-OPERATING',       'type' => 'debit',  'amount_cents' => $stbShare,
             'classification' => 'asset',   'flow_category' => 'dds_split'],
            ['account_key' => 'STA-PARTNERS-POOL',   'type' => 'debit',  'amount_cents' => $stapShare,
             'classification' => 'asset',   'flow_category' => 'dds_split'],
            ['account_key' => 'TRUSTEE-ADMIN',       'type' => 'debit',  'amount_cents' => $trusteeShare,
             'classification' => 'asset',   'flow_category' => 'dds_split'],
        ];
    }

    /**
     * Sub-Trust B proportional distribution — pays members and STC.
     * @param array $distributions [['account_key' => 'MEMBER-1', 'amount_cents' => 123], ...]
     */
    public static function buildSTBDistributionEntries(array $distributions): array
    {
        $totalCents = array_sum(array_column($distributions, 'amount_cents'));
        $entries = [
            ['account_key' => 'STB-OPERATING', 'type' => 'credit', 'amount_cents' => $totalCents,
             'classification' => 'asset',   'flow_category' => 'stb_distribution'],
        ];
        foreach ($distributions as $d) {
            $entries[] = [
                'account_key' => $d['account_key'],
                'type' => 'debit',
                'amount_cents' => $d['amount_cents'],
                'classification' => 'equity',
                'flow_category' => 'stb_distribution',
            ];
        }
        return $entries;
    }

    /**
     * Grant disbursement from Sub-Trust C.
     */
    public static function buildGrantEntries(int $grantCents, string $granteeKey = 'EXTERNAL-GRANTEE'): array
    {
        return [
            ['account_key' => 'STC-OPERATING', 'type' => 'credit', 'amount_cents' => $grantCents,
             'classification' => 'asset',   'flow_category' => 'grant_disbursement'],
            ['account_key' => $granteeKey,    'type' => 'debit',  'amount_cents' => $grantCents,
             'classification' => 'expense', 'flow_category' => 'grant_disbursement'],
        ];
    }

    /**
     * ASX share acquisition from Partners Pool cash.
     * Modelled within STA-PARTNERS-POOL as a classification change (asset composition).
     * For now, represented as a single-pool movement; if brokerage is paid, include
     * the brokerage pair separately (via buildOperatingExpenseEntries).
     */
    public static function buildASXAcquisitionEntries(int $costCents): array
    {
        return [
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'credit', 'amount_cents' => $costCents,
             'classification' => 'asset',   'flow_category' => 'asx_acquisition'],
            ['account_key' => 'EXTERNAL-ASX',     'type' => 'debit',  'amount_cents' => $costCents,
             'classification' => 'asset',   'flow_category' => 'asx_acquisition'],
            // Partners Pool receives the share asset equal in value to the cash spent
            ['account_key' => 'EXTERNAL-ASX',     'type' => 'credit', 'amount_cents' => $costCents,
             'classification' => 'asset',   'flow_category' => 'asx_acquisition'],
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'debit', 'amount_cents' => $costCents,
             'classification' => 'asset',   'flow_category' => 'asx_acquisition'],
        ];
    }

    /**
     * Trustee fee paid from the 25% DDS trustee admin pool.
     */
    public static function buildTrusteeFeeEntries(int $feeCents): array
    {
        return [
            ['account_key' => 'TRUSTEE-ADMIN', 'type' => 'credit', 'amount_cents' => $feeCents,
             'classification' => 'asset',   'flow_category' => 'trustee_fee'],
            ['account_key' => 'EXTERNAL-VENDOR','type' => 'debit', 'amount_cents' => $feeCents,
             'classification' => 'expense', 'flow_category' => 'trustee_fee'],
        ];
    }

    /**
     * Members Bonus Election — Schedule S4.2A.
     * Partner elects to reinvest their STB distribution back into Class A Units.
     * STB-OPERATING → STA-OPERATING (new unit consideration) → STA-PARTNERS-POOL.
     * The new A-Class unit carry a fresh 12-month Stewardship Season lock (I12).
     */
    public static function buildMembersBonusElectionEntries(int $electionCents, int $recipientMemberId): array
    {
        return [
            // STB releases the distribution amount
            ['account_key' => 'STB-OPERATING',     'type' => 'credit', 'amount_cents' => $electionCents,
             'classification' => 'asset',   'flow_category' => 'bonus_election_netting'],
            // STA-OPERATING receives as new unit consideration
            ['account_key' => 'STA-OPERATING',     'type' => 'debit',  'amount_cents' => $electionCents,
             'classification' => 'asset',   'flow_category' => 'bonus_election_netting'],
            // Transit to Partners Pool (100% to investment — Tier 2 A-class no admin cut)
            ['account_key' => 'STA-OPERATING',     'type' => 'credit', 'amount_cents' => $electionCents,
             'classification' => 'asset',   'flow_category' => 'sta_transit'],
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'debit',  'amount_cents' => $electionCents,
             'classification' => 'asset',   'flow_category' => 'bonus_election_netting'],
            // Member equity claim created for the new A-class unit
            ['account_key' => "MEMBER-{$recipientMemberId}", 'type' => 'credit', 'amount_cents' => $electionCents,
             'classification' => 'equity',  'flow_category' => 'bonus_election_netting'],
        ];
    }

    /**
     * Gift received via DGR pathway — SubTrustC cl.11.
     * External donor contributes to STC-GIFT-FUND (segregated DGR account).
     */
    public static function buildGiftReceivedEntries(int $giftCents): array
    {
        return [
            ['account_key' => 'EXTERNAL-VENDOR', 'type' => 'credit', 'amount_cents' => $giftCents,
             'classification' => 'income',  'flow_category' => 'gift_received'],
            ['account_key' => 'STC-GIFT-FUND',   'type' => 'debit',  'amount_cents' => $giftCents,
             'classification' => 'asset',   'flow_category' => 'gift_received'],
        ];
    }

    /**
     * Asset swap — Holding X → Holding Y within Partners Pool (amended I6).
     * Disposal proceeds and acquisition costs remain inside STA-PARTNERS-POOL.
     * Brokerage is recorded separately via buildOperatingExpenseEntries.
     *
     * @param int $disposalCents  Net disposal proceeds (after brokerage deducted)
     * @param int $acquisitionCents  Cost of new holding (net, matching disposal net)
     */
    public static function buildAssetSwapEntries(int $disposalCents, int $acquisitionCents): array
    {
        return [
            // Disposal leg: Partners Pool → EXTERNAL-ASX (market counterparty)
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'credit', 'amount_cents' => $disposalCents,
             'classification' => 'asset',   'flow_category' => 'asset_swap'],
            ['account_key' => 'EXTERNAL-ASX',       'type' => 'debit',  'amount_cents' => $disposalCents,
             'classification' => 'asset',   'flow_category' => 'asset_swap'],
            // Acquisition leg: EXTERNAL-ASX → Partners Pool
            ['account_key' => 'EXTERNAL-ASX',       'type' => 'credit', 'amount_cents' => $acquisitionCents,
             'classification' => 'asset',   'flow_category' => 'asset_swap'],
            ['account_key' => 'STA-PARTNERS-POOL', 'type' => 'debit',  'amount_cents' => $acquisitionCents,
             'classification' => 'asset',   'flow_category' => 'asset_swap'],
        ];
    }

    // ========================================================================
    // INTERNAL HELPERS
    // ========================================================================

    /**
     * Validate entries array.
     * @return string|null  Error message, or null if valid.
     */
    private static function validate(array $entries, string $sourceTable): ?string
    {
        if (!in_array($sourceTable, self::SOURCES, true)) {
            return "Invalid source_table: {$sourceTable}";
        }
        if (count($entries) < 2) {
            return 'At least 2 entries required for double-entry';
        }

        $delta = 0;
        foreach ($entries as $i => $e) {
            if (!isset($e['account_key'], $e['type'], $e['amount_cents'], $e['classification'])) {
                return "Entry {$i}: missing required field (account_key, type, amount_cents, classification)";
            }
            if (!in_array($e['type'], ['debit', 'credit'], true)) {
                return "Entry {$i}: type must be 'debit' or 'credit'";
            }
            if (!in_array($e['classification'], self::CLASSIFICATIONS, true)) {
                return "Entry {$i}: invalid classification '{$e['classification']}'";
            }
            if (!is_int($e['amount_cents']) || $e['amount_cents'] < 1) {
                return "Entry {$i}: amount_cents must be positive integer";
            }
            $delta += ($e['type'] === 'debit') ? $e['amount_cents'] : -$e['amount_cents'];
        }

        if ($delta !== 0) {
            return "Unbalanced entries: delta={$delta}c (must be 0)";
        }
        return null;
    }

    /** Check whether a transaction_ref already has entries recorded. */
    private static function refExists(PDO $pdo, string $ref): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ledger_entries WHERE transaction_ref = ?");
        $stmt->execute([$ref]);
        return (int) $stmt->fetchColumn();
    }

    /** Create a MEMBER-{id} sector row on first reference. */
    private static function createMemberSector(PDO $pdo, int $memberId, string $accountKey): ?int
    {
        $stmt = $pdo->prepare("
            SELECT member_number, COALESCE(full_name, CONCAT(first_name, ' ', last_name)) AS name
            FROM members WHERE id = ?
        ");
        $stmt->execute([$memberId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) return null;

        $display = 'Partner — ' . ($m['name'] ?: $m['member_number']);
        $ins = $pdo->prepare("
            INSERT INTO stewardship_accounts (partner_id, account_key, account_type, display_name, status)
            VALUES (?, ?, 'partner', ?, 'active')
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $ins->execute([$memberId, $accountKey, $display]);
        $id = (int) $pdo->lastInsertId();
        self::$accountIdCache[$accountKey] = $id;
        return $id;
    }

    /** Create a DONOR-{id} sector row on first reference. */
    private static function createDonorSector(PDO $pdo, int $donorId, string $accountKey): ?int
    {
        // Donors may be existing members (D Class purchase) or one-off external donors.
        // Default: link to members.id if present; otherwise use the id as a logical donor ref.
        $stmt = $pdo->prepare("SELECT id FROM members WHERE id = ?");
        $stmt->execute([$donorId]);
        $isMember = $stmt->fetchColumn() !== false;

        $display = $isMember ? 'Donor (Member-linked) #' . $donorId : 'Donor #' . $donorId;

        $ins = $pdo->prepare("
            INSERT INTO stewardship_accounts (partner_id, account_key, account_type, display_name, status)
            VALUES (?, ?, 'donor', ?, 'active')
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $ins->execute([$isMember ? $donorId : null, $accountKey, $display]);
        $id = (int) $pdo->lastInsertId();
        self::$accountIdCache[$accountKey] = $id;
        return $id;
    }
}

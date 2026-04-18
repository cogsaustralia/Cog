<?php
declare(strict_types=1);

if (!function_exists('env')) {
    require_once dirname(__DIR__) . '/config/bootstrap.php';
}

/**
 * MedicareKycAgent
 *
 * Handles the full manual Medicare card KYC lifecycle:
 *   1. Accept and encrypt member submission
 *   2. Generate evidence hash (never touches plain card number after step 1)
 *   3. Admin review helpers — decrypt for display, approve, reject
 *   4. Attestation creation on approval → evidence_vault_entries
 *   5. Besu bridge stub — ready to connect on Investment Day
 *
 * Encryption: AES-256-CBC via KYC_ENCRYPTION_KEY in .env
 * Storage:    Encrypted blobs in kyc_medicare_submissions
 * Retention:  7 years per AML/CTF Act 2006 s.28
 * Privacy:    Raw card number never persisted; encrypted form only
 */
class MedicareKycAgent
{
    private PDO    $db;
    private string $encKey;   // 32-byte key from env
    private string $encIv;    // 16-byte IV seed from env

    public function __construct(PDO $db)
    {
        $this->db     = $db;
        $rawKey       = (string)(env('KYC_ENCRYPTION_KEY', ''));
        $rawIv        = (string)(env('KYC_ENCRYPTION_IV',  ''));

        if (strlen($rawKey) < 32 || strlen($rawIv) < 16) {
            throw new RuntimeException(
                'KYC_ENCRYPTION_KEY (32+ chars) and KYC_ENCRYPTION_IV (16+ chars) ' .
                'must be set in .env before using MedicareKycAgent.'
            );
        }

        $this->encKey = substr($rawKey, 0, 32);
        $this->encIv  = substr($rawIv,  0, 16);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — SUBMIT
    // Called from vault route when member submits Medicare card details.
    // Returns the new submission ID.
    // ════════════════════════════════════════════════════════════════════════

    public function submit(
        int    $memberId,
        string $memberNumber,
        string $medicareCardName,   // Full name on card
        string $medicareNumber,     // 10-digit number
        string $medicareIrn,        // Individual Reference Number (1–9)
        string $medicareExpiry,     // MM/YYYY
        string $purpose             = 'guardian_ksnft',
        ?int   $kidsRegistrationId  = null,
        string $declarationIp       = ''
    ): int {

        // Validate Medicare number format
        $cleanNumber = preg_replace('/\D/', '', $medicareNumber);
        if (strlen($cleanNumber) !== 10) {
            throw new InvalidArgumentException('Medicare number must be 10 digits.');
        }
        $cleanIrn = trim($medicareIrn);
        if (!preg_match('/^[1-9]$/', $cleanIrn)) {
            throw new InvalidArgumentException('IRN must be a single digit 1–9.');
        }

        // Parse expiry
        if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $medicareExpiry, $m)) {
            throw new InvalidArgumentException('Expiry must be MM/YYYY.');
        }
        $expiryMonth = $m[1];
        $expiryYear  = $m[2];

        // Mark any previous pending submissions for this member as superseded
        $this->db->prepare(
            "UPDATE kyc_medicare_submissions
             SET status = 'superseded', updated_at = UTC_TIMESTAMP()
             WHERE member_id = ? AND status IN ('pending','under_review')"
        )->execute([$memberId]);

        // Encrypt sensitive fields
        $nameEnc   = $this->encrypt(trim($medicareCardName));
        $numEnc    = $this->encrypt($cleanNumber);
        $irnEnc    = $this->encrypt($cleanIrn);
        $expiryEnc = $this->encrypt($medicareExpiry);

        // Safe reference fields (non-sensitive)
        $nameInitial = strtoupper(substr(trim($medicareCardName), 0, 1));
        $numLast4    = substr($cleanNumber, -4);

        // Evidence hash — SHA-256 of canonical record
        // Computed BEFORE data is stored so it can be independently audited
        $now           = gmdate('Y-m-d\TH:i:s\Z');
        $evidenceInput = implode('|', [
            'COGS_KYC_MEDICARE_V1',
            $memberNumber,
            strtoupper(trim($medicareCardName)),
            $cleanNumber,
            $cleanIrn,
            $medicareExpiry,
            $now,
        ]);
        $evidenceHash = hash('sha256', $evidenceInput);

        // Retention expiry = 7 years from now (AML/CTF s.28)
        $retentionExpiry = gmdate('Y-m-d H:i:s', strtotime('+7 years'));

        // Declaration text (verbatim — stored for compliance)
        $declarationText = 'I declare that the Medicare card details I have provided belong to me '
            . 'and are accurate. I consent to the COGS Australia Foundation storing and using '
            . 'these details for the purpose of identity verification in accordance with '
            . 'the Privacy Act 1988 and the AML/CTF Act 2006.';

        $this->db->prepare("
            INSERT INTO kyc_medicare_submissions
                (member_id, member_number, purpose, kids_registration_id,
                 medicare_name_enc, medicare_number_enc, medicare_irn_enc, medicare_expiry_enc,
                 medicare_name_initial, medicare_number_last4,
                 medicare_expiry_month, medicare_expiry_year,
                 declaration_accepted, declaration_accepted_at, declaration_ip, declaration_text,
                 status, evidence_hash, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?, ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ")->execute([
            $memberId, $memberNumber, $purpose, $kidsRegistrationId,
            $nameEnc, $numEnc, $irnEnc, $expiryEnc,
            $nameInitial, $numLast4,
            $expiryMonth, $expiryYear,
            $declarationIp, $declarationText,
            $evidenceHash,
            $retentionExpiry,
        ]);

        $submissionId = (int)$this->db->lastInsertId();

        // Log submission event
        $this->log($submissionId, $memberNumber, null, 'submitted',
            "Medicare KYC submitted. Purpose: {$purpose}. Evidence: {$evidenceHash}",
            $declarationIp
        );

        // Update member KYC status and link submission ID
        $this->db->prepare(
            "UPDATE snft_memberships SET kyc_status = 'pending', kyc_submission_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$submissionId, $memberId]);

        return $submissionId;
    }


    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — ADMIN REVIEW: OPEN
    // Admin opens a submission — marks it under_review
    // ════════════════════════════════════════════════════════════════════════

    public function openForReview(int $submissionId, int $adminId): array
    {
        $row = $this->getSubmission($submissionId);
        if (!$row) throw new RuntimeException("Submission #{$submissionId} not found.");

        if ($row['status'] !== 'pending') {
            throw new RuntimeException("Submission is already {$row['status']}.");
        }

        $this->db->prepare(
            "UPDATE kyc_medicare_submissions
             SET status = 'under_review', reviewed_by_admin_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?"
        )->execute([$adminId, $submissionId]);

        $this->log($submissionId, $row['member_number'], $adminId, 'opened');

        // Return decrypted data for admin display — only during active review
        return $this->decryptForReview($row);
    }


    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — ADMIN REVIEW: APPROVE
    // Approves identity, creates evidence vault entry, updates member status
    // ════════════════════════════════════════════════════════════════════════

    public function approve(int $submissionId, int $adminId, string $notes = ''): void
    {
        $row = $this->getSubmission($submissionId);
        if (!$row) throw new RuntimeException("Submission #{$submissionId} not found.");
        if (!in_array($row['status'], ['pending','under_review'], true)) {
            throw new RuntimeException("Cannot approve submission with status: {$row['status']}");
        }

        $this->db->beginTransaction();
        try {
            // Mark submission verified
            $this->db->prepare("
                UPDATE kyc_medicare_submissions
                SET status = 'verified',
                    reviewed_by_admin_id = ?,
                    reviewed_at = UTC_TIMESTAMP(),
                    verified_at = UTC_TIMESTAMP(),
                    review_notes = ?,
                    updated_at  = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$adminId, $notes ?: null, $submissionId]);

            // Write evidence vault entry
            $vaultId = $this->writeEvidenceVault($row, $adminId);

            // Link vault entry back to submission
            $this->db->prepare(
                "UPDATE kyc_medicare_submissions SET evidence_vault_id = ? WHERE id = ?"
            )->execute([$vaultId, $submissionId]);

            // Update member KYC status
            $this->db->prepare("
                UPDATE snft_memberships
                SET kyc_status         = 'verified',
                    kyc_method         = 'medicare_manual',
                    kyc_verified_at    = UTC_TIMESTAMP(),
                    kyc_submission_id  = ?,
                    updated_at         = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$submissionId, $row['member_id']]);

            // If this is a guardian_ksnft submission, update the kids registration too
            if ($row['purpose'] === 'guardian_ksnft' && $row['kids_registration_id']) {
                $this->db->prepare("
                    UPDATE kids_token_registrations
                    SET kyc_status = 'verified',
                        kyc_submission_id = ?,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ")->execute([$submissionId, $row['kids_registration_id']]);
            }

            // Log
            $this->log($submissionId, $row['member_number'], $adminId, 'attestation_created',
                "Evidence vault entry #{$vaultId} created. Hash: {$row['evidence_hash']}"
            );
            $this->log($submissionId, $row['member_number'], $adminId, 'approved', $notes);

            // Besu bridge stub — queue for Investment Day
            $this->queueBesuAttestation($submissionId, $row);

            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }


    // ════════════════════════════════════════════════════════════════════════
    // STEP 3b — ADMIN REVIEW: REJECT
    // ════════════════════════════════════════════════════════════════════════

    public function reject(int $submissionId, int $adminId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }

        $row = $this->getSubmission($submissionId);
        if (!$row) throw new RuntimeException("Submission #{$submissionId} not found.");

        $this->db->prepare("
            UPDATE kyc_medicare_submissions
            SET status = 'rejected',
                rejection_reason = ?,
                reviewed_by_admin_id = ?,
                reviewed_at = UTC_TIMESTAMP(),
                updated_at  = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([$reason, $adminId, $submissionId]);

        // Update member status
        $this->db->prepare(
            "UPDATE snft_memberships SET kyc_status = 'rejected', updated_at = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$row['member_id']]);

        $this->log($submissionId, $row['member_number'], $adminId, 'rejected', $reason);
    }


    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — BESU BRIDGE STUB
    // Called on approval. Queues attestation for on-chain anchoring.
    // On Investment Day: replace body with real Besu RPC call.
    // ════════════════════════════════════════════════════════════════════════

    private function queueBesuAttestation(int $submissionId, array $row): void
    {
        // Compute Keccak-256 compatible hash of the attestation payload
        // In production this will be: eth_keccak256(abi.encodePacked(fields))
        // For now: SHA-256 of the same canonical evidence string
        $attestationHash = '0x' . hash('sha256', implode('|', [
            'COGS_BESU_ATTESTATION_V1',
            $row['member_number'],
            $row['evidence_hash'],
            gmdate('Y-m-d\TH:i:s\Z'),
        ]));

        $this->db->prepare(
            "UPDATE kyc_medicare_submissions
             SET besu_attestation_hash = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?"
        )->execute([$attestationHash, $submissionId]);

        // Log what will be written to chain
        error_log(sprintf(
            '[BesuStub] Attestation queued for member %s — hash %s — ' .
            'will be written to Besu registry on Investment Day.',
            $row['member_number'],
            $attestationHash
        ));

        // TODO (Investment Day): 
        // 1. Call Besu RPC: eth_sendRawTransaction with signed attestation
        // 2. Store tx_hash and block_number in kyc_medicare_submissions
        // 3. Update log with action 'besu_anchored'
    }


    // ════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Write an evidence vault entry for the approved KYC submission.
     * Returns the new evidence_vault_entries.id
     */
    private function writeEvidenceVault(array $row, int $adminId): int
    {
        $summary = sprintf(
            'Medicare card KYC verified for member %s. ' .
            'Name initial: %s. Card last 4: %s. Expiry: %s/%s. ' .
            'Purpose: %s.',
            $row['member_number'],
            $row['medicare_name_initial'] ?? '?',
            $row['medicare_number_last4'] ?? '????',
            $row['medicare_expiry_month'] ?? '??',
            $row['medicare_expiry_year']  ?? '????',
            $row['purpose']
        );

        $this->db->prepare("
            INSERT INTO evidence_vault_entries
                (entry_type, subject_type, subject_id, subject_ref,
                 payload_hash, payload_summary,
                 source_system, created_by_type, created_by_id, created_at)
            VALUES ('kyc_medicare_verified', 'snft_member', ?, ?, ?, ?,
                    'kyc_medicare_agent', 'admin', ?, UTC_TIMESTAMP())
        ")->execute([
            $row['member_id'],
            $row['member_number'],
            $row['evidence_hash'],
            $summary,
            $adminId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /** Append an immutable log entry */
    private function log(
        int $submissionId, string $memberNumber,
        ?int $adminId, string $action,
        string $note = '', string $ip = ''
    ): void {
        try {
            $this->db->prepare("
                INSERT INTO kyc_review_log
                    (submission_id, member_number, admin_id, action, note, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ")->execute([
                $submissionId, $memberNumber, $adminId ?: null,
                $action, $note ?: null, $ip ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('[KYC] log insert failed: ' . $e->getMessage());
        }
    }

    /** Decrypt a submission row for admin display — only call during active review */
    public function decryptForReview(array $row): array
    {
        return array_merge($row, [
            'medicare_name'   => $this->decrypt((string)($row['medicare_name_enc']   ?? '')),
            'medicare_number' => $this->decrypt((string)($row['medicare_number_enc'] ?? '')),
            'medicare_irn'    => $this->decrypt((string)($row['medicare_irn_enc']    ?? '')),
            'medicare_expiry' => $this->decrypt((string)($row['medicare_expiry_enc'] ?? '')),
        ]);
    }

    /** Get a single submission row */
    public function getSubmission(int $id): ?array
    {
        $s = $this->db->prepare(
            "SELECT s.*, m.full_name AS member_name, m.email AS member_email
             FROM kyc_medicare_submissions s
             LEFT JOIN snft_memberships m ON m.id = s.member_id
             WHERE s.id = ?
             LIMIT 1"
        );
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Get all submissions for a member */
    public function getMemberSubmissions(int $memberId): array
    {
        $s = $this->db->prepare(
            'SELECT * FROM kyc_medicare_submissions WHERE member_id = ? ORDER BY created_at DESC'
        );
        $s->execute([$memberId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Get pending + under_review submissions for admin queue */
    public function getPendingQueue(): array
    {
        return $this->db->query(
            "SELECT s.*, m.full_name AS member_name, m.email AS member_email
             FROM kyc_medicare_submissions s
             LEFT JOIN snft_memberships m ON m.id = s.member_id
             WHERE s.status IN ('pending','under_review')
             ORDER BY s.created_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Mark expired submissions (>30 days pending without review) */
    public function markExpired(): int
    {
        $s = $this->db->prepare(
            "UPDATE kyc_medicare_submissions
             SET status = 'expired', updated_at = UTC_TIMESTAMP()
             WHERE status IN ('pending','under_review')
               AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
        );
        $s->execute();
        return $s->rowCount();
    }

    // ════════════════════════════════════════════════════════════════════════
    // ENCRYPTION / DECRYPTION
    // AES-256-CBC — key and IV from environment, never from DB
    // ════════════════════════════════════════════════════════════════════════

    private function encrypt(string $plaintext): string
    {
        if ($plaintext === '') return '';
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $this->encKey, 0, $this->encIv);
        if ($encrypted === false) {
            throw new RuntimeException('KYC field encryption failed.');
        }
        return $encrypted;
    }

    private function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') return '';
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->encKey, 0, $this->encIv);
        if ($decrypted === false) {
            error_log('[KYC] Decryption failed — check KYC_ENCRYPTION_KEY/IV match.');
            return '[decryption failed]';
        }
        return $decrypted;
    }
}

<?php
declare(strict_types=1);

/**
 * DeclarationExecutionService
 *
 * Handles the two-capacity electronic execution of the CJVM Hybrid Trust
 * Declaration as a deed under the Electronic Transactions Act 1999 (Cth)
 * and section 14G of the Electronic Transactions Act 2000 (NSW).
 *
 * Execution flow:
 *   1. Thomas completes Declarant capacity  → status = executor_complete
 *   2. Thomas completes Caretaker Trustee capacity → status = executor_complete
 *   3. Witness token generated and sent to Alex
 *   4. Alex completes attestation → both records flipped to fully_executed
 *
 * All records are immutable after generation.
 */
class DeclarationExecutionService
{
    public const DEED_KEY       = 'declaration_v15_1';
    public const DEED_TITLE     = 'COGS OF AUSTRALIA FOUNDATION HYBRID TRUST DECLARATION';
    public const DEED_VERSION   = 'v15.1';
    public const DEED_PDF       = 'CJVM_Hybrid_Trust_Declaration.pdf';
    public const EXECUTION_DATE = '2026-04-21';

    public const EXECUTOR_NAME    = 'Thomas Boyd Cunliffe';
    public const EXECUTOR_ADDRESS = '780 Sugarbag Road West, DRAKE 2469 NSW';

    public const WITNESS_NAME       = 'Alexander Stefan Gorshenin';
    public const WITNESS_DOB        = '1979-05-16';
    public const WITNESS_ADDRESS    = '1/118 Ridgeway Ave, Southport QLD 4215';
    public const WITNESS_OCCUPATION = 'Independent witness';

    public const ATTESTATION_METHOD =
        'Electronic attestation via audio-visual link — section 14G Electronic Transactions Act 2000 (NSW)';

    // Acknowledgement texts stored verbatim in each record
    public const DECLARANT_ACKNOWLEDGEMENT =
        "I, Thomas Boyd Cunliffe, execute this Declaration as Declarant. "
        . "I declare that the Initial Trust Property described in Schedule 1 is held on the trusts "
        . "of this Declaration under the law of South Australia. "
        . "I execute this instrument electronically in accordance with the Electronic Transactions Act 1999 (Cth) "
        . "and the Electronic Transactions Act 2000 (NSW). "
        . "No wet-ink signature or paper counterpart is required or produced.";

    public const TRUSTEE_ACKNOWLEDGEMENT =
        "I, Thomas Boyd Cunliffe, execute this Declaration as Caretaker Trustee of the "
        . "COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust. "
        . "I accept the office of Caretaker Trustee and undertake to hold and administer "
        . "the trust property in accordance with this Declaration and the Joint Venture Participation Agreement. "
        . "I execute this instrument electronically in accordance with the Electronic Transactions Act 1999 (Cth) "
        . "and the Electronic Transactions Act 2000 (NSW). "
        . "No wet-ink signature or paper counterpart is required or produced.";

    public const WITNESS_ATTESTATION_TEXT =
        "I, Alexander Stefan Gorshenin, attest that I observed Thomas Boyd Cunliffe "
        . "execute this Declaration electronically via audio-visual link on 21 April 2026. "
        . "I am satisfied this is the same document executed. "
        . "This attestation is given electronically under section 14G of the "
        . "Electronic Transactions Act 2000 (NSW).";

    // -------------------------------------------------------------------------
    // Public: record one execution capacity (declarant OR caretaker_trustee).
    // Must be called twice — once per capacity — before witness flow begins.
    // Returns record array. Throws on any failure.
    // -------------------------------------------------------------------------
    public static function recordExecution(
        PDO    $db,
        string $capacity,       // 'declarant' or 'caretaker_trustee'
        string $sessionId,      // shared UUIDv4 for both capacity rows
        string $deedSha256,     // SHA-256 of the deed PDF
        string $ipAddress,
        string $userAgent
    ): array {
        if (!in_array($capacity, ['declarant', 'caretaker_trustee'], true)) {
            throw new \InvalidArgumentException("Invalid capacity: {$capacity}");
        }

        // Guard: no duplicate capacity row for this session
        $existing = $db->prepare(
            'SELECT record_id FROM declaration_execution_records
             WHERE session_id = ? AND capacity = ? LIMIT 1'
        );
        $existing->execute([$sessionId, $capacity]);
        if ($existing->fetch()) {
            throw new \RuntimeException("Execution record for capacity '{$capacity}' already exists in session {$sessionId}.");
        }

        $recordId  = self::uuid4();
        $nowUtc    = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $nowDb     = gmdate('Y-m-d H:i:s');

        $ipDeviceData = json_encode([
            'ip_address'      => $ipAddress,
            'user_agent'      => $userAgent,
            'server_time_utc' => $nowDb,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ipDeviceHash = hash('sha256', $ipDeviceData);

        $ackText = $capacity === 'declarant'
            ? self::DECLARANT_ACKNOWLEDGEMENT
            : self::TRUSTEE_ACKNOWLEDGEMENT;

        $canonical = [
            'record_id'               => $recordId,
            'session_id'              => $sessionId,
            'capacity'                => $capacity,
            'executor_full_name'      => self::EXECUTOR_NAME,
            'executor_address'        => self::EXECUTOR_ADDRESS,
            'deed_key'                => self::DEED_KEY,
            'deed_version'            => self::DEED_VERSION,
            'execution_date'          => self::EXECUTION_DATE,
            'deed_sha256'             => $deedSha256,
            'execution_timestamp_utc' => $nowUtc,
            'ip_device_hash'          => $ipDeviceHash,
            'acceptance_flag_engaged' => true,
            'acknowledgement_text'    => $ackText,
        ];
        $recordSha256 = hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $db->beginTransaction();
        try {
            // Insert execution record
            $db->prepare(
                'INSERT INTO declaration_execution_records (
                    record_id, session_id, capacity,
                    executor_full_name, executor_address,
                    deed_key, deed_title, deed_version, execution_date, deed_sha256,
                    execution_timestamp_utc, ip_device_hash, ip_device_data,
                    acceptance_flag_engaged, execution_method,
                    witness_required, record_sha256, onchain_commitment_txid,
                    status, created_at
                ) VALUES (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,1,
                    \'Electronic — Electronic Transactions Act 1999 (Cth) and Electronic Transactions Act 2000 (NSW)\',
                    1,?,NULL,\'executor_complete\',?
                )'
            )->execute([
                $recordId, $sessionId, $capacity,
                self::EXECUTOR_NAME, self::EXECUTOR_ADDRESS,
                self::DEED_KEY, self::DEED_TITLE, self::DEED_VERSION,
                self::EXECUTION_DATE, $deedSha256,
                $nowUtc, $ipDeviceHash, $ipDeviceData,
                $recordSha256, $nowDb,
            ]);

            // Insert evidence vault entry (transitional on-chain)
            $db->prepare(
                'INSERT INTO evidence_vault_entries (
                    entry_type, subject_type, subject_id, subject_ref,
                    payload_hash, payload_summary, source_system,
                    chain_tx_hash, created_by_type, created_at
                ) VALUES (
                    \'declaration_execution\', \'deed\', 0, ?,
                    ?, ?, \'declaration_execution\',
                    ?, \'system\', ?
                )'
            )->execute([
                $recordId,
                $recordSha256,
                sprintf('Declaration execution — %s — capacity: %s', self::DEED_VERSION, $capacity),
                '0x' . $recordSha256,
                $nowDb,
            ]);

            $eveId = (int)$db->lastInsertId();

            $db->prepare(
                'UPDATE declaration_execution_records
                 SET onchain_commitment_txid = ? WHERE record_id = ?'
            )->execute([(string)$eveId, $recordId]);

            // Ensure deed_version_anchors row exists for this deed/session
            $dvaCheck = $db->prepare(
                'SELECT id FROM deed_version_anchors WHERE deed_key = ? LIMIT 1'
            );
            $dvaCheck->execute([self::DEED_KEY]);
            if (!$dvaCheck->fetch()) {
                $db->prepare(
                    'INSERT INTO deed_version_anchors
                     (deed_key, deed_title, deed_version, execution_date, deed_sha256, pdf_filename, session_id, created_at)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    self::DEED_KEY, self::DEED_TITLE, self::DEED_VERSION,
                    self::EXECUTION_DATE, $deedSha256,
                    self::DEED_PDF, $sessionId, $nowDb,
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        return [
            'record_id'               => $recordId,
            'session_id'              => $sessionId,
            'capacity'                => $capacity,
            'deed_sha256'             => $deedSha256,
            'record_sha256'           => $recordSha256,
            'execution_timestamp_utc' => $nowUtc,
            'onchain_commitment_txid' => (string)$eveId,
            'status'                  => 'executor_complete',
        ];
    }

    // -------------------------------------------------------------------------
    // Public: record the witness attestation.
    // Flips both execution records to fully_executed.
    // -------------------------------------------------------------------------
    public static function recordWitnessAttestation(
        PDO    $db,
        string $sessionId,
        string $deedSha256,
        string $ipAddress,
        string $userAgent
    ): array {
        // Confirm both execution records exist and are executor_complete
        $stmt = $db->prepare(
            'SELECT capacity, status FROM declaration_execution_records
             WHERE session_id = ? ORDER BY capacity'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $capacities = array_column($rows, 'capacity');
        if (!in_array('declarant', $capacities) || !in_array('caretaker_trustee', $capacities)) {
            throw new \RuntimeException('Both executor capacity records must be complete before witness attestation.');
        }

        // Guard: no duplicate attestation for this session
        $dup = $db->prepare(
            'SELECT attestation_id FROM declaration_witness_attestations WHERE session_id = ? LIMIT 1'
        );
        $dup->execute([$sessionId]);
        if ($dup->fetch()) {
            throw new \RuntimeException('Witness attestation already exists for this session.');
        }

        $attestationId = self::uuid4();
        $nowUtc        = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $nowDb         = gmdate('Y-m-d H:i:s');

        $ipDeviceData = json_encode([
            'ip_address'      => $ipAddress,
            'user_agent'      => $userAgent,
            'server_time_utc' => $nowDb,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ipDeviceHash = hash('sha256', $ipDeviceData);

        $canonical = [
            'attestation_id'            => $attestationId,
            'session_id'                => $sessionId,
            'witness_full_name'         => self::WITNESS_NAME,
            'witness_dob'               => self::WITNESS_DOB,
            'witness_address'           => self::WITNESS_ADDRESS,
            'deed_key'                  => self::DEED_KEY,
            'deed_sha256'               => $deedSha256,
            'attestation_timestamp_utc' => $nowUtc,
            'ip_device_hash'            => $ipDeviceHash,
            'attestation_flag_engaged'  => true,
            'attestation_text'          => self::WITNESS_ATTESTATION_TEXT,
        ];
        $recordSha256 = hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO declaration_witness_attestations (
                    attestation_id, session_id,
                    witness_full_name, witness_dob, witness_address, witness_occupation,
                    attestation_method, deed_key, deed_sha256,
                    attestation_timestamp_utc, ip_device_hash, ip_device_data,
                    attestation_flag_engaged, attestation_text,
                    record_sha256, onchain_commitment_txid, created_at
                ) VALUES (
                    ?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,NULL,?
                )'
            )->execute([
                $attestationId, $sessionId,
                self::WITNESS_NAME, self::WITNESS_DOB, self::WITNESS_ADDRESS,
                self::WITNESS_OCCUPATION, self::ATTESTATION_METHOD,
                self::DEED_KEY, $deedSha256,
                $nowUtc, $ipDeviceHash, $ipDeviceData,
                self::WITNESS_ATTESTATION_TEXT,
                $recordSha256, $nowDb,
            ]);

            $eveId = (int)$db->lastInsertId();

            // Evidence vault entry for witness attestation
            $db->prepare(
                'INSERT INTO evidence_vault_entries (
                    entry_type, subject_type, subject_id, subject_ref,
                    payload_hash, payload_summary, source_system,
                    chain_tx_hash, created_by_type, created_at
                ) VALUES (
                    \'witness_attestation\', \'deed\', 0, ?,
                    ?, ?, \'witness_attestation\',
                    ?, \'system\', ?
                )'
            )->execute([
                $attestationId,
                $recordSha256,
                sprintf('Witness attestation — %s — witness: %s', self::DEED_VERSION, self::WITNESS_NAME),
                '0x' . $recordSha256,
                $nowDb,
            ]);

            // Backfill onchain txid on attestation row
            $db->prepare(
                'UPDATE declaration_witness_attestations
                 SET onchain_commitment_txid = ? WHERE attestation_id = ?'
            )->execute([(string)$eveId, $attestationId]);

            // Update both execution records: link attestation, flip to fully_executed
            $db->prepare(
                'UPDATE declaration_execution_records
                 SET witness_attestation_id = ?, status = \'fully_executed\'
                 WHERE session_id = ?'
            )->execute([$attestationId, $sessionId]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        return [
            'attestation_id'            => $attestationId,
            'session_id'                => $sessionId,
            'deed_sha256'               => $deedSha256,
            'attestation_timestamp_utc' => $nowUtc,
            'record_sha256'             => $recordSha256,
            'onchain_commitment_txid'   => (string)$eveId,
        ];
    }

    // -------------------------------------------------------------------------
    // Public: get execution session status for display.
    // Returns array with both execution records and attestation if present.
    // -------------------------------------------------------------------------
    public static function getSession(PDO $db, string $sessionId): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT record_id, capacity, deed_sha256, record_sha256,
                        execution_timestamp_utc, onchain_commitment_txid, status
                 FROM declaration_execution_records
                 WHERE session_id = ? ORDER BY capacity'
            );
            $stmt->execute([$sessionId]);
            $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$records) return null;

            $aStmt = $db->prepare(
                'SELECT attestation_id, witness_full_name, attestation_timestamp_utc,
                        record_sha256, onchain_commitment_txid
                 FROM declaration_witness_attestations WHERE session_id = ? LIMIT 1'
            );
            $aStmt->execute([$sessionId]);
            $attestation = $aStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            return ['records' => $records, 'attestation' => $attestation, 'session_id' => $sessionId];
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Public: get the active execution session (any in-progress or complete).
    // -------------------------------------------------------------------------
    public static function getActiveSession(PDO $db): ?array
    {
        try {
            $stmt = $db->query(
                'SELECT DISTINCT session_id FROM declaration_execution_records
                 ORDER BY created_at DESC LIMIT 1'
            );
            $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$row) return null;
            return self::getSession($db, (string)$row['session_id']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Public: generate a one-time token (reuses one_time_tokens table).
    // purpose: 'declaration_execution' (Thomas) or 'witness_attestation' (Alex)
    // -------------------------------------------------------------------------
    public static function generateOneTimeToken(PDO $db, string $purpose): string
    {
        $allowed = ['declaration_execution', 'witness_attestation'];
        if (!in_array($purpose, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid token purpose: {$purpose}");
        }

        $stmt = $db->prepare(
            'SELECT id FROM one_time_tokens
             WHERE purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$purpose]);
        if ($stmt->fetch()) {
            throw new \RuntimeException(
                "A valid unused {$purpose} token already exists. Invalidate it before generating a new one."
            );
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+24 hours'));

        $db->prepare(
            'INSERT INTO one_time_tokens (token_hash, purpose, expires_at, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$tokenHash, $purpose, $expiresAt]);

        return $rawToken;
    }

    // -------------------------------------------------------------------------
    // Public: validate and consume a one-time token.
    // -------------------------------------------------------------------------
    public static function validateOneTimeToken(PDO $db, string $rawToken, string $purpose): bool
    {
        $tokenHash = hash('sha256', $rawToken);
        $stmt = $db->prepare(
            'SELECT id FROM one_time_tokens
             WHERE token_hash = ? AND purpose = ?
               AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash, $purpose]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return false;
        $db->prepare('UPDATE one_time_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?')
           ->execute([(int)$row['id']]);
        return true;
    }

    // -------------------------------------------------------------------------
    // Public: get the deed SHA-256 from deed_version_anchors.
    // Used by the witness flow to verify the hash without re-computing.
    // -------------------------------------------------------------------------
    public static function getDeedSha256(PDO $db): ?string
    {
        try {
            $stmt = $db->prepare(
                'SELECT deed_sha256 FROM deed_version_anchors WHERE deed_key = ? LIMIT 1'
            );
            $stmt->execute([self::DEED_KEY]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (string)$row['deed_sha256'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

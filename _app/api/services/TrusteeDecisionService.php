<?php
declare(strict_types=1);

/**
 * TrusteeDecisionService
 * Trustee Records System — Phase 1: Trustee Decision Records (TDR)
 *
 * Extends the existing execution rail pattern (DeclarationExecutionService,
 * SubTrustAExecutionService, etc.) for Trustee Decision Records.
 *
 * Design principles (per TrusteeRecordsSystem_PlanAndStrategy_v1.0):
 *  - DB is authoritative; PDF is a rendering of the DB state
 *  - Immutability after status = fully_executed
 *  - Canonical SHA-256 payload includes all material fields including witness
 *  - OTP routed to sub-trust-specific email (sub-trust-a@, sub-trust-b@, sub-trust-c@)
 *  - Witness is form-captured (name, DOB, occupation, address, JP number, comments)
 *  - non_mis_affirmation must be 1 for normal execution
 *
 * Electronic Transactions Act 1999 (Cth) + s.14G ETA 2000 (NSW)
 */
class TrusteeDecisionService
{
    public const TOKEN_PURPOSE_EXECUTION = 'tdr_execution';
    public const TOKEN_PURPOSE_WITNESS   = 'tdr_witness';
    public const TOKEN_TTL_EXECUTION     = 900;  // 15 minutes
    public const TOKEN_TTL_WITNESS       = 900;  // 15 minutes

    public const EXECUTOR_NAME    = 'Thomas Boyd Cunliffe';
    public const EXECUTOR_ADDRESS = '780 Sugarbag Road West, DRAKE 2469 NSW';

    public const EXECUTION_METHOD =
        'Electronic execution — Electronic Transactions Act 1999 (Cth) and ' .
        'section 14G Electronic Transactions Act 2000 (NSW). ' .
        'No wet-ink signature or paper counterpart is required or produced.';

    public const NON_MIS_STATEMENT =
        'This Trustee Decision Record is made in respect of the COGS of Australia Foundation ' .
        'Community Joint Venture Mainspring Hybrid Trust, which is not a managed investment ' .
        'scheme within the meaning of section 9 of the Corporations Act 2001 (Cth), ' .
        'consistently with JVPA clause 4.9, Declaration clause 1.1A, and the Sub-Trust Deeds.';

    // ── Token management ──────────────────────────────────────────────────────

    /**
     * Generate a one-time execution token for a TDR.
     * Returns the raw 64-char hex token (to be emailed, never stored in plain form).
     */
    public static function generateExecutionToken(PDO $db, string $decisionUuid, string $purposeBase = self::TOKEN_PURPOSE_EXECUTION): string
    {
        $purpose = $purposeBase . ':' . $decisionUuid;

        // Invalidate any prior unused token for this decision+purpose
        $db->prepare(
            "UPDATE one_time_tokens SET used_at = UTC_TIMESTAMP()
             WHERE purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()"
        )->execute([$purpose]);

        $raw     = bin2hex(random_bytes(32));
        $hash    = hash('sha256', $raw);
        $expires = gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL_EXECUTION);

        $db->prepare(
            "INSERT INTO one_time_tokens (token_hash, purpose, expires_at, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$hash, $purpose, $expires]);

        return $raw;
    }

    /**
     * Validate a raw token without consuming it. Returns decision_uuid or null.
     */
    public static function validateToken(PDO $db, string $raw, string $purposeBase = self::TOKEN_PURPOSE_EXECUTION): ?string
    {
        $hash = hash('sha256', $raw);
        $stmt = $db->prepare(
            "SELECT id, purpose FROM one_time_tokens
             WHERE token_hash = ? AND purpose LIKE ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1"
        );
        $stmt->execute([$hash, $purposeBase . ':%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Extract decision_uuid from purpose string e.g. "tdr_execution:uuid-here"
        $parts = explode(':', (string)$row['purpose'], 2);
        return $parts[1] ?? null;
    }

    /**
     * Consume a raw token. Returns true if consumed, false if already used/expired.
     */
    public static function consumeToken(PDO $db, string $raw, string $purposeBase = self::TOKEN_PURPOSE_EXECUTION): bool
    {
        $hash = hash('sha256', $raw);
        $stmt = $db->prepare(
            "UPDATE one_time_tokens SET used_at = UTC_TIMESTAMP()
             WHERE token_hash = ? AND purpose LIKE ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()"
        );
        $stmt->execute([$hash, $purposeBase . ':%']);
        return $stmt->rowCount() > 0;
    }

    // ── Decision CRUD ─────────────────────────────────────────────────────────

    /**
     * Load a decision record by UUID.
     */
    public static function getDecision(PDO $db, string $decisionUuid): ?array
    {
        $stmt = $db->prepare('SELECT * FROM trustee_decisions WHERE decision_uuid = ? LIMIT 1');
        $stmt->execute([$decisionUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Load a decision record by ref (e.g. TDR-20260422-001).
     */
    public static function getDecisionByRef(PDO $db, string $ref): ?array
    {
        $stmt = $db->prepare('SELECT * FROM trustee_decisions WHERE decision_ref = ? LIMIT 1');
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List decisions with optional filters.
     * Returns array of rows ordered by effective_date DESC.
     */
    public static function listDecisions(
        PDO $db,
        ?string $subTrust = null,
        ?string $category = null,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $where  = ['1=1'];
        $params = [];
        if ($subTrust !== null) { $where[] = 'sub_trust_context = ?'; $params[] = $subTrust; }
        if ($category !== null) { $where[] = 'decision_category = ?'; $params[] = $category; }
        if ($status   !== null) { $where[] = 'status = ?';            $params[] = $status; }
        if ($dateFrom !== null) { $where[] = 'effective_date >= ?';   $params[] = $dateFrom; }
        if ($dateTo   !== null) { $where[] = 'effective_date <= ?';   $params[] = $dateTo; }
        $params[] = $limit;
        $params[] = $offset;
        $sql = 'SELECT * FROM trustee_decisions WHERE ' . implode(' AND ', $where) .
               ' ORDER BY effective_date DESC, created_at DESC LIMIT ? OFFSET ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new draft TDR. Returns the new decision_uuid.
     */
    public static function createDraft(PDO $db, array $data, ?int $adminId = null): string
    {
        $uuid = self::uuid4();
        $ref  = self::generateRef($db, $data['effective_date'] ?? date('Y-m-d'));

        $db->prepare(
            "INSERT INTO trustee_decisions
               (decision_uuid, decision_ref, sub_trust_context, decision_category,
                title, effective_date, related_poll_id,
                powers_json, background_md, fnac_consideration_md, fpic_consideration_md,
                cultural_heritage_md, resolution_md,
                fnac_consulted, fnac_evidence_ref,
                fpic_obtained, fpic_evidence_ref,
                cultural_heritage_assessed, cultural_heritage_ref,
                non_mis_affirmation, visibility, status, created_by_admin_id, created_at)
             VALUES
               (?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                1, 'internal', 'draft', ?, UTC_TIMESTAMP())"
        )->execute([
            $uuid,
            $ref,
            $data['sub_trust_context'],
            $data['decision_category'],
            $data['title'],
            $data['effective_date'],
            $data['related_poll_id'] ?? null,
            json_encode($data['powers'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['background_md']         ?? null,
            $data['fnac_consideration_md'] ?? null,
            $data['fpic_consideration_md'] ?? null,
            $data['cultural_heritage_md']  ?? null,
            $data['resolution_md'],
            $data['fnac_consulted']             ? 1 : 0,
            $data['fnac_evidence_ref']          ?? null,
            $data['fpic_obtained']              ? 1 : 0,
            $data['fpic_evidence_ref']          ?? null,
            $data['cultural_heritage_assessed'] ? 1 : 0,
            $data['cultural_heritage_ref']      ?? null,
            $adminId,
        ]);

        return $uuid;
    }

    /**
     * Update a draft TDR (only permitted while status = draft).
     */
    public static function updateDraft(PDO $db, string $decisionUuid, array $data): void
    {
        $decision = self::getDecision($db, $decisionUuid);
        if (!$decision) {
            throw new \RuntimeException("TDR not found: {$decisionUuid}");
        }
        if ($decision['status'] !== 'draft') {
            throw new \RuntimeException("TDR {$decision['decision_ref']} cannot be edited — status is {$decision['status']}.");
        }
        $db->prepare(
            "UPDATE trustee_decisions SET
               sub_trust_context = ?, decision_category = ?, title = ?,
               effective_date = ?, related_poll_id = ?,
               powers_json = ?, background_md = ?, fnac_consideration_md = ?,
               fpic_consideration_md = ?, cultural_heritage_md = ?, resolution_md = ?,
               fnac_consulted = ?, fnac_evidence_ref = ?,
               fpic_obtained = ?, fpic_evidence_ref = ?,
               cultural_heritage_assessed = ?, cultural_heritage_ref = ?,
               updated_at = UTC_TIMESTAMP()
             WHERE decision_uuid = ? AND status = 'draft'"
        )->execute([
            $data['sub_trust_context'],
            $data['decision_category'],
            $data['title'],
            $data['effective_date'],
            $data['related_poll_id'] ?? null,
            json_encode($data['powers'] ?? [], JSON_UNESCAPED_UNICODE),
            $data['background_md']         ?? null,
            $data['fnac_consideration_md'] ?? null,
            $data['fpic_consideration_md'] ?? null,
            $data['cultural_heritage_md']  ?? null,
            $data['resolution_md'],
            $data['fnac_consulted']             ? 1 : 0,
            $data['fnac_evidence_ref']          ?? null,
            $data['fpic_obtained']              ? 1 : 0,
            $data['fpic_evidence_ref']          ?? null,
            $data['cultural_heritage_assessed'] ? 1 : 0,
            $data['cultural_heritage_ref']      ?? null,
            $decisionUuid,
        ]);
    }

    // ── Execution rail ────────────────────────────────────────────────────────

    /**
     * Issue an execution token: marks TDR as pending_execution, returns raw token.
     * Sends email to the sub-trust trustee email address.
     * Does NOT send email itself — caller passes $emailFn(string $to, string $raw, array $decision).
     */
    public static function issueExecutionToken(PDO $db, string $decisionUuid): string
    {
        $decision = self::getDecision($db, $decisionUuid);
        if (!$decision) {
            throw new \RuntimeException("TDR not found: {$decisionUuid}");
        }
        if (!in_array($decision['status'], ['draft', 'pending_execution'], true)) {
            throw new \RuntimeException("TDR {$decision['decision_ref']} cannot be issued — status is {$decision['status']}.");
        }
        if (!(int)$decision['non_mis_affirmation']) {
            throw new \RuntimeException("non_mis_affirmation must be 1 before an execution token can be issued.");
        }

        $raw = self::generateExecutionToken($db, $decisionUuid);

        $db->prepare(
            "UPDATE trustee_decisions SET status = 'pending_execution', updated_at = UTC_TIMESTAMP()
             WHERE decision_uuid = ?"
        )->execute([$decisionUuid]);

        return $raw;
    }

    /**
     * Look up the trustee email for a given sub_trust_context.
     */
    public static function getTrusteeEmail(PDO $db, string $subTrustContext): ?string
    {
        $stmt = $db->prepare(
            "SELECT email FROM trustees WHERE sub_trust_context = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$subTrustContext]);
        $email = $stmt->fetchColumn();
        return $email !== false ? (string)$email : null;
    }

    /**
     * Execute a TDR (Trustee acceptance step).
     * Called from execute_tdr.php after token validation.
     * Returns execution record array.
     */
    public static function recordExecution(
        PDO    $db,
        string $decisionUuid,
        string $ipAddress,
        string $userAgent,
        string $rawToken
    ): array {
        $decision = self::getDecision($db, $decisionUuid);
        if (!$decision) {
            throw new \RuntimeException("TDR not found: {$decisionUuid}");
        }
        if ($decision['status'] !== 'pending_execution') {
            throw new \RuntimeException("TDR {$decision['decision_ref']} is not pending execution.");
        }

        // Consume token
        if (!self::consumeToken($db, $rawToken, self::TOKEN_PURPOSE_EXECUTION)) {
            throw new \RuntimeException("Execution token is invalid, expired, or already used.");
        }

        // Look up trustee row
        $trusteeStmt = $db->prepare(
            "SELECT id FROM trustees WHERE sub_trust_context = ? AND status = 'active' LIMIT 1"
        );
        $trusteeStmt->execute([$decision['sub_trust_context']]);
        $trusteeId = (int)$trusteeStmt->fetchColumn();
        if (!$trusteeId) {
            throw new \RuntimeException("No active trustee found for {$decision['sub_trust_context']}.");
        }

        $execUuid   = self::uuid4();
        $nowUtc     = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $nowDb      = gmdate('Y-m-d H:i:s');
        $execDate   = gmdate('Y-m-d');
        $capacityLabel = 'caretaker_trustee_' . $decision['sub_trust_context'];

        $ipData = json_encode([
            'ip_address'      => $ipAddress,
            'user_agent'      => $userAgent,
            'server_time_utc' => $nowDb,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ipHash = hash('sha256', $ipData);

        $ackText = sprintf(
            'I, %s, execute this Trustee Decision Record (%s) as Caretaker Trustee of %s ' .
            'of the COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust. ' .
            'I have read and understood this Record and resolve as stated herein. ' .
            '%s ' .
            '%s',
            self::EXECUTOR_NAME,
            $decision['decision_ref'],
            strtoupper(str_replace('_', '-', $decision['sub_trust_context'])),
            self::EXECUTION_METHOD,
            self::NON_MIS_STATEMENT
        );

        // Build canonical payload (includes all material fields; witness fields added later)
        $canonical = [
            'record_type'              => 'trustee_decision_record',
            'execution_uuid'           => $execUuid,
            'decision_uuid'            => $decisionUuid,
            'decision_ref'             => $decision['decision_ref'],
            'sub_trust_context'        => $decision['sub_trust_context'],
            'decision_category'        => $decision['decision_category'],
            'title'                    => $decision['title'],
            'effective_date'           => $decision['effective_date'],
            'resolution_md'            => $decision['resolution_md'],
            'powers_json'              => json_decode((string)$decision['powers_json'], true),
            'non_mis_affirmation'      => true,
            'non_mis_statement'        => self::NON_MIS_STATEMENT,
            'executor_full_name'       => self::EXECUTOR_NAME,
            'executor_address'         => self::EXECUTOR_ADDRESS,
            'capacity_label'           => $capacityLabel,
            'acceptance_flag_engaged'  => true,
            'acknowledgement_text'     => $ackText,
            'execution_timestamp_utc'  => $nowUtc,
            'ip_device_hash'           => $ipHash,
            'execution_method'         => self::EXECUTION_METHOD,
        ];
        $recHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $db->beginTransaction();
        try {
            // Insert execution record (witness fields NULL until witness step)
            $db->prepare(
                "INSERT INTO trustee_decision_execution_records
                   (execution_uuid, decision_uuid, trustee_id, capacity_label,
                    acceptance_flag_engaged, acknowledgement_text,
                    execution_timestamp_utc, execution_date,
                    ip_address, user_agent, ip_device_hash, ip_device_data,
                    record_sha256, status, created_at)
                 VALUES (?, ?, ?, ?,
                         1, ?,
                         ?, ?,
                         ?, ?, ?, ?,
                         ?, 'executor_complete', ?)"
            )->execute([
                $execUuid, $decisionUuid, $trusteeId, $capacityLabel,
                $ackText,
                $nowUtc, $execDate,
                $ipAddress, $userAgent, $ipHash, $ipData,
                $recHash, $nowDb,
            ]);

            // Anchor in evidence vault
            $db->prepare(
                "INSERT INTO evidence_vault_entries
                   (entry_type, subject_type, subject_id, subject_ref,
                    payload_hash, payload_summary, source_system,
                    chain_tx_hash, created_by_type, created_at)
                 VALUES ('trustee_decision_record', 'trustee_decision', 0, ?,
                         ?, ?, 'trustee_decision_service',
                         ?, 'system', ?)"
            )->execute([
                $execUuid,
                $recHash,
                sprintf('TDR execution — %s — %s', $decision['decision_ref'], $capacityLabel),
                '0x' . $recHash,
                $nowDb,
            ]);
            $eveId = (int)$db->lastInsertId();

            $db->prepare(
                "UPDATE trustee_decision_execution_records SET evidence_vault_id = ? WHERE execution_uuid = ?"
            )->execute([$eveId, $execUuid]);

            // Update decision with canonical payload + hash + vault ID; flip to witness_pending
            $db->prepare(
                "UPDATE trustee_decisions SET
                   canonical_payload_json = ?,
                   record_sha256 = ?,
                   evidence_vault_id = ?,
                   status = 'pending_execution',
                   updated_at = UTC_TIMESTAMP()
                 WHERE decision_uuid = ?"
            )->execute([
                json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $recHash,
                $eveId,
                $decisionUuid,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'execution_uuid'          => $execUuid,
            'decision_uuid'           => $decisionUuid,
            'decision_ref'            => $decision['decision_ref'],
            'record_sha256'           => $recHash,
            'execution_timestamp_utc' => $nowUtc,
            'evidence_vault_id'       => $eveId,
            'status'                  => 'executor_complete',
        ];
    }

    /**
     * Record witness attestation for an executed TDR.
     * Witness data is form-captured (no hardcoded identity).
     * Finalises the TDR to fully_executed.
     */
    public static function recordWitnessAttestation(
        PDO    $db,
        string $decisionUuid,
        array  $witness,  // full_name, dob, occupation, address, jp_number?, comments?
        string $rawToken,
        string $ipAddress,
        string $userAgent
    ): array {
        $decision = self::getDecision($db, $decisionUuid);
        if (!$decision) {
            throw new \RuntimeException("TDR not found: {$decisionUuid}");
        }

        // Find the execution record
        $execStmt = $db->prepare(
            "SELECT * FROM trustee_decision_execution_records
             WHERE decision_uuid = ? AND status = 'executor_complete' LIMIT 1"
        );
        $execStmt->execute([$decisionUuid]);
        $execRow = $execStmt->fetch(PDO::FETCH_ASSOC);
        if (!$execRow) {
            throw new \RuntimeException("No executor_complete execution record found for {$decision['decision_ref']}.");
        }

        if (!self::consumeToken($db, $rawToken, self::TOKEN_PURPOSE_WITNESS)) {
            throw new \RuntimeException("Witness token is invalid, expired, or already used.");
        }

        $nowUtc = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $nowDb  = gmdate('Y-m-d H:i:s');

        $witnessIpData = json_encode([
            'ip_address'      => $ipAddress,
            'user_agent'      => $userAgent,
            'server_time_utc' => $nowDb,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $witnessIpHash = hash('sha256', $witnessIpData);

        $attestText = sprintf(
            'I, %s, born %s, occupation %s, of %s%s, attest that I observed %s execute ' .
            'the Trustee Decision Record %s electronically on %s. ' .
            'I am satisfied this is the record as executed. ' .
            'This attestation is given electronically under section 14G of the ' .
            'Electronic Transactions Act 2000 (NSW).%s',
            $witness['full_name'],
            $witness['dob'],
            $witness['occupation'],
            $witness['address'],
            !empty($witness['jp_number']) ? ', JP No. ' . $witness['jp_number'] : '',
            self::EXECUTOR_NAME,
            $decision['decision_ref'],
            gmdate('d F Y', strtotime($nowDb)),
            !empty($witness['comments']) ? ' Comments: ' . $witness['comments'] : ''
        );

        // Re-build canonical payload with witness fields appended
        $existingCanonical = json_decode((string)($decision['canonical_payload_json'] ?? '{}'), true);
        $existingCanonical['witness_full_name']         = $witness['full_name'];
        $existingCanonical['witness_dob']               = $witness['dob'];
        $existingCanonical['witness_occupation']        = $witness['occupation'];
        $existingCanonical['witness_address']           = $witness['address'];
        $existingCanonical['witness_jp_number']         = $witness['jp_number'] ?? null;
        $existingCanonical['witness_attestation_text']  = $attestText;
        $existingCanonical['witness_timestamp_utc']     = $nowUtc;
        $existingCanonical['witness_ip_hash']           = $witnessIpHash;
        $finalHash = hash('sha256', json_encode($existingCanonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $db->beginTransaction();
        try {
            // Update execution record with witness fields + final hash
            $db->prepare(
                "UPDATE trustee_decision_execution_records SET
                   witness_full_name = ?, witness_dob = ?, witness_occupation = ?,
                   witness_address = ?, witness_jp_number = ?, witness_comments = ?,
                   witness_attestation_text = ?,
                   witness_ip_hash = ?, witness_timestamp_utc = ?,
                   record_sha256 = ?,
                   status = 'fully_executed'
                 WHERE execution_uuid = ?"
            )->execute([
                $witness['full_name'],
                $witness['dob'],
                $witness['occupation'],
                $witness['address'],
                $witness['jp_number'] ?? null,
                $witness['comments']  ?? null,
                $attestText,
                $witnessIpHash,
                $nowUtc,
                $finalHash,
                $execRow['execution_uuid'],
            ]);

            // Final evidence vault entry for witness attestation
            $db->prepare(
                "INSERT INTO evidence_vault_entries
                   (entry_type, subject_type, subject_id, subject_ref,
                    payload_hash, payload_summary, source_system,
                    chain_tx_hash, created_by_type, created_at)
                 VALUES ('witness_attestation', 'trustee_decision', 0, ?,
                         ?, ?, 'trustee_decision_service',
                         ?, 'system', ?)"
            )->execute([
                $execRow['execution_uuid'],
                $finalHash,
                sprintf('TDR witness attestation — %s — witness: %s', $decision['decision_ref'], $witness['full_name']),
                '0x' . $finalHash,
                $nowDb,
            ]);
            $witnessEveId = (int)$db->lastInsertId();

            // Finalise the decision row
            $db->prepare(
                "UPDATE trustee_decisions SET
                   canonical_payload_json = ?,
                   record_sha256 = ?,
                   status = 'fully_executed',
                   updated_at = UTC_TIMESTAMP()
                 WHERE decision_uuid = ?"
            )->execute([
                json_encode($existingCanonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $finalHash,
                $decisionUuid,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'execution_uuid'         => $execRow['execution_uuid'],
            'decision_uuid'          => $decisionUuid,
            'decision_ref'           => $decision['decision_ref'],
            'final_record_sha256'    => $finalHash,
            'witness_timestamp_utc'  => $nowUtc,
            'witness_vault_id'       => $witnessEveId,
            'status'                 => 'fully_executed',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Generate the next sequential TDR reference for a given date.
     * Format: TDR-YYYYMMDD-NNN (3-digit sequence per date).
     */
    public static function generateRef(PDO $db, string $effectiveDate): string
    {
        $datePart = date('Ymd', strtotime($effectiveDate));
        $prefix   = 'TDR-' . $datePart . '-';
        $stmt     = $db->prepare(
            "SELECT COUNT(*) FROM trustee_decisions WHERE decision_ref LIKE ?"
        );
        $stmt->execute([$prefix . '%']);
        $count = (int)$stmt->fetchColumn();
        return $prefix . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Return all attachments for a TDR.
     */
    public static function getAttachments(PDO $db, string $decisionUuid): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM trustee_decision_attachments WHERE decision_uuid = ? ORDER BY created_at'
        );
        $stmt->execute([$decisionUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all execution records for a TDR.
     */
    public static function getExecutionRecords(PDO $db, string $decisionUuid): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM trustee_decision_execution_records WHERE decision_uuid = ? ORDER BY created_at'
        );
        $stmt->execute([$decisionUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}

<?php
declare(strict_types=1);

/**
 * TrusteeCounterpartService
 *
 * Handles all operations for the Trustee Counterpart Record under
 * JVPA clause 10.10A. Structurally parallel to JvpaAcceptanceService
 * but produces a Trustee-capacity artefact, not a Member S-NFT token.
 *
 * The founding Caretaker Trustee record is identified by:
 *   capacity_type = 'founding_caretaker' AND superseded_at IS NULL
 *
 * Records are immutable after generation per clause 10.10A(f).
 */
class TrusteeCounterpartService
{
    // -------------------------------------------------------------------------
    // Supremacy acknowledgement text — stored verbatim in the Record per
    // clause 10.10A(c)(viii). Must match what is displayed in the UI exactly.
    // -------------------------------------------------------------------------
    public const SUPREMACY_ACKNOWLEDGEMENT = "I acknowledge that:\n"
        . "- this Joint Venture Participation Agreement is the supreme governing instrument of the Joint Venture;\n"
        . "- the CJVM Hybrid Trust Declaration is subordinate to this Agreement; and\n"
        . "- I consent to be bound by the terms of this Agreement in the performance of Trustee Functions.";

    // -------------------------------------------------------------------------
    // Public: generate and store the founding Trustee Counterpart Record.
    // Atomically writes trustee_counterpart_records + evidence_vault_entries.
    // Returns the full record array on success; throws on any failure.
    // -------------------------------------------------------------------------
    public static function record(
        PDO    $db,
        string $ipAddress,
        string $userAgent
    ): array {
        $version = self::getCurrentJvpaVersion($db);

        // Reject if a founding record already exists
        if (self::getFoundingRecord($db) !== null) {
            throw new RuntimeException('[TrusteeCounterpartService] A founding Caretaker Trustee Counterpart Record already exists. This flow is single-use.');
        }

        $recordId      = self::generateUuidV4();
        $nowUtc        = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $nowDb         = gmdate('Y-m-d H:i:s');
        $retentionDate = gmdate('Y-m-d H:i:s', strtotime('+7 years'));

        $ipDeviceData = json_encode([
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'server_time_utc' => $nowDb,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ipDeviceHash = hash('sha256', $ipDeviceData);

        $jvpaSha256 = $version['agreement_hash'];
        $jvpaVersion = $version['version_label'];
        $jvpaTitle   = $version['version_title'];
        $jvpaDate    = $version['effective_date'];

        // Build the canonical record array for hashing — order is deterministic
        $canonicalRecord = [
            'record_id'                      => $recordId,
            'trustee_full_name'              => 'Thomas Boyd Cunliffe',
            'declaration_appointment_ref'    => 'Founding Caretaker Trustee, appointed under the CJVM Hybrid Trust Declaration prior to the execution of the JVPA',
            'jvpa_version'                   => $jvpaVersion,
            'jvpa_title'                     => $jvpaTitle,
            'jvpa_execution_date'            => $jvpaDate,
            'jvpa_sha256'                    => $jvpaSha256,
            'acceptance_timestamp_utc'       => $nowUtc,
            'ip_device_hash'                 => $ipDeviceHash,
            'acceptance_flag_engaged'        => true,
            'supremacy_acknowledgement_text' => self::SUPREMACY_ACKNOWLEDGEMENT,
        ];

        $recordSha256 = hash('sha256', json_encode($canonicalRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $db->beginTransaction();
        try {
            // Insert the trustee_counterpart_records row
            $db->prepare(
                'INSERT INTO trustee_counterpart_records (
                    record_id, trustee_full_name, declaration_appointment_ref,
                    jvpa_version, jvpa_title, jvpa_execution_date, jvpa_sha256,
                    acceptance_timestamp_utc, ip_device_hash, ip_device_data,
                    acceptance_flag_engaged, supremacy_acknowledgement_text,
                    record_sha256, onchain_commitment_txid, capacity_type, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, \'founding_caretaker\', ?
                )'
            )->execute([
                $recordId,
                $canonicalRecord['trustee_full_name'],
                $canonicalRecord['declaration_appointment_ref'],
                $jvpaVersion,
                $jvpaTitle,
                $jvpaDate,
                $jvpaSha256,
                $nowUtc,
                $ipDeviceHash,
                $ipDeviceData,
                1, // acceptance_flag_engaged — must be 1
                self::SUPREMACY_ACKNOWLEDGEMENT,
                $recordSha256,
                $nowDb,
            ]);

            // Insert evidence_vault_entries row (transitional on-chain commitment)
            $db->prepare(
                'INSERT INTO evidence_vault_entries (
                    entry_type, subject_type, subject_id, subject_ref,
                    payload_hash, payload_summary, source_system,
                    chain_tx_hash, created_by_type, created_at
                ) VALUES (
                    \'trustee_counterpart_record\', \'trustee\', 0, ?,
                    ?, ?, \'trustee_acceptance\',
                    ?, \'system\', ?
                )'
            )->execute([
                $recordId,
                $recordSha256,
                sprintf('Trustee Counterpart Record %s — %s accepted JVPA %s', $recordId, $canonicalRecord['trustee_full_name'], $jvpaVersion),
                '0x' . $recordSha256, // transitional: SHA-256 prefixed as hex commitment
                $nowDb,
            ]);

            $eveId = (int)$db->lastInsertId();

            // Backfill onchain_commitment_txid with the evidence vault entry ID
            // (transitional pathway per JVPA cl.2.2(h) and brief §5)
            $db->prepare(
                'UPDATE trustee_counterpart_records SET onchain_commitment_txid = ? WHERE record_id = ?'
            )->execute([(string)$eveId, $recordId]);

            $db->commit();

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        return [
            'record_id'               => $recordId,
            'jvpa_version'            => $jvpaVersion,
            'jvpa_title'              => $jvpaTitle,
            'jvpa_execution_date'     => $jvpaDate,
            'jvpa_sha256'             => $jvpaSha256,
            'acceptance_timestamp_utc'=> $nowUtc,
            'record_sha256'           => $recordSha256,
            'onchain_commitment_txid' => (string)$eveId,
            'capacity_type'           => 'founding_caretaker',
        ];
    }

    // -------------------------------------------------------------------------
    // Public: retrieve the active founding Trustee Counterpart Record.
    // Returns public-safe fields only (no ip_device_data).
    // Returns null if no record exists yet.
    // -------------------------------------------------------------------------
    public static function getFoundingRecord(PDO $db): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT record_id, trustee_full_name, jvpa_version, jvpa_title,
                        jvpa_execution_date, jvpa_sha256, acceptance_timestamp_utc,
                        record_sha256, onchain_commitment_txid, capacity_type, created_at
                 FROM trustee_counterpart_records
                 WHERE capacity_type = \'founding_caretaker\' AND superseded_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Public: generate a one-time token for the Trustee acceptance flow.
    // Stores only the SHA-256 hash. Returns the raw token (shown once).
    // Throws if a valid unused token already exists.
    // -------------------------------------------------------------------------
    public static function generateOneTimeToken(PDO $db): string
    {
        // Check for existing valid unused token
        $stmt = $db->prepare(
            'SELECT id FROM one_time_tokens
             WHERE purpose = \'trustee_acceptance\'
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute();
        if ($stmt->fetch()) {
            throw new RuntimeException('A valid unused Trustee acceptance token already exists. Invalidate or wait for it to expire before generating a new one.');
        }

        $rawToken  = bin2hex(random_bytes(32)); // 64 hex chars, 256 bits
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+24 hours'));

        $db->prepare(
            'INSERT INTO one_time_tokens (token_hash, purpose, expires_at, created_at)
             VALUES (?, \'trustee_acceptance\', ?, UTC_TIMESTAMP())'
        )->execute([$tokenHash, $expiresAt]);

        return $rawToken;
    }

    // -------------------------------------------------------------------------
    // Public: validate a one-time token. Marks used_at on success.
    // Returns true on valid; false on invalid/expired/already used.
    // -------------------------------------------------------------------------
    public static function validateOneTimeToken(PDO $db, string $rawToken): bool
    {
        $tokenHash = hash('sha256', $rawToken);
        $stmt = $db->prepare(
            'SELECT id FROM one_time_tokens
             WHERE token_hash = ?
               AND purpose = \'trustee_acceptance\'
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $db->prepare(
            'UPDATE one_time_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([(int)$row['id']]);
        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------
    private static function getCurrentJvpaVersion(PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT version_label, version_title, effective_date, agreement_hash
             FROM jvpa_versions WHERE is_current = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('[TrusteeCounterpartService] No current JVPA version found. Run the SQL migration first.');
        }
        return $row;
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

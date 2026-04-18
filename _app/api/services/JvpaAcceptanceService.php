<?php
declare(strict_types=1);

/**
 * JvpaAcceptanceService
 *
 * Handles the atomic sequence for recording JVPA acceptance under Option A:
 * acceptance is recorded at Partner registration (snft-reserve), before Stripe
 * payment. stripe_payment_ref is backfilled later by the Stripe webhook.
 *
 * This version also supports repairing legacy/backfilled Partner rows where
 * partner_entry_records already contains one row per partner under the unique
 * key uq_partner_entry_records_partner. In that case the existing row is
 * updated in place instead of attempting a second INSERT.
 */
class JvpaAcceptanceService
{
    public static function record(
        PDO    $db,
        int    $partnerId,
        string $partnerNumber,
        int    $memberId,
        int    $snftSequenceNo,
        string $acceptedIp,
        string $acceptedUserAgent,
        string $entryType = 'personal'
    ): string {
        $version = self::getCurrentVersion($db);
        $acceptedAtUtc = gmdate('Y-m-d\TH:i:s\Z');
        $acceptedAtDb  = gmdate('Y-m-d H:i:s');

        $hash = self::computeHash(
            $partnerNumber,
            $snftSequenceNo,
            $version['version_label'],
            $version['agreement_hash'],
            $acceptedAtUtc,
            $acceptedIp
        );

        $startedTxn = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTxn = true;
        }

        try {
            $perCols = self::tableColumns($db, 'partner_entry_records');
            if (empty($perCols)) {
                throw new RuntimeException('[JvpaAcceptanceService] partner_entry_records columns not found — table missing or not migrated.');
            }

            $existingStmt = $db->prepare(
                'SELECT id, stripe_payment_ref, snft_metadata_written, snft_metadata_written_at, evidence_vault_id, created_at
'
                . 'FROM partner_entry_records WHERE partner_id = ? ORDER BY id DESC LIMIT 1'
            );
            $existingStmt->execute([$partnerId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $evidenceVaultId = self::insertEvidenceVaultEntry(
                $db,
                $partnerId,
                $partnerNumber,
                $version['version_label'],
                $hash,
                $acceptedAtDb
            );

            $commonMap = [
                'partner_id'               => $partnerId,
                'entry_channel'            => 'website_registration',
                'entry_label_public'       => 'partnership contribution',
                'entry_label_internal'     => 'snft_registration',
                'entry_amount_cents'       => 400,
                'checkbox_confirmed'       => 1,
                'snft_metadata_written'    => $existing ? (int)($existing['snft_metadata_written'] ?? 0) : 0,
                'snft_metadata_written_at' => $existing ? ($existing['snft_metadata_written_at'] ?? null) : null,
                'evidence_vault_id'        => $evidenceVaultId,
                'retention_expires_at'     => gmdate('Y-m-d H:i:s', strtotime('+7 years')),
                'accepted_version'         => $version['version_label'],
                'jvpa_title'               => $version['version_title'],
                'agreement_hash'           => $version['agreement_hash'],
                'acceptance_record_hash'   => $hash,
                'snft_sequence_no'         => $snftSequenceNo > 0 ? $snftSequenceNo : null,
                'accepted_at'              => $acceptedAtDb,
                'accepted_ip'              => $acceptedIp !== '' ? $acceptedIp : null,
                'accepted_user_agent'      => $acceptedUserAgent !== '' ? $acceptedUserAgent : null,
                'updated_at'               => $acceptedAtDb,
            ];

            if ($existing) {
                $updateData = [];
                foreach ($commonMap as $col => $val) {
                    if (isset($perCols[$col]) && $col !== 'partner_id') $updateData[$col] = $val;
                }
                if (!$updateData) {
                    throw new RuntimeException('[JvpaAcceptanceService] No updatable partner_entry_records columns found.');
                }
                $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($updateData)));
                $params = array_values($updateData);
                $params[] = (int)$existing['id'];
                $db->prepare('UPDATE partner_entry_records SET ' . $set . ' WHERE id = ?')->execute($params);
            } else {
                $insertData = $commonMap;
                $insertData['stripe_payment_ref'] = null;
                $insertData['created_at'] = $acceptedAtDb;
                $insertData = array_filter($insertData, fn($k) => isset($perCols[$k]), ARRAY_FILTER_USE_KEY);
                $cols  = array_keys($insertData);
                $marks = implode(',', array_fill(0, count($cols), '?'));
                $db->prepare(
                    'INSERT INTO partner_entry_records ('
                    . implode(',', array_map(fn($c) => "`{$c}`", $cols))
                    . ') VALUES (' . $marks . ')'
                )->execute(array_values($insertData));
            }

            if ($startedTxn) $db->commit();
            return $hash;
        } catch (Throwable $e) {
            if ($startedTxn && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function backfillStripeRef(PDO $db, int $partnerId, string $stripePaymentRef): void
    {
        if (!self::tableExists($db, 'partner_entry_records')) return;
        $db->prepare(
            'UPDATE partner_entry_records
'
            . 'SET stripe_payment_ref = CASE WHEN stripe_payment_ref IS NULL OR stripe_payment_ref = "" THEN ? ELSE stripe_payment_ref END, updated_at = NOW()
'
            . 'WHERE partner_id = ?'
        )->execute([$stripePaymentRef, $partnerId]);
    }

    public static function confirmMetadataWritten(PDO $db, int $partnerId): void
    {
        if (!self::tableExists($db, 'partner_entry_records')) return;
        $db->prepare(
            'UPDATE partner_entry_records
'
            . 'SET snft_metadata_written = 1, snft_metadata_written_at = NOW(), updated_at = NOW()
'
            . 'WHERE partner_id = ?'
        )->execute([$partnerId]);
    }

    public static function getCurrentVersion(PDO $db): array
    {
        if (!self::tableExists($db, 'jvpa_versions')) {
            throw new RuntimeException('[JvpaAcceptanceService] jvpa_versions table not found. Run the SQL migration first.');
        }
        $stmt = $db->prepare(
            'SELECT version_label, version_title, effective_date, agreement_hash
'
            . 'FROM jvpa_versions WHERE is_current = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('[JvpaAcceptanceService] No current JVPA version found in jvpa_versions. Seed the table.');
        }
        if (($row['agreement_hash'] ?? '') === 'REPLACE_WITH_SHA256_BEFORE_DEPLOY') {
            throw new RuntimeException('[JvpaAcceptanceService] jvpa_versions.agreement_hash is still the placeholder value. Compute the real SHA-256 and update the row before accepting new Partners.');
        }
        return $row;
    }

    private static function insertEvidenceVaultEntry(PDO $db, int $partnerId, string $partnerNumber, string $versionLabel, string $hash, string $acceptedAtDb): ?int
    {
        if (!self::tableExists($db, 'evidence_vault_entries')) return null;
        $eveCols = self::tableColumns($db, 'evidence_vault_entries');
        $eveData = [];
        $eveMap  = [
            'entry_type'       => 'jvpa_accepted',
            'subject_type'     => 'partner',
            'subject_id'       => $partnerId,
            'subject_ref'      => $partnerNumber,
            'payload_hash'     => $hash,
            'payload_summary'  => sprintf('JVPA %s accepted by partner %s', $versionLabel, $partnerNumber),
            'source_system'    => 'website_registration',
            'chain_tx_hash'    => null,
            'chain_block'      => null,
            'ledger_tx_hash'   => null,
            'ledger_block_ref' => null,
            'created_by_type'  => 'system',
            'created_by_id'    => null,
            'created_at'       => $acceptedAtDb,
        ];
        foreach ($eveMap as $col => $val) {
            if (isset($eveCols[$col])) $eveData[$col] = $val;
        }
        if (!$eveData) return null;
        $cols  = array_keys($eveData);
        $marks = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare(
            'INSERT INTO evidence_vault_entries ('
            . implode(',', array_map(fn($c) => "`{$c}`", $cols))
            . ') VALUES (' . $marks . ')'
        )->execute(array_values($eveData));
        return (int)$db->lastInsertId() ?: null;
    }

    private static function computeHash(
        string $partnerNumber,
        int    $snftSequenceNo,
        string $jvpaVersion,
        string $agreementHash,
        string $acceptedAtUtc,
        string $acceptedIp
    ): string {
        $payload = json_encode([
            'partner_number'   => $partnerNumber,
            'snft_sequence_no' => $snftSequenceNo,
            'jvpa_version'     => $jvpaVersion,
            'agreement_hash'   => $agreementHash,
            'accepted_at'      => $acceptedAtUtc,
            'accepted_ip'      => $acceptedIp,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $payload);
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function tableColumns(PDO $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            $cols = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cols[$row['Field']] = true;
            }
            return $cache[$table] = $cols;
        } catch (Throwable $e) {
            return $cache[$table] = [];
        }
    }
}

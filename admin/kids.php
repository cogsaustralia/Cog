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
if (!function_exists('q_rows')) {
    function q_rows(PDO $pdo, string $sql, array $p = []): array {
        $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('q_one')) {
    function q_one(PDO $pdo, string $sql, array $p = []): ?array {
        $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
}

$adminUserId = function_exists('ops_current_admin_user_id') ? ops_current_admin_user_id($pdo) : null;
$adminId   = function_exists('ops_legacy_admin_write_id') ? ops_legacy_admin_write_id($pdo) : (function_exists('ops_admin_id') ? ops_admin_id() : null);
$flash     = null;
$error     = null;
$section   = trim((string)($_GET['section'] ?? 'list'));

// ─── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');

        // ── Register a new kid ──────────────────────────────────────────────
        if ($action === 'register_kid') {
            $guardianId     = (int)($_POST['guardian_member_id'] ?? 0);
            $relType        = (string)($_POST['relationship_type'] ?? 'parent');
            $childName      = trim((string)($_POST['child_full_name'] ?? ''));
            $childFirst     = trim((string)($_POST['child_first_name'] ?? ''));
            $childLast      = trim((string)($_POST['child_last_name'] ?? ''));
            $childDob       = trim((string)($_POST['child_dob'] ?? ''));
            $declAccepted   = !empty($_POST['guardian_declaration_accepted']);
            $notes          = trim((string)($_POST['notes'] ?? ''));

            if (!$guardianId || !$childName || !$childDob) {
                throw new RuntimeException('Guardian, child name, and date of birth are required.');
            }
            if (!$declAccepted) {
                throw new RuntimeException('Guardian must accept the declaration before registering a child.');
            }

            // Verify guardian is active S-NFT member
            $guardian = q_one($pdo,
                "SELECT id, member_number, full_name, wallet_status
                 FROM snft_memberships WHERE id = ? LIMIT 1", [$guardianId]
            );
            if (!$guardian) throw new RuntimeException('Guardian member not found.');
            if ((string)$guardian['wallet_status'] === 'locked') {
                throw new RuntimeException('Guardian account is locked — cannot register kids tokens.');
            }

            // Validate child DOB — must be under 18
            $dobTs = strtotime($childDob);
            if (!$dobTs) throw new RuntimeException('Invalid date of birth.');
            $ageYears = (int)date_diff(new DateTime($childDob), new DateTime())->y;
            if ($ageYears >= 18) {
                throw new RuntimeException('Child must be under 18 at time of registration.');
            }

            $conversionDate = date('Y-m-d', strtotime($childDob . ' +18 years'));

            // Require supporting doc for legal_guardian
            $needsDoc = $relType === 'legal_guardian';
            $initialStatus = $needsDoc ? 'doc_required' : 'pending';

            $evidencePayload = json_encode([
                'agent'         => 'AdminKidsRegistration',
                'guardian'      => $guardian['member_number'],
                'child_name'    => $childName,
                'child_dob'     => $childDob,
                'relationship'  => $relType,
                'registered_by_admin_user_id' => $adminUserId,
                'timestamp'     => gmdate('Y-m-d\TH:i:s\Z'),
            ], JSON_UNESCAPED_SLASHES);
            $evidenceHash = hash('sha256', $evidencePayload);

            $pdo->prepare("
                INSERT INTO kids_token_registrations
                    (guardian_member_id, guardian_member_number, relationship_type,
                     child_full_name, child_first_name, child_last_name,
                     child_dob, child_age_at_registration,
                     guardian_declaration_accepted, guardian_declaration_accepted_at,
                     conversion_due_date, status, kyc_status,
                     evidence_hash, verification_notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?, ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ")->execute([
                $guardianId, $guardian['member_number'], $relType,
                $childName, $childFirst ?: null, $childLast ?: null,
                $childDob, $ageYears,
                $conversionDate, $initialStatus,
                $evidenceHash, $notes ?: null,
            ]);

            $regId = (int)$pdo->lastInsertId();

            // Evidence vault entry
            try {
                $pdo->prepare("
                    INSERT INTO evidence_vault_entries
                        (entry_type, subject_type, subject_id, subject_ref,
                         payload_hash, payload_summary, source_system, created_by_type, created_at)
                    VALUES ('kids_registration', 'snft_member', ?, ?, ?, ?, 'admin', 'admin', UTC_TIMESTAMP())
                ")->execute([
                    $guardianId, $guardian['member_number'], $evidenceHash,
                    "kS-NFT registration for {$childName} (DOB: {$childDob}) by guardian {$guardian['member_number']}",
                ]);
            } catch (Throwable $e) {}

            $flash = $needsDoc
                ? "Registration created (ID #{$regId}). Supporting documentation required before verification."
                : "Registration created (ID #{$regId}). Ready for admin verification.";

            // If this registration was created from a vault submission, auto-dismiss that record
            $sourceAppId = (int)($_POST['source_app_id'] ?? 0);
            if ($sourceAppId > 0) {
                try {
                    $pdo->prepare(
                        "UPDATE member_applications
                            SET application_status = 'processed',
                                notes = CONCAT(COALESCE(notes,''), ' [Processed: kids_token_registrations id=#{$regId}]'),
                                updated_at = UTC_TIMESTAMP()
                          WHERE id = ? AND application_type = 'kids_snft'"
                    )->execute([$sourceAppId]);
                } catch (Throwable $ignored) {}
            }

            $section = 'list';
        }

        // ── Upload KYC identity document ────────────────────────────────────
        if ($action === 'upload_kyc_doc') {
            $regId   = (int)($_POST['reg_id'] ?? 0);
            $docType = trim((string)($_POST['doc_type'] ?? 'medicare_card'));
            if (!$regId) throw new RuntimeException('Registration ID missing.');
            $reg = q_one($pdo, 'SELECT * FROM kids_token_registrations WHERE id = ? LIMIT 1', [$regId]);
            if (!$reg) throw new RuntimeException('Registration not found.');
            if (!in_array($reg['status'], ['pending','doc_required'], true)) {
                throw new RuntimeException('Registration is not in a state that accepts documents.');
            }
            if (empty($_FILES['kyc_doc']['tmp_name']) || !is_uploaded_file($_FILES['kyc_doc']['tmp_name'])) {
                throw new RuntimeException('No file uploaded.');
            }
            $finfo       = finfo_open(FILEINFO_MIME_TYPE);
            $mime        = finfo_file($finfo, $_FILES['kyc_doc']['tmp_name']);
            finfo_close($finfo);
            $allowedMime = ['application/pdf','image/jpeg','image/png','image/webp'];
            if (!in_array($mime, $allowedMime, true)) {
                throw new RuntimeException('Only PDF, JPG, PNG, or WEBP files are accepted.');
            }
            if ((int)$_FILES['kyc_doc']['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('File exceeds 10 MB limit.');
            }
            $docHash = hash_file('sha256', $_FILES['kyc_doc']['tmp_name']);
            // Store in private vault alongside ASX documents
            $storageDir = rtrim(dirname(dirname(__FILE__)), '/') . '/_private/kyc_docs/';
            if (!is_dir($storageDir)) mkdir($storageDir, 0750, true);
            $storedName = 'KYCDOC-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)),0,8)) . '.bin';
            if (!move_uploaded_file($_FILES['kyc_doc']['tmp_name'], $storageDir . $storedName)) {
                throw new RuntimeException('Failed to store document. Check server permissions.');
            }
            $validDocTypes = ['medicare_card','birth_certificate','passport','court_order','statutory_declaration','other'];
            if (!in_array($docType, $validDocTypes, true)) $docType = 'other';
            $pdo->prepare("
                UPDATE kids_token_registrations
                SET supporting_doc_hash = ?, supporting_doc_type = ?,
                    supporting_doc_uploaded_at = UTC_TIMESTAMP(),
                    status = CASE WHEN status = 'doc_required' THEN 'pending' ELSE status END,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$docHash, $docType, $regId]);
            $flash = "Identity document uploaded and hashed (SHA-256: " . substr($docHash, 0, 16) . "…). Registration #{$regId} ready for verification.";
            $section = 'list';
        }

        // ── Verify a registration ───────────────────────────────────────────
        if ($action === 'verify_registration') {
            $regId  = (int)($_POST['reg_id'] ?? 0);
            $notes  = trim((string)($_POST['notes'] ?? ''));
            if (!$regId) throw new RuntimeException('Registration ID missing.');

            $reg = q_one($pdo, 'SELECT * FROM kids_token_registrations WHERE id = ? LIMIT 1', [$regId]);
            if (!$reg) throw new RuntimeException('Registration not found.');
            if (!in_array($reg['status'], ['pending','doc_required'], true)) {
                throw new RuntimeException('Registration is not in a verifiable state.');
            }

            // ── KYC gate — Phase 1 ────────────────────────────────────────
            // Require either an uploaded identity document OR an explicit admin
            // override with a mandatory reason. Prevents verification without evidence.
            $kycOverride = trim((string)($_POST['kyc_override_reason'] ?? ''));
            $hasDoc      = !empty($reg['supporting_doc_hash']);
            if (!$hasDoc && $kycOverride === '') {
                throw new RuntimeException(
                    'KYC gate: no identity document has been uploaded for this registration. ' .
                    'Upload a Medicare card, birth certificate, or other evidence first, ' .
                    'or enter an override reason if you have verified identity by other means.'
                );
            }

            // Generate child member number using sequence
            $seq = q_one($pdo, 'INSERT INTO member_number_sequence () VALUES (); SELECT LAST_INSERT_ID() AS seq');
            if (!$seq) {
                $pdo->query('INSERT INTO member_number_sequence () VALUES ()');
                $seqId = (int)$pdo->lastInsertId();
            } else {
                $seqId = (int)$seq['seq'];
            }
            $prefix = (string)(env('SNFT_MEMBER_PREFIX') ?: '608200');
            $childMemberNumber = $prefix . str_pad((string)$seqId, 10, '0', STR_PAD_LEFT);

            $verifyNotes = trim(($notes ? $notes . ' ' : '') . ($kycOverride ? '[KYC override: ' . $kycOverride . ']' : ''));
            $pdo->prepare("
                UPDATE kids_token_registrations
                SET status = 'verified', kyc_status = 'verified',
                    child_member_number = ?,
                    verified_by_admin_id = ?, verified_at = UTC_TIMESTAMP(),
                    verification_notes = ?, updated_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$childMemberNumber, $adminId, $verifyNotes ?: null, $regId]);

            $docNote = $hasDoc ? ' Identity document on file.' : ' [No document — admin override recorded.]';
            $flash = "Registration #{$regId} verified. Child member number: {$childMemberNumber}.{$docNote}";
            $section = 'list';
        }

        // ── Issue token ─────────────────────────────────────────────────────
        if ($action === 'issue_token') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if (!$regId) throw new RuntimeException('Registration ID missing.');

            $reg = q_one($pdo, 'SELECT * FROM kids_token_registrations WHERE id = ? LIMIT 1', [$regId]);
            if (!$reg) throw new RuntimeException('Registration not found.');
            if ($reg['status'] !== 'verified') {
                throw new RuntimeException('Registration must be verified before issuing token.');
            }
            if ((string)($reg['kyc_status'] ?? '') !== 'verified') {
                throw new RuntimeException('KYC gate: identity verification has not been completed for this registration.');
            }

            // Add kS reservation line to guardian's account
            $ksTc = q_one($pdo, "SELECT id FROM token_classes WHERE class_code = 'KIDS_SNFT' LIMIT 1");
            if (!$ksTc) throw new RuntimeException('KIDS_SNFT token class not found.');

            // Check guardian's reservation line
            $existingLine = q_one($pdo,
                'SELECT id FROM member_reservation_lines
                 WHERE member_id = ? AND token_class_id = ?
                 LIMIT 1',
                [(int)$reg['guardian_member_id'], (int)$ksTc['id']]
            );

            $pdo->beginTransaction();
            try {
                if ($existingLine) {
                    $pdo->prepare(
                        'UPDATE member_reservation_lines
                         SET requested_units = requested_units + 1, updated_at = UTC_TIMESTAMP()
                         WHERE id = ?'
                    )->execute([(int)$existingLine['id']]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO member_reservation_lines
                         (member_id, token_class_id, requested_units, approved_units, paid_units,
                          approval_status, payment_status, created_at, updated_at)
                         VALUES (?, ?, 1, 1, 1, \'approved\', \'paid\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                    )->execute([(int)$reg['guardian_member_id'], (int)$ksTc['id']]);
                    $lineId = (int)$pdo->lastInsertId();
                }

                // Mark token issued on registration
                $pdo->prepare("
                    UPDATE kids_token_registrations
                    SET status = 'issued', token_issued = 1,
                        token_issued_at = UTC_TIMESTAMP(),
                        proxy_vote_active = 1,
                        proxy_vote_activated_at = UTC_TIMESTAMP(),
                        subtrust_b_unit_active = 1,
                        subtrust_b_unit_activated_at = UTC_TIMESTAMP(),
                        reservation_line_id = ?,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ")->execute([$lineId ?? $existingLine['id'], $regId]);

                // Update guardian's kids_tokens count
                $pdo->prepare(
                    "UPDATE snft_memberships SET kids_tokens = kids_tokens + 1,
                     kids_registrations_count = kids_registrations_count + 1,
                     updated_at = UTC_TIMESTAMP()
                     WHERE id = ?"
                )->execute([(int)$reg['guardian_member_id']]);

                // Wallet event log
                $pdo->prepare(
                    "INSERT INTO wallet_events (subject_type, subject_ref, event_type, description, created_at)
                     VALUES ('snft_member', ?, 'kids_token_issued',
                             CONCAT('kS-NFT issued for child: ', ?, ' (DOB: ', ?, '). Registration #', ?),
                             UTC_TIMESTAMP())"
                )->execute([
                    $reg['guardian_member_number'],
                    $reg['child_full_name'],
                    $reg['child_dob'],
                    $regId,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // ── Godley ledger emission for kS-NFT issue ───────────────────
            // Only emit if AccountingHooks has NOT already emitted a unit_issue_ks
            // entry for this guardian at payment confirmation time. AccountingHooks
            // runs via the Stripe webhook and uses ref GDLY-PAY{n}-KIDS_SNFT-M{n}.
            // Emitting again here would produce a duplicate $1.00 equity credit.
            $hooksFile = __DIR__ . '/includes/AccountingHooks.php';
            if (file_exists($hooksFile)) {
                require_once $hooksFile;
            }
            if (class_exists('AccountingHooks') && class_exists('LedgerEmitter')) {
                try {
                    $guardianMemberId = (int)$reg['guardian_member_id'];
                    // Check for existing unit_issue_ks credit on this guardian's sector
                    $alreadyEmitted = false;
                    try {
                        $chk = $pdo->prepare(
                            "SELECT COUNT(*) FROM ledger_entries le
                             JOIN stewardship_accounts sa ON sa.id = le.stewardship_account_id
                             WHERE sa.account_key = ?
                               AND le.flow_category = 'unit_issue_ks'
                               AND le.entry_type = 'credit'"
                        );
                        $chk->execute(['MEMBER-' . $guardianMemberId]);
                        $existingKsCount = (int)$chk->fetchColumn();
                        // Allow one entry per issued token — compare against previously issued count
                        $issuedStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM kids_token_registrations
                             WHERE guardian_member_id = ? AND status = 'issued' AND id != ?"
                        );
                        $issuedStmt->execute([$guardianMemberId, $regId]);
                        $issuedCount = (int)$issuedStmt->fetchColumn();
                        // If ledger credits already recorded >= tokens issued so far + 1,
                        // AccountingHooks covered the current one — skip to avoid duplicate
                        if ($existingKsCount > $issuedCount) {
                            $alreadyEmitted = true;
                        }
                    } catch (Throwable $chkEx) {
                        // Can't determine — skip to be safe
                        $alreadyEmitted = true;
                        error_log('[kids] kS ledger check failed, skipping emission: ' . $chkEx->getMessage());
                    }

                    if (!$alreadyEmitted) {
                        $godleyRef = 'GDLY-KS-ISSUE-REG' . $regId . '-M' . $guardianMemberId;
                        $entries   = \LedgerEmitter::buildKSClassEntries($guardianMemberId);
                        $res = \LedgerEmitter::emitTransaction(
                            $pdo, $godleyRef, 'backfill', $regId, $entries, date('Y-m-d')
                        );
                        if ($res['status'] === 'ok') {
                            error_log('[kids] Godley kS emission ok: ' . $godleyRef);
                        } else {
                            error_log('[kids] Godley kS emission ' . $res['status'] . ': ' . ($res['message'] ?? ''));
                        }
                    } else {
                        error_log('[kids] Godley kS emission skipped for REG' . $regId . ' — already covered by AccountingHooks at payment time.');
                    }
                } catch (Throwable $ledgerEx) {
                    error_log('[kids] Godley kS emission exception: ' . $ledgerEx->getMessage());
                }
            }

            $flash = "kS-NFT token issued to guardian {$reg['guardian_member_number']} for child {$reg['child_full_name']}.";
            $section = 'list';
        }

        // ── Reject a registration ───────────────────────────────────────────
        if ($action === 'reject_registration') {
            $regId  = (int)($_POST['reg_id'] ?? 0);
            $reason = trim((string)($_POST['rejected_reason'] ?? ''));
            if (!$regId || !$reason) throw new RuntimeException('Registration ID and reason required.');

            $pdo->prepare("
                UPDATE kids_token_registrations
                SET status = 'rejected', kyc_status = 'rejected',
                    rejected_reason = ?, verified_by_admin_id = ?,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$reason, $adminId, $regId]);

            $flash = "Registration #{$regId} rejected.";
            $section = 'list';
        }

        // ── Run age-18 conversions ──────────────────────────────────────────
        // ── Dismiss vault submission from pending queue ─────────────────────
        if ($action === 'dismiss_vault_submission') {
            $appId = (int)($_POST['app_id'] ?? 0);
            if (!$appId) throw new RuntimeException('Missing app_id.');
            $pdo->prepare(
                "UPDATE member_applications
                    SET application_status = 'processed',
                        notes = CONCAT(COALESCE(notes,''), ' [Processed by admin via kids.php]'),
                        updated_at = UTC_TIMESTAMP()
                  WHERE id = ? AND application_type = 'kids_snft'"
            )->execute([$appId]);
            $flash = "Vault submission #{$appId} marked as processed.";
            $section = 'list';
        }

        if ($action === 'run_conversions') {
            $due = q_rows($pdo,
                "SELECT * FROM kids_token_registrations
                 WHERE conversion_status = 'pending'
                   AND conversion_due_date <= CURDATE()
                   AND status = 'issued'
                 ORDER BY conversion_due_date ASC
                 LIMIT 20"
            );
            $converted = 0;
            foreach ($due as $reg) {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("
                        UPDATE kids_token_registrations
                        SET conversion_status = 'converted', converted_at = UTC_TIMESTAMP(),
                            status = 'converted',
                            proxy_vote_active = 0,
                            subtrust_b_unit_active = 0,
                            updated_at = UTC_TIMESTAMP()
                        WHERE id = ?
                    ")->execute([(int)$reg['id']]);

                    // Decrement guardian's kids_tokens and kids_registrations_count
                    $pdo->prepare(
                        "UPDATE snft_memberships
                         SET kids_tokens = GREATEST(0, kids_tokens - 1),
                             kids_registrations_count = GREATEST(0, kids_registrations_count - 1),
                             updated_at = UTC_TIMESTAMP()
                         WHERE id = ?"
                    )->execute([(int)$reg['guardian_member_id']]);

                    // Wallet event on guardian account
                    $pdo->prepare(
                        "INSERT INTO wallet_events (subject_type, subject_ref, event_type, description, created_at)
                         VALUES ('snft_member', ?, 'kids_token_converted_age_18',
                                 CONCAT('kS-NFT auto-converted to S-NFT for ', ?, ' (DOB: ', ?, '). Proxy vote released. Child now holds independent governance rights.'),
                                 UTC_TIMESTAMP())"
                    )->execute([
                        $reg['guardian_member_number'],
                        $reg['child_full_name'],
                        $reg['child_dob'],
                    ]);

                    $pdo->commit();

                    // ── Ledger reversal for proxy vote release ────────────────
                    // Emit correction_reversal to close the equity credit on the
                    // guardian's sector — the child's converted S-NFT will have
                    // its own ledger entry when the conversion token is minted.
                    $hooksFile2 = __DIR__ . '/includes/AccountingHooks.php';
                    if (file_exists($hooksFile2)) { require_once $hooksFile2; }
                    if (class_exists('LedgerEmitter')) {
                        try {
                            $convRef = 'GDLY-KS-CONVERT-REG' . (int)$reg['id'] . '-M' . (int)$reg['guardian_member_id'];
                            // Reverse the guardian equity credit: Dr MEMBER, Cr STA-PARTNERS-POOL
                            $convEntries = [
                                ['account_key' => 'MEMBER-' . (int)$reg['guardian_member_id'],
                                 'type' => 'debit', 'amount_cents' => 100,
                                 'classification' => 'equity', 'flow_category' => 'ks_conversion'],
                                ['account_key' => 'STA-PARTNERS-POOL',
                                 'type' => 'credit', 'amount_cents' => 100,
                                 'classification' => 'asset', 'flow_category' => 'ks_conversion'],
                            ];
                            \LedgerEmitter::emitTransaction(
                                $pdo, $convRef, 'backfill', (int)$reg['id'],
                                $convEntries, date('Y-m-d')
                            );
                        } catch (Throwable $convEx) {
                            error_log('[kids] conversion ledger entry failed for reg #' . $reg['id'] . ': ' . $convEx->getMessage());
                        }
                    }

                    $converted++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log('[kids] conversion failed for reg #' . $reg['id'] . ': ' . $e->getMessage());
                }
            }
            $flash = "Age-18 conversion run complete. {$converted} token(s) converted.";
            $section = 'list';
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ─── Data loads ───────────────────────────────────────────────────────────────
$editReg  = null;
if (isset($_GET['edit'])) {
    $editReg = q_one($pdo, 'SELECT * FROM kids_token_registrations WHERE id = ? LIMIT 1', [(int)$_GET['edit']]);
}

// Prefill values from vault submission link
$prefillAppId    = (int)($_GET['prefill_app_id'] ?? 0);
$prefillGuardId  = (int)($_GET['prefill_guardian_id'] ?? 0);
$prefillName     = trim((string)($_GET['prefill_child_name'] ?? ''));
$prefillDob      = trim((string)($_GET['prefill_child_dob'] ?? ''));
$prefillRel      = trim((string)($_GET['prefill_rel'] ?? 'parent'));
$prefillGuardian = null;
if ($prefillGuardId > 0) {
    $prefillGuardian = q_one($pdo,
        'SELECT id, member_number, full_name, email, mobile FROM snft_memberships WHERE id = ? LIMIT 1',
        [$prefillGuardId]
    );
}

// ── Filter params ─────────────────────────────────────────────────────────────
$fSearch = trim((string)($_GET['search'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));

$kidsWhere  = '';
$kidsParams = [];
if ($fSearch !== '') {
    $kidsWhere .= ' AND (r.child_full_name LIKE ? OR sm.full_name LIKE ? OR r.guardian_member_number LIKE ?)';
    $s = '%' . $fSearch . '%';
    $kidsParams[] = $s; $kidsParams[] = $s; $kidsParams[] = $s;
}
$validStatuses = ['pending','doc_required','verified','issued','converted','rejected'];
if ($fStatus !== '' && in_array($fStatus, $validStatuses, true)) {
    $kidsWhere .= ' AND r.status = ?';
    $kidsParams[] = $fStatus;
}

$registrations = q_rows($pdo,
    "SELECT r.*,
            sm.full_name AS guardian_name, sm.email AS guardian_email,
            sm.wallet_status AS guardian_wallet_status
     FROM kids_token_registrations r
     LEFT JOIN snft_memberships sm ON sm.id = r.guardian_member_id
     WHERE 1=1 $kidsWhere
     ORDER BY r.created_at DESC
     LIMIT 100",
    $kidsParams
);

$pendingCount   = (int)(q_one($pdo, "SELECT COUNT(*) AS c FROM kids_token_registrations WHERE status IN ('pending','doc_required')")['c'] ?? 0);
$verifiedCount  = (int)(q_one($pdo, "SELECT COUNT(*) AS c FROM kids_token_registrations WHERE status = 'verified'")['c'] ?? 0);
$issuedCount    = (int)(q_one($pdo, "SELECT COUNT(*) AS c FROM kids_token_registrations WHERE status = 'issued'")['c'] ?? 0);
$convertedCount = (int)(q_one($pdo, "SELECT COUNT(*) AS c FROM kids_token_registrations WHERE status = 'converted'")['c'] ?? 0);
$dueSoon        = (int)(q_one($pdo, "SELECT COUNT(*) AS c FROM kids_token_registrations WHERE conversion_status = 'pending' AND status = 'issued' AND conversion_due_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)")['c'] ?? 0);

// Guardian search for the registration form
$guardianSearch = trim((string)($_GET['guardian_q'] ?? ''));
$guardians = [];
if ($guardianSearch !== '') {
    $guardians = q_rows($pdo,
        "SELECT id, member_number, full_name, email, mobile
         FROM snft_memberships
         WHERE (full_name LIKE ? OR member_number LIKE ? OR mobile LIKE ?)
           AND wallet_status != 'locked'
         ORDER BY full_name ASC LIMIT 10",
        ["%{$guardianSearch}%", "%{$guardianSearch}%", "%{$guardianSearch}%"]
    );
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Kids kS-NFT Registrations</title>
<style>
.stat-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.stat{flex:1;min-width:110px;padding:14px;background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:12px;text-align:center}
.stat .sv{font-size:1.5rem;font-weight:800;color:var(--gold)}
.stat .sl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-top:3px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.info-box{padding:10px 14px;border-radius:10px;background:rgba(212,178,92,.06);border:1px solid rgba(212,178,92,.15);font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:12px}
.warning-box{padding:10px 14px;border-radius:10px;background:rgba(200,61,75,.08);border:1px solid rgba(200,61,75,.2);font-size:12px;color:var(--bad);margin-bottom:12px}
.conversion-alert{color:#ffa040;font-weight:700}
.drawer-bg{display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.5);backdrop-filter:blur(3px)}
.drawer-bg.show{display:block}
.drawer{position:fixed;top:0;right:-520px;width:480px;max-width:92vw;height:100vh;background:var(--panel);border-left:1px solid var(--line);z-index:201;overflow-y:auto;padding:24px;transition:right .25s ease}
.drawer-bg.show .drawer{right:0}
.drawer h3{margin:0 0 16px;font-size:16px}
.close-x{position:absolute;top:14px;right:14px;background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;padding:4px 8px}
@media(max-width:900px){.grid-2,.grid-3{grid-template-columns:1fr}.drawer{width:100%}}
</style>
<script>
function openDrawer(id){ document.getElementById(id)?.classList.add('show'); }
function closeDrawer(id){ document.getElementById(id)?.classList.remove('show'); }
function fillGuardian(id, num, name){
  document.getElementById('guardian_member_id').value = id;
  document.getElementById('guardian_display').textContent = name + ' (' + num + ')';
  document.getElementById('guardian_search_results').style.display = 'none';
}
// Auto-fill guardian from URL prefill params (vault submission link)
<?php if($prefillGuardian): ?>
document.addEventListener('DOMContentLoaded', function(){
  var gdEl = document.getElementById('guardian_member_id');
  if(gdEl && !gdEl.value) {
    gdEl.value = '<?=(int)$prefillGuardian['id']?>';
  }
});
<?php endif; ?>
function searchGuardian(){
  const q = document.getElementById('guardian_search').value;
  if(q.length < 2) return;
  window.location.href = '?section=register&guardian_q=' + encodeURIComponent(q);
}
</script>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="admin-shell">
<?php admin_sidebar_render('kids'); ?>
<main class="main">

<!-- Header -->
<div class="card">
  <div class="card-head">
    <h1 style="margin:0;font-size:1.2rem">👶 Kids kS-NFT Registrations <?= ops_admin_help_button('Kids kS-NFT registrations', 'Use this page to register, verify, issue, and monitor child-linked kS-NFT records. It is the operator surface for guardian relationships, child status, conversion timing, and token issue state.') ?></h1>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="btn btn-sm" onclick="openDrawer('register-drawer')">+ Register Child</button><?= ops_admin_help_button('Register Child', 'Use this to create a new child registration directly from admin when needed.') ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="run_conversions">
        <button type="submit" class="btn btn-sm" onclick="return confirm('Run age-18 conversion check now?')" title="Auto-converts tokens for children who have turned 18">⟳ Age-18 Check</button><?= ops_admin_help_button('Run Age-18 Check', 'This checks whether any child records have reached the conversion point and need lifecycle handling.') ?>
      </form>
    </div>
  </div>
  <div class="card-body" style="padding-top:8px">
    <p class="muted small" style="margin:0">Verify guardian relationships and manage kS-NFT tokens — MD clauses 25.1–25.5</p>
  </div>
</div>

<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Stage 4 · Children and guardians', 'What this page does', 'This page manages children linked to a guardian Partner record. It is where you register the child, verify the guardian relationship, issue the child-linked token, and monitor future conversion to the adult pathway.', [
    'Use this page when a guardian submits child details through the vault or when an operator needs to create the record directly.',
    'Verification here is about guardian relationship and child record integrity, not about later execution or governance publishing.',
    'The age-18 conversion check is a lifecycle task for child records that are approaching adulthood.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Kids registrations follow a clear lifecycle from submission to token issue.', [
    ['title' => 'Review the submission', 'body' => 'Check the guardian, the child details, the relationship type, and whether supporting documentation is required.'],
    ['title' => 'Register the child formally', 'body' => 'Create the kS-NFT registration record from the vault submission or direct admin entry.'],
    ['title' => 'Verify the record', 'body' => 'Verify only when the guardian relationship and child details are satisfactory.'],
    ['title' => 'Issue and monitor', 'body' => 'Issue the token after verification, then monitor the future age-18 conversion timeline.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'The page is split into intake, active registrations, and lifecycle management.', [
    ['title' => 'Vault submissions', 'body' => 'These are member-submitted child applications waiting for formal admin registration.'],
    ['title' => 'All registrations', 'body' => 'These are the authoritative child records after registration has been created.'],
    ['title' => 'Verification and token issue', 'body' => 'Only verified records should proceed to child-member-number generation and token issue.'],
    ['title' => 'Conversion monitoring', 'body' => 'Use the age-18 check and conversion indicators to watch records approaching adulthood.'],
  ]),
  ops_admin_status_panel('Field and status guide', 'These are the most important statuses and concepts on the kids page.', [
    ['label' => 'Pending review', 'body' => 'The child record exists but still needs operator review or verification.'],
    ['label' => 'Verified', 'body' => 'The guardian relationship and child details have been accepted by the operator.'],
    ['label' => 'Tokens issued', 'body' => 'A child-linked token has been issued against the verified registration.'],
    ['label' => 'Converted (18+)', 'body' => 'The child has reached adulthood and the record has been processed into the next lifecycle state.'],
  ]),
]) ?>

<?php if($flash): ?><div class="msg ok"><?=h($flash)?></div><?php endif; ?>
<?php if($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

<!-- Stats -->
<div class="stat-row">
  <div class="stat"><div class="sv"><?=$pendingCount?></div><div class="sl">Pending review</div></div>
  <div class="stat"><div class="sv" style="color:#8ecef0"><?=$verifiedCount?></div><div class="sl">Verified</div></div>
  <div class="stat"><div class="sv" style="color:#7ee0a0"><?=$issuedCount?></div><div class="sl">Tokens issued</div></div>
  <div class="stat"><div class="sv" style="color:#d4a8e8"><?=$convertedCount?></div><div class="sl">Converted (18+)</div></div>
  <?php if($dueSoon > 0): ?>
  <div class="stat"><div class="sv conversion-alert"><?=$dueSoon?></div><div class="sl">Due for conversion (&lt;90 days)</div></div>
  <?php endif; ?>
</div>

<!-- ── Vault-submitted kids applications (member_applications → kids_snft) ── -->
<?php
$vaultSubmissions = q_rows($pdo,
    "SELECT a.id, a.guardian_member_id, a.child_full_name, a.child_dob,
            a.guardian_full_name, a.guardian_email, a.guardian_relationship,
            a.application_status, a.created_at,
            sm.member_number AS guardian_member_number,
            sm.wallet_status AS guardian_wallet_status
       FROM member_applications a
       LEFT JOIN snft_memberships sm ON sm.id = a.guardian_member_id
      WHERE a.application_type = 'kids_snft'
        AND a.application_status = 'submitted'
      ORDER BY a.created_at ASC
      LIMIT 50"
);
if (!empty($vaultSubmissions)):
?>
<div class="card" style="border-color:rgba(212,178,92,.3);margin-bottom:14px">
  <div class="card-head" style="background:rgba(212,178,92,.06);border-color:rgba(212,178,92,.15)">
    <h2 style="margin:0;color:var(--gold)">⚠ Vault Submissions — Awaiting Admin Registration (<?=count($vaultSubmissions)?>)</h2>
    <span class="muted small">Members submitted via wallet — click Register to create the formal kS-NFT record</span>
  </div>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>App&nbsp;#</th>
          <th>Guardian</th>
          <th>Child Name</th>
          <th>DOB</th>
          <th>Relationship <?= ops_admin_help_button('Relationship', 'This shows how the guardian claims authority over the child. Legal guardian records may require stronger supporting evidence.') ?></th>
          <th>Submitted</th>
          <th>Action <?= ops_admin_help_button('Action', 'Use Register to create the formal child record from a submitted vault application. Dismiss only when the submission has already been handled elsewhere.') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($vaultSubmissions as $vs):
        $age = $vs['child_dob'] ? (int)date_diff(new DateTime($vs['child_dob']), new DateTime())->y : null;
        $ageStr = $age !== null ? $age . ' yrs' : '—';
        $dobStr = $vs['child_dob'] ? date('d M Y', strtotime($vs['child_dob'])) : '—';
        $relMap = ['parent'=>'Parent','grandparent'=>'Grandparent','legal_guardian'=>'Legal Guardian ⚠'];
        $relLabel = $relMap[$vs['guardian_relationship'] ?? ''] ?? h($vs['guardian_relationship'] ?? '—');
        $guardianUrl = './members.php?search=' . urlencode($vs['guardian_member_number'] ?? '');
      ?>
        <tr style="background:rgba(212,178,92,.03)">
          <td style="font-size:12px;color:var(--muted)">#<?=(int)$vs['id']?></td>
          <td>
            <div style="font-size:13px;font-weight:600"><?=h($vs['guardian_full_name']??'—')?></div>
            <div style="font-size:11px;color:var(--muted)"><?=h($vs['guardian_member_number']??'')?></div>
            <div style="font-size:11px;color:var(--muted)"><?=h($vs['guardian_email']??'')?></div>
          </td>
          <td style="font-size:13px;font-weight:600"><?=h($vs['child_full_name']??'—')?></td>
          <td style="font-size:12px"><?=$dobStr?> <span style="color:var(--muted)">(<?=$ageStr?>)</span></td>
          <td style="font-size:12px"><?=$relLabel?></td>
          <td style="font-size:11px;color:var(--muted)"><?=h(substr($vs['created_at']??'',0,16))?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <!-- Pre-fill the register form with this submission's data -->
              <a href="?section=register&prefill_app_id=<?=(int)$vs['id']?>&prefill_guardian_id=<?=(int)$vs['guardian_member_id']?>&prefill_child_name=<?=urlencode($vs['child_full_name']??'')?>&prefill_child_dob=<?=urlencode($vs['child_dob']??'')?>&prefill_rel=<?=urlencode($vs['guardian_relationship']??'parent')?>"
                 style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(82,184,122,.12);color:#7ee0a0;border:1px solid rgba(82,184,122,.25);text-decoration:none;white-space:nowrap">
                Register →
              </a>
              <form method="post" style="display:inline">
                <input type="hidden" name="_csrf" value="<?=h(admin_csrf_token())?>">
                <input type="hidden" name="action" value="dismiss_vault_submission">
                <input type="hidden" name="app_id" value="<?=(int)$vs['id']?>">
                <button type="submit" onclick="return confirm('Mark this vault submission as processed (dismiss from queue)?')"
                  style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(255,255,255,.04);color:var(--muted);border:1px solid var(--line);cursor:pointer">
                  Dismiss
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Registration list -->
<form method="get" style="margin-bottom:0">
<div class="filter-bar">
  <div class="filter-group">
    <label>Child or guardian name / member no.</label>
    <input type="text" name="search" value="<?=h($fSearch)?>" placeholder="Search…" style="min-width:220px">
  </div>
  <div class="filter-group">
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach (['pending','doc_required','verified','issued','converted','rejected'] as $st): ?>
        <option value="<?=h($st)?>"<?=$fStatus===$st?' selected':''?>><?=h(str_replace('_',' ',$st))?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end">
    <button type="submit" class="btn btn-sm" style="background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)">Filter</button>
    <a href="kids.php" class="btn btn-sm">Reset</a>
  </div>
  <?php if ($fSearch !== '' || $fStatus !== ''): ?>
    <span style="font-size:11px;color:var(--gold);align-self:center"><?=count($registrations)?> result<?=count($registrations)!==1?'s':''?></span>
  <?php endif; ?>
</div>
</form>
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0;font-size:15px;font-weight:700">All Registrations</h2>
  </div>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Guardian</th>
          <th>Child</th>
          <th>DOB / Age</th>
          <th>Relationship</th>
          <th>Status <?= ops_admin_help_button('Status', 'Status shows where the child registration sits in the lifecycle: pending, verified, issued, or converted.') ?></th>
          <th>Token <?= ops_admin_help_button('Token', 'Shows whether the child-linked kS-NFT has actually been issued. Verification must happen first.') ?></th>
          <th>Converts <?= ops_admin_help_button('Converts', 'This shows the expected or actual age-18 conversion timing for the child record.') ?></th>
          <th style="width:200px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($registrations as $r):
        $statusClass = 'badge-' . str_replace('_','-',(string)$r['status']);
        $ageYrs = $r['child_dob'] ? (int)date_diff(new DateTime($r['child_dob']), new DateTime())->y : '?';
        $convTs = $r['conversion_due_date'] ? strtotime($r['conversion_due_date']) : 0;
        $convSoon = $convTs && $convTs <= strtotime('+90 days') && $r['conversion_status'] === 'pending';
      ?>
      <tr>
        <td style="font-size:11px;color:var(--muted)">#<?=(int)$r['id']?></td>
        <td>
          <div style="font-weight:600;font-size:13px"><?=h($r['guardian_name']??'')?></div>
          <div style="font-size:11px;color:var(--muted);font-family:monospace"><?=h($r['guardian_member_number']??'')?></div>
        </td>
        <td>
          <div style="font-weight:600;font-size:13px"><?=h($r['child_full_name']??'')?></div>
          <?php if($r['child_member_number']): ?>
            <div style="font-size:11px;color:var(--muted);font-family:monospace"><?=h($r['child_member_number'])?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?=h($r['child_dob']??'')?>
          <div style="color:var(--muted);font-size:11px">Age <?=$ageYrs?></div>
        </td>
        <td style="font-size:12px"><?=h(ucfirst(str_replace('_',' ',$r['relationship_type']??'')))?></td>
        <td><span class="badge <?=$statusClass?>"><?=h(str_replace('_',' ',$r['status']??''))?></span></td>
        <td style="font-size:12px">
          <?php if($r['token_issued']): ?>
            <span style="color:#7ee0a0">✓ Issued</span>
            <div style="font-size:11px;color:var(--muted)"><?=h(substr($r['token_issued_at']??'',0,10))?></div>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?php if($r['conversion_due_date']): ?>
            <span class="<?=$convSoon?'conversion-alert':''?>"><?=h($r['conversion_due_date'])?></span>
            <?php if($r['conversion_status']==='converted'): ?>
              <div style="font-size:11px;color:#d4a8e8">✓ Converted</div>
            <?php elseif($convSoon): ?>
              <div style="font-size:11px;color:#ffa040">⚠ Due soon</div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if(in_array($r['status'],['pending','doc_required'],true)): ?>
              <?php if(empty($r['supporting_doc_hash'])): ?>
                <button class="btn secondary sm" style="border-color:#f0a030;color:#f0a030" onclick="openUploadDocDrawer(<?=(int)$r['id']?>, '<?=h($r['child_full_name'])?>')">⚠ Upload ID doc</button>
              <?php else: ?>
                <span style="font-size:11px;color:#52c47a">✓ Doc on file</span>
              <?php endif; ?>
              <button class="btn secondary sm" onclick="openVerifyDrawer(<?=(int)$r['id']?>, '<?=h($r['child_full_name'])?>', <?=empty($r['supporting_doc_hash'])?'false':'true'?>)">Verify</button>
              <button class="btn danger sm" onclick="openRejectDrawer(<?=(int)$r['id']?>, '<?=h($r['child_full_name'])?>')">Reject</button>
            <?php endif; ?>
            <?php if($r['status']==='verified'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Issue kS-NFT token for <?=h($r['child_full_name'])?>?')">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="issue_token">
                <input type="hidden" name="reg_id" value="<?=(int)$r['id']?>">
                <button type="submit" class="btn sm">Issue Token</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; if(!$registrations): ?>
        <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">No registrations yet. Click <strong>+ Register Child</strong> to begin.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Info panel -->
<div class="info-box">
  <strong style="color:var(--gold)">MD clause 25 — Kids kS-NFT rules:</strong><br>
  · Acquired by existing member on behalf of child or grandchild at <strong>$1.00 fixed</strong> (entrenched cl.35(x))<br>
  · Soulbound — non-transferable in all circumstances (cl.25.2)<br>
  · Guardian holds proxy vote until child turns 18 (cl.25.3)<br>
  · Auto-converts to full Personal S-NFT on 18th birthday (cl.25.4)<br>
  · Sub-Trust B income held in trust for child until age 18 (cl.25.5)<br>
  · If child's address is in an Affected Zone, guardian exercises Residential Weighted Vote by proxy (cl.12A.6)
</div>

</main>
</div>

<!-- Register Child Drawer -->
<div class="drawer-bg" id="register-drawer" onclick="if(event.target===this)closeDrawer('register-drawer')">
  <div class="drawer">
    <button class="close-x" onclick="closeDrawer('register-drawer')">✕</button>
    <h3>Register Child for kS-NFT</h3>

    <div class="info-box">Guardian must be an existing active S-NFT member. The $1.00 token fee is fixed and entrenched (MD cl.35(x)).</div>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="register_kid">
      <input type="hidden" name="guardian_member_id" id="guardian_member_id" value="">

      <label>Search guardian (name, member #, or mobile)</label>
      <div style="display:flex;gap:6px;margin-bottom:4px">
        <input type="text" id="guardian_search" placeholder="e.g. Thomas or 608200..." style="flex:1">
        <button type="button" class="btn secondary sm" onclick="searchGuardian()">Search</button>
      </div>
      <?php if($guardianSearch && $guardians): ?>
      <div id="guardian_search_results" style="background:#0f1720;border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:8px">
        <?php foreach($guardians as $g): ?>
          <div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--line);font-size:13px"
               onclick="fillGuardian(<?=(int)$g['id']?>, '<?=h($g['member_number'])?>', '<?=h($g['full_name'])?>')">
            <strong><?=h($g['full_name'])?></strong>
            <span style="color:var(--muted);margin-left:8px;font-family:monospace;font-size:11px"><?=h($g['member_number'])?></span>
            <span style="color:var(--muted);margin-left:8px;font-size:11px"><?=h($g['mobile']??'')?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php elseif($guardianSearch): ?>
      <div style="font-size:12px;color:var(--muted);margin-bottom:8px">No members found for "<?=h($guardianSearch)?>"</div>
      <?php endif; ?>
      <div id="guardian_display" style="font-size:12px;color:#7ee0a0;margin-bottom:12px;min-height:16px"><?php if($prefillGuardian): echo h($prefillGuardian['full_name'] . ' (' . $prefillGuardian['member_number'] . ')'); endif; ?></div>

      <div class="spacer"></div>
      <label>Relationship to child</label>
      <select name="relationship_type">
        <option value="parent" <?=$prefillRel==='parent'?'selected':''?>>Parent (natural or adoptive)</option>
        <option value="grandparent" <?=$prefillRel==='grandparent'?'selected':''?>>Grandparent (MD cl.25.1 explicit)</option>
        <option value="legal_guardian" <?=$prefillRel==='legal_guardian'?'selected':''?>>Legal Guardian (court order required)</option>
      </select>

      <div class="spacer"></div>
      <label>Child's full name</label>
      <input name="child_full_name" placeholder="As per birth certificate" value="<?=h($prefillName)?>">

      <div class="spacer"></div>
      <div class="grid-2">
        <div><label>First name</label><input name="child_first_name"></div>
        <div><label>Last name</label><input name="child_last_name"></div>
      </div>

      <div class="spacer"></div>
      <label>Child's date of birth</label>
      <input type="date" name="child_dob" max="<?=date('Y-m-d')?>" value="<?=h($prefillDob)?>">
      <div style="font-size:11px;color:var(--muted);margin-top:4px">Must be under 18. Token auto-converts to S-NFT on 18th birthday (MD cl.25.4).</div>
      <?php if($prefillAppId > 0): ?>
      <input type="hidden" name="source_app_id" value="<?=$prefillAppId?>">
      <?php endif; ?>

      <div class="spacer"></div>
      <label>Admin notes (optional)</label>
      <textarea name="notes" placeholder="Document checks, supporting evidence references, etc."></textarea>

      <div class="spacer"></div>
      <div style="padding:12px 14px;background:rgba(212,178,92,.06);border:1px solid rgba(212,178,92,.2);border-radius:10px;margin-bottom:14px">
        <label style="display:flex;align-items:flex-start;gap:10px;margin:0">
          <input type="checkbox" name="guardian_declaration_accepted" value="1"
                 style="width:auto;margin-top:2px;flex-shrink:0">
          <span style="font-size:12px;color:var(--muted);line-height:1.6">
            I confirm that the guardian named above is a verified COG$ member and has declared they are the legal parent, grandparent, or appointed guardian of the child named. For legal guardian registrations, supporting documentation (court order or statutory declaration) will be required before token issuance.
          </span>
        </label>
      </div>

      <button type="submit">Create Registration</button>
    </form>
  </div>
</div>

<!-- Verify Drawer -->
<div class="drawer-bg" id="verify-drawer" onclick="if(event.target===this)closeDrawer('verify-drawer')">
  <div class="drawer">
    <button class="close-x" onclick="closeDrawer('verify-drawer')">✕</button>
    <h3 id="verify-drawer-title">Verify Registration</h3>
    <div class="info-box">
      Verification confirms: (1) guardian is active S-NFT member ✓ (2) child is under 18 ✓ (3) identity evidence reviewed. Once verified, a child member number is assigned and the token is ready to issue.
    </div>
    <div id="verify-no-doc-warning" style="display:none;background:rgba(240,130,20,.1);border:1px solid rgba(240,130,20,.4);border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px;color:#f0a030">
      ⚠ <strong>No identity document on file.</strong> You must either upload a document first, or enter a mandatory override reason below explaining how identity was verified by other means.
    </div>
    <div id="verify-doc-ok" style="display:none;background:rgba(82,196,122,.08);border:1px solid rgba(82,196,122,.3);border-radius:8px;padding:10px;margin-bottom:14px;font-size:13px;color:#52c47a">
      ✓ Identity document on file. Verification can proceed.
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="verify_registration">
      <input type="hidden" name="reg_id" id="verify-reg-id" value="">
      <label>Verification notes</label>
      <textarea name="notes" placeholder="Documents checked, relationship confirmed, etc."></textarea>
      <div id="override-section" style="margin-top:12px">
        <label>KYC override reason <span style="color:#f0a030;font-size:11px">(required if no document on file)</span></label>
        <textarea name="kyc_override_reason" id="kyc-override-reason" placeholder="e.g. Identity confirmed via in-person attendance. Certified copy of birth certificate sighted and returned. Guardian Medicare card reviewed in person." rows="3"></textarea>
      </div>
      <div class="spacer"></div>
      <button type="submit">Confirm Verification</button>
    </form>
  </div>
</div>

<!-- Upload KYC Doc Drawer -->
<div class="drawer-bg" id="upload-doc-drawer" onclick="if(event.target===this)closeDrawer('upload-doc-drawer')">
  <div class="drawer">
    <button class="close-x" onclick="closeDrawer('upload-doc-drawer')">✕</button>
    <h3 id="upload-doc-drawer-title">Upload Identity Document</h3>
    <div class="info-box">
      Upload the identity evidence for this registration. Accepted: Medicare card (family), birth certificate, passport, court order, or statutory declaration. The file is SHA-256 hashed and stored in the private document vault. Max 10 MB. PDF, JPG, PNG, or WEBP.
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="upload_kyc_doc">
      <input type="hidden" name="reg_id" id="upload-doc-reg-id" value="">
      <label>Document type</label>
      <select name="doc_type" style="width:100%;margin-bottom:14px;padding:8px;border-radius:8px;background:var(--panel);border:1px solid var(--line);color:var(--text)">
        <option value="medicare_card">Medicare card (family)</option>
        <option value="birth_certificate">Birth certificate</option>
        <option value="passport">Passport</option>
        <option value="court_order">Court order</option>
        <option value="statutory_declaration">Statutory declaration</option>
        <option value="other">Other</option>
      </select>
      <label>Document file</label>
      <input type="file" name="kyc_doc" accept="application/pdf,image/jpeg,image/png,image/webp" required style="width:100%;margin-bottom:14px">
      <div class="spacer"></div>
      <button type="submit">Upload and hash document</button>
    </form>
  </div>
</div>

<!-- Reject Drawer -->
<div class="drawer-bg" id="reject-drawer" onclick="if(event.target===this)closeDrawer('reject-drawer')">
  <div class="drawer">
    <button class="close-x" onclick="closeDrawer('reject-drawer')">✕</button>
    <h3 id="reject-drawer-title">Reject Registration</h3>
    <div class="warning-box">This action is logged and cannot be undone. Provide a clear reason.</div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="reject_registration">
      <input type="hidden" name="reg_id" id="reject-reg-id" value="">
      <label>Reason for rejection (required)</label>
      <textarea name="rejected_reason" placeholder="e.g. Child is over 18. Guardian relationship not established. Missing court order." required></textarea>
      <div class="spacer"></div>
      <button type="submit" class="btn danger">Reject Registration</button>
    </form>
  </div>
</div>

<script>
function openVerifyDrawer(id, name, hasDoc){
  document.getElementById('verify-reg-id').value = id;
  document.getElementById('verify-drawer-title').textContent = 'Verify Registration — ' + name;
  var noDocWarn = document.getElementById('verify-no-doc-warning');
  var docOk     = document.getElementById('verify-doc-ok');
  if(hasDoc){
    if(noDocWarn) noDocWarn.style.display = 'none';
    if(docOk)     docOk.style.display     = '';
  } else {
    if(noDocWarn) noDocWarn.style.display = '';
    if(docOk)     docOk.style.display     = 'none';
  }
  openDrawer('verify-drawer');
}
function openUploadDocDrawer(id, name){
  document.getElementById('upload-doc-reg-id').value = id;
  document.getElementById('upload-doc-drawer-title').textContent = 'Upload Identity Document — ' + name;
  openDrawer('upload-doc-drawer');
}
function openRejectDrawer(id, name){
  document.getElementById('reject-reg-id').value = id;
  document.getElementById('reject-drawer-title').textContent = 'Reject Registration — ' + name;
  openDrawer('reject-drawer');
}
</script>
</body>
</html>

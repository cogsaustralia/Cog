<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
if (file_exists(__DIR__ . '/includes/LedgerEmitter.php'))   require_once __DIR__ . '/includes/LedgerEmitter.php';
if (file_exists(__DIR__ . '/includes/AccountingHooks.php')) require_once __DIR__ . '/includes/AccountingHooks.php';
require_once dirname(__DIR__) . '/_app/api/config/bootstrap.php';
require_once dirname(__DIR__) . '/_app/api/integrations/mailer.php';

ops_require_admin();
$pdo = ops_db();

// ── Helpers ───────────────────────────────────────────────────────────────────
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('rows')) {
    function rows(PDO $pdo, string $sql, array $p = []): array {
        $st = $pdo->prepare($sql); $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('one')) {
    function one(PDO $pdo, string $sql, array $p = []): ?array {
        $st = $pdo->prepare($sql); $st->execute($p);
        $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
}
if (!function_exists('now_dt')) {
    function now_dt(): string { return date('Y-m-d H:i:s'); }
}

// ── Class definitions ─────────────────────────────────────────────────────────
if (!function_exists('uir_class_defs')) {
function uir_class_defs(): array {
    return [
        'S'  => ['name' => 'Personal S-NFT COG$',       'gate' => 1, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual'],
        'B'  => ['name' => 'Business B-NFT COG$',        'gate' => 1, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual'],
        'kS' => ['name' => 'Kids S-NFT COG$',            'gate' => 2, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual'],
        'C'  => ['name' => 'Community COG$',             'gate' => 2, 'cert_type' => 'community',             'payment_required' => false, 'initial_units' => null, 'issue_trigger' => 'standing_poll_cl23D3'],
        'P'  => ['name' => 'Pay It Forward COG$',        'gate' => 2, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual'],
        'D'  => ['name' => 'Donation COG$',              'gate' => 2, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual'],
        'Lr' => ['name' => 'Resident COG$',              'gate' => 2, 'cert_type' => 'governance_allocation', 'payment_required' => false, 'initial_units' => 1000, 'issue_trigger' => 'auto_zone_allocation'],
        'A'  => ['name' => 'ASX COG$',                   'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => null, 'issue_trigger' => 'trustee_manual'],
        'Lh' => ['name' => 'Landholder COG$',            'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => null, 'issue_trigger' => 'trustee_manual'],
        'BP' => ['name' => 'Business Property COG$',     'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => null, 'issue_trigger' => 'trustee_manual'],
        'R'  => ['name' => 'RWA COG$',                   'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'initial_units' => null, 'issue_trigger' => 'trustee_resolution_rwa'],
    ];
}
}

if (!function_exists('uir_class_is_open')) {
function uir_class_is_open(array $def, bool $gate2Open): bool {
    if ($def['gate'] === 1) return true;
    if ($def['gate'] === 2) return $gate2Open;
    return false;
}
}

if (!function_exists('uir_next_ref')) {
function uir_next_ref(PDO $pdo, string $prefix, string $table, string $col): string {
    $row = one($pdo, "SELECT MAX(CAST(SUBSTRING({$col}, LENGTH(?)+2) AS UNSIGNED)) AS n FROM `{$table}` WHERE {$col} LIKE ?",
               [$prefix, "{$prefix}-%"]);
    $n = (int)($row['n'] ?? 0) + 1;
    return $prefix . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}
}

if (!function_exists('uir_build_hash')) {
function uir_build_hash(string $registerRef, int $memberId, string $unitClassCode,
                        string $unitsIssued, string $issueDate, int $considerationCents): string {
    return hash('sha256', implode('|', [$registerRef, $memberId, $unitClassCode,
                                        $unitsIssued, $issueDate, $considerationCents]));
}
}

if (!function_exists('uir_eligible_members')) {
function uir_eligible_members(PDO $pdo, string $unitClassCode, bool $gate2Open, bool $tablesReady): array {
    $defs = uir_class_defs();
    $def  = $defs[$unitClassCode] ?? null;
    if (!$def || !uir_class_is_open($def, $gate2Open)) return [];

    $issuedIds = [];
    if ($tablesReady) {
        $issued = rows($pdo, "SELECT member_id FROM unit_issuance_register WHERE unit_class_code = ?", [$unitClassCode]);
        $issuedIds = array_column($issued, 'member_id');
    }

    if ($unitClassCode === 'C') {
        $rows = $tablesReady ? rows($pdo,
            "SELECT DISTINCT m.id, m.member_number, m.full_name, m.email, m.member_type,
                    m.kyc_status, m.signup_payment_status
             FROM members m
             INNER JOIN unit_issuance_register uir ON uir.member_id = m.id AND uir.unit_class_code IN ('S','B')
             WHERE m.is_active = 1 ORDER BY m.id ASC") : [];
    } elseif ($unitClassCode === 'S') {
        $rows = rows($pdo,
            "SELECT id, member_number, full_name, email, member_type, kyc_status, signup_payment_status
             FROM members WHERE is_active = 1 AND member_type = 'personal' ORDER BY id ASC");
    } elseif ($unitClassCode === 'B') {
        $rows = rows($pdo,
            "SELECT id, member_number, full_name, email, member_type, kyc_status, signup_payment_status
             FROM members WHERE is_active = 1 AND member_type = 'business' ORDER BY id ASC");
    } else {
        $rows = rows($pdo,
            "SELECT id, member_number, full_name, email, member_type, kyc_status, signup_payment_status
             FROM members WHERE is_active = 1 ORDER BY id ASC");
    }

    return array_values(array_filter($rows, function($r) use ($issuedIds) {
        return !in_array((int)$r['id'], $issuedIds);
    }));
}
}

if (!function_exists('uir_preconditions')) {
function uir_preconditions(PDO $pdo, array $member, string $unitClassCode,
                           bool $gate2Open, int $unitsRequested, bool $tablesReady): array {
    $defs    = uir_class_defs();
    $def     = $defs[$unitClassCode];
    $checks  = [];
    $allPass = true;

    $gateOpen  = uir_class_is_open($def, $gate2Open);
    $gateLabel = $def['gate'] === 1 ? 'Gate 1 (Declaration executed)'
               : ($def['gate'] === 2 ? 'Gate 2 (Foundation Day)' : 'Gate 3 (Expansion Day)');
    $checks[] = ['label' => $gateLabel, 'ok' => $gateOpen,
                 'note'  => $gateOpen ? 'Open' : 'Not yet reached'];
    if (!$gateOpen) $allPass = false;

    // KYC/AML-CTF: read from snft_memberships (identity KYC table).
    // Passes if: snft kyc_status='verified' OR members.id_verified=1 OR members.manual_id_verified_at IS NOT NULL.
    // address_verified (GnafAddressAgent) is NOT identity KYC and does not satisfy this condition.
    $snftKyc = one($pdo,
        "SELECT kyc_status, kyc_verified_at FROM snft_memberships WHERE member_number = ? LIMIT 1",
        [(string)($member['member_number'] ?? '')]);
    $snftVerified  = !empty($snftKyc) && (string)($snftKyc['kyc_status'] ?? '') === 'verified';
    $idVerified    = (int)($member['id_verified'] ?? 0) === 1;
    $manualVerified= !empty($member['manual_id_verified_at']);
    $kycOk         = $snftVerified || $idVerified || $manualVerified;
    $kycNote = $kycOk
        ? ($snftVerified ? 'Medicare KYC verified (' . substr((string)($snftKyc['kyc_verified_at'] ?? ''), 0, 10) . ')'
            : ($idVerified ? 'ID manually verified' : 'Manual verification recorded'))
        : 'Identity KYC not completed — Medicare KYC or manual admin verification required';
    $checks[] = ['label' => 'KYC/AML-CTF identity verified', 'ok' => $kycOk, 'note' => $kycNote];
    if (!$kycOk) $allPass = false;

    if ($def['payment_required']) {
        $payOk = (string)($member['signup_payment_status'] ?? '') === 'paid';
        $checks[] = ['label' => 'Payment cleared', 'ok' => $payOk,
                     'note'  => $payOk ? 'Cleared' : 'Awaiting payment'];
        if (!$payOk) $allPass = false;
    } else {
        $checks[] = ['label' => 'Payment', 'ok' => true, 'note' => 'No monetary consideration for this class'];
    }

    $currentTotal = 0.0;
    if ($tablesReady) {
        $capRow = one($pdo, "SELECT COALESCE(SUM(units_issued),0) AS total FROM unit_issuance_register WHERE member_id = ? AND unit_class_code NOT IN ('Lr')", [(int)$member['id']]);
        $currentTotal = (float)($capRow['total'] ?? 0);
    }
    $capOk = ($currentTotal + $unitsRequested) <= 1000000;
    $checks[] = ['label' => 'Anti-capture cap (1,000,000)', 'ok' => $capOk,
                 'note'  => "Current: {$currentTotal} + {$unitsRequested} = " . ($currentTotal + $unitsRequested) . ($capOk ? ' ≤ cap' : ' EXCEEDS CAP')];
    if (!$capOk) $allPass = false;

    $existing = $tablesReady ? one($pdo, "SELECT id FROM unit_issuance_register WHERE member_id = ? AND unit_class_code = ? LIMIT 1", [(int)$member['id'], $unitClassCode]) : null;
    $notDup = ($existing === null);
    $checks[] = ['label' => 'No duplicate issuance', 'ok' => $notDup,
                 'note'  => $notDup ? 'No prior issuance for this class' : 'Already issued — blocked'];
    if (!$notDup) $allPass = false;

    return ['pass' => $allPass, 'checks' => $checks];
}
}

// ── Gate state ────────────────────────────────────────────────────────────────
$fdDeclared = ops_setting_get($pdo, 'governance_foundation_day_declared', '') === 'yes';
$fdDate     = ops_setting_get($pdo, 'governance_foundation_day_date', '');
$gate2Open  = $fdDeclared && $fdDate !== '' && date('Y-m-d') >= $fdDate;

// ── Table existence guard ─────────────────────────────────────────────────────
$tablesReady = ops_has_table($pdo, 'unit_issuance_register') && ops_has_table($pdo, 'unitholder_certificates');

// ── POST handler ──────────────────────────────────────────────────────────────
$flash = ''; $flashType = 'ok'; $error = '';
$adminId = function_exists('ops_current_admin_id') ? (int)ops_current_admin_id($pdo) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        if (!$tablesReady) throw new RuntimeException('SQL migration not yet run. Run both migration files via phpMyAdmin first.');
        $action        = (string)($_POST['action'] ?? '');
        $memberId      = (int)($_POST['member_id'] ?? 0);
        $unitClassCode = trim((string)($_POST['unit_class_code'] ?? ''));

        // ── Bulk issue all eligible members for a class ──────────────────────
        if ($action === 'issue_class_all') {
            if ($unitClassCode === '') throw new RuntimeException('Unit class required.');
            $defs = uir_class_defs();
            if (!isset($defs[$unitClassCode])) throw new RuntimeException('Unknown unit class code.');
            $def = $defs[$unitClassCode];
            if (!uir_class_is_open($def, $gate2Open)) throw new RuntimeException("Class {$unitClassCode} gate is not yet open.");

            $eligible = uir_eligible_members($pdo, $unitClassCode, $gate2Open, $tablesReady);
            if (empty($eligible)) throw new RuntimeException('No eligible members found for this class.');

            $issued = []; $skipped = [];
            foreach ($eligible as $member) {
                $unitsIssued = ($unitClassCode === 'C')
                    ? ($member['member_type'] === 'business' ? 10000.0 : 1000.0)
                    : (float)($def['initial_units'] ?? 1);

                $pre = uir_preconditions($pdo, $member, $unitClassCode, $gate2Open, (int)$unitsIssued, $tablesReady);
                if (!$pre['pass']) {
                    $failed = array_filter($pre['checks'], function($c) { return !$c['ok']; });
                    $skipped[] = $member['full_name'] . ' (' . implode(', ', array_column($failed, 'label')) . ')';
                    continue;
                }

                if ($def['payment_required']) {
                    $tc = one($pdo, "SELECT unit_price_cents, business_unit_price_cents FROM token_classes WHERE unit_class_code = ? LIMIT 1", [$unitClassCode]);
                    $considerationCents = ($member['member_type'] === 'business' && $tc && $tc['business_unit_price_cents'] !== null)
                        ? (int)$tc['business_unit_price_cents'] : (int)($tc['unit_price_cents'] ?? 0);
                } else {
                    $considerationCents = 0;
                }

                $issueDate = date('Y-m-d');
                $unitsStr  = number_format($unitsIssued, 4, '.', '');

                $pdo->beginTransaction();
                try {
                    $registerRef = uir_next_ref($pdo, 'UIR-' . strtoupper($unitClassCode), 'unit_issuance_register', 'register_ref');
                    $certRef     = uir_next_ref($pdo, 'CERT-' . strtoupper($unitClassCode), 'unitholder_certificates', 'cert_ref');
                    $hash        = uir_build_hash($registerRef, (int)$member['id'], $unitClassCode, $unitsStr, $issueDate, $considerationCents);

                    $pdo->prepare("INSERT INTO unit_issuance_register
                        (register_ref, member_id, unit_class_code, unit_class_name, cert_type,
                         units_issued, consideration_cents, issue_date, issue_trigger, gate,
                         kyc_verified, payment_cleared, anti_cap_checked, gate_satisfied,
                         sha256_hash, issued_by_admin_id, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$registerRef, (int)$member['id'], $unitClassCode, $def['name'], $def['cert_type'],
                                   $unitsStr, $considerationCents, $issueDate, $def['issue_trigger'], $def['gate'],
                                   1, $def['payment_required'] ? 1 : 0, 1, 1,
                                   $hash, $adminId ?: null, now_dt(), now_dt()]);
                    $issuanceId = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO unitholder_certificates
                        (cert_ref, issuance_id, member_id, unit_class_code, cert_type, units, issue_date, email_sent_to, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$certRef, $issuanceId, (int)$member['id'], $unitClassCode, $def['cert_type'],
                                   $unitsStr, $issueDate, $member['email'], now_dt()]);
                    $certId = (int)$pdo->lastInsertId();

                    $pdo->prepare("UPDATE unit_issuance_register SET certificate_sent_at = ? WHERE id = ?")
                        ->execute([now_dt(), $issuanceId]);

                    $emailQueueId = queueEmail($pdo, 'unit_certificate', $issuanceId, (string)$member['email'],
                        'unitholder_certificate', "COG\$ Certificate of Unit Holding — {$def['name']} — {$certRef}",
                        ['full_name' => $member['full_name'], 'first_name' => $member['first_name'] ?? '',
                         'email' => $member['email'], 'member_number' => $member['member_number'],
                         'unit_class_code' => $unitClassCode, 'unit_class_name' => $def['name'],
                         'cert_type' => $def['cert_type'], 'units_issued' => $unitsStr,
                         'issue_date' => $issueDate, 'register_ref' => $registerRef,
                         'cert_ref' => $certRef, 'sha256_hash' => $hash,
                         'consideration_cents' => $considerationCents, 'issue_trigger' => $def['issue_trigger'],
                         'gate' => $def['gate'], 'member_type' => $member['member_type']]);
                    if ($emailQueueId > 0) {
                        $pdo->prepare("UPDATE unitholder_certificates SET email_sent_at = ?, email_queue_id = ? WHERE id = ?")
                            ->execute([now_dt(), $emailQueueId, $certId]);
                    }

                    $pdo->commit();
                    $issued[] = ['name' => $member['full_name'], 'register_ref' => $registerRef,
                                 'cert_ref' => $certRef, 'units' => $unitsStr];
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $skipped[] = $member['full_name'] . ' (error: ' . $ex->getMessage() . ')';
                }
            }

            // Flush email queue for all issued certs
            if (!empty($issued)) {
                processEmailQueue($pdo, count($issued));
            }

            $successUrl = admin_url('unit_issuance.php')
                . '?tab=issued_bulk'
                . '&class_code='   . urlencode($unitClassCode)
                . '&class_name='   . urlencode($def['name'])
                . '&issued_count=' . urlencode((string)count($issued))
                . '&skipped_count='. urlencode((string)count($skipped))
                . '&skipped_list=' . urlencode(implode('|', $skipped));
            header('Location: ' . $successUrl);
            exit;
        }

        if ($action === 'issue_unit') {
            if ($memberId <= 0) throw new RuntimeException('Member ID required.');
            if ($unitClassCode === '') throw new RuntimeException('Unit class required.');
            $defs = uir_class_defs();
            if (!isset($defs[$unitClassCode])) throw new RuntimeException('Unknown unit class code.');
            $def = $defs[$unitClassCode];
            if (!uir_class_is_open($def, $gate2Open)) throw new RuntimeException("Class {$unitClassCode} gate is not yet open.");

            $member = one($pdo, "SELECT * FROM members WHERE id = ? AND is_active = 1 LIMIT 1", [$memberId]);
            if (!$member) throw new RuntimeException('Member not found or inactive.');

            if ($unitClassCode === 'C') {
                $unitsIssued = $member['member_type'] === 'business' ? 10000.0 : 1000.0;
            } elseif ($def['initial_units'] !== null) {
                $unitsIssued = (float)$def['initial_units'];
            } else {
                $unitsIssued = (float)(int)($_POST['units'] ?? 1);
                if ($unitsIssued <= 0) throw new RuntimeException('Invalid unit quantity.');
            }

            $pre = uir_preconditions($pdo, $member, $unitClassCode, $gate2Open, (int)$unitsIssued, $tablesReady);
            if (!$pre['pass']) {
                $failed = array_filter($pre['checks'], function($c) { return !$c['ok']; });
                throw new RuntimeException('Pre-conditions not met: ' . implode('; ', array_column($failed, 'label')));
            }

            if ($def['payment_required']) {
                $tc = one($pdo, "SELECT unit_price_cents, business_unit_price_cents FROM token_classes WHERE unit_class_code = ? LIMIT 1", [$unitClassCode]);
                $considerationCents = ($member['member_type'] === 'business' && $tc && $tc['business_unit_price_cents'] !== null)
                    ? (int)$tc['business_unit_price_cents'] : (int)($tc['unit_price_cents'] ?? 0);
            } else {
                $considerationCents = 0;
            }

            $issueDate   = date('Y-m-d');
            $unitsStr    = number_format($unitsIssued, 4, '.', '');

            $pdo->beginTransaction();

            $registerRef = uir_next_ref($pdo, 'UIR-' . strtoupper($unitClassCode), 'unit_issuance_register', 'register_ref');
            $certRef     = uir_next_ref($pdo, 'CERT-' . strtoupper($unitClassCode), 'unitholder_certificates', 'cert_ref');
            $hash        = uir_build_hash($registerRef, $memberId, $unitClassCode, $unitsStr, $issueDate, $considerationCents);

            $pdo->prepare("INSERT INTO unit_issuance_register
                (register_ref, member_id, unit_class_code, unit_class_name, cert_type,
                 units_issued, consideration_cents, issue_date, issue_trigger, gate,
                 kyc_verified, payment_cleared, anti_cap_checked, gate_satisfied,
                 sha256_hash, issued_by_admin_id, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$registerRef, $memberId, $unitClassCode, $def['name'], $def['cert_type'],
                           $unitsStr, $considerationCents, $issueDate, $def['issue_trigger'], $def['gate'],
                           1, $def['payment_required'] ? 1 : 0, 1, 1,
                           $hash, $adminId ?: null, now_dt(), now_dt()]);
            $issuanceId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO unitholder_certificates
                (cert_ref, issuance_id, member_id, unit_class_code, cert_type, units, issue_date, email_sent_to, created_at)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$certRef, $issuanceId, $memberId, $unitClassCode, $def['cert_type'],
                           $unitsStr, $issueDate, $member['email'], now_dt()]);
            $certId = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE unit_issuance_register SET certificate_sent_at = ? WHERE id = ?")
                ->execute([now_dt(), $issuanceId]);

            $emailQueueId = queueEmail($pdo, 'unit_certificate', $issuanceId, (string)$member['email'],
                'unitholder_certificate', "COG\$ Certificate of Unit Holding — {$def['name']} — {$certRef}",
                ['full_name' => $member['full_name'], 'first_name' => $member['first_name'] ?? '',
                 'email' => $member['email'], 'member_number' => $member['member_number'],
                 'unit_class_code' => $unitClassCode, 'unit_class_name' => $def['name'],
                 'cert_type' => $def['cert_type'], 'units_issued' => $unitsStr,
                 'issue_date' => $issueDate, 'register_ref' => $registerRef,
                 'cert_ref' => $certRef, 'sha256_hash' => $hash,
                 'consideration_cents' => $considerationCents, 'issue_trigger' => $def['issue_trigger'],
                 'gate' => $def['gate'], 'member_type' => $member['member_type']]);
            if ($emailQueueId > 0) {
                $pdo->prepare("UPDATE unitholder_certificates SET email_sent_at = ?, email_queue_id = ? WHERE id = ?")
                    ->execute([now_dt(), $emailQueueId, $certId]);
            }

            $pdo->commit();
            // Send immediately — do not wait for cron
            processEmailQueue($pdo, 1);

            // PRG — redirect to clean success summary, no GET params that would re-show the issue form
            $successUrl = admin_url('unit_issuance.php')
                . '?tab=issued'
                . '&register_ref=' . urlencode($registerRef)
                . '&cert_ref='     . urlencode($certRef)
                . '&class_code='   . urlencode($unitClassCode)
                . '&class_name='   . urlencode($def['name'])
                . '&member_name='  . urlencode($member['full_name'])
                . '&member_num='   . urlencode($member['member_number'])
                . '&units='        . urlencode((string)$unitsIssued)
                . '&email_sent='   . ($emailQueueId > 0 ? '1' : '0');
            header('Location: ' . $successUrl);
            exit;
        }

        // ── Resend failed certificate emails ─────────────────────────────────
        if ($action === 'resend_certs') {
            $sent = 0;

            // Case 1: certs where email_queue_id IS NULL — never queued, build payload and send directly
            $unqueued = $pdo->query(
                "SELECT uc.id AS cert_id, uc.cert_ref, uc.unit_class_code, uc.cert_type,
                        uc.units, uc.issue_date, uc.issuance_id,
                        m.full_name, m.email, m.member_number, m.member_type,
                        uir.unit_class_name, uir.register_ref, uir.sha256_hash, uir.issue_trigger
                 FROM unitholder_certificates uc
                 INNER JOIN members m ON m.id = uc.member_id
                 INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
                 WHERE uc.email_queue_id IS NULL"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($unqueued as $row) {
                $defs    = uir_class_defs();
                $def     = $defs[$row['unit_class_code']] ?? [];
                $gate    = (int)($def['gate'] ?? 1);
                $payload = [
                    'full_name'        => $row['full_name'],
                    'first_name'       => explode(' ', trim($row['full_name']))[0],
                    'email'            => $row['email'],
                    'member_number'    => $row['member_number'],
                    'member_type'      => $row['member_type'],
                    'unit_class_code'  => $row['unit_class_code'],
                    'unit_class_name'  => $row['unit_class_name'],
                    'cert_type'        => $row['cert_type'],
                    'units_issued'     => (string)$row['units'],
                    'issue_date'       => $row['issue_date'],
                    'register_ref'     => $row['register_ref'],
                    'cert_ref'         => $row['cert_ref'],
                    'sha256_hash'      => $row['sha256_hash'],
                    'issue_trigger'    => $row['issue_trigger'],
                    'gate'             => $gate,
                    'consideration_cents' => 0,
                ];
                $subject = 'COG$ Certificate of Unit Holding — ' . $row['unit_class_name'] . ' — ' . $row['cert_ref'];
                $qid = queueEmail($pdo, 'unit_certificate', (int)$row['issuance_id'],
                    $row['email'], 'unitholder_certificate', $subject, $payload);
                if ($qid > 0) {
                    $pdo->prepare("UPDATE unitholder_certificates SET email_queue_id = ? WHERE id = ?")
                        ->execute([$qid, $row['cert_id']]);
                }
            }

            // Case 2: reset any failed/pending queue rows back to pending
            $pdo->prepare(
                "UPDATE email_queue SET status = 'pending', last_error = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE template_key = 'unitholder_certificate' AND status IN ('failed','pending')"
            )->execute();

            // Process up to 50 certificate emails now
            $result = processEmailQueue($pdo, 50);
            $sent   = (int)($result['processed'] ?? 0);
            header('Location: ' . admin_url('unit_issuance.php') . '?tab=certs&resend_result=' . $sent);
            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── View state ────────────────────────────────────────────────────────────────
$viewTab = in_array($_GET['tab'] ?? '', ['issue','register','certs','issued','issued_bulk'], true) ? $_GET['tab'] : 'issue';

// Success params (populated after PRG redirect)
$successRegRef   = (string)($_GET['register_ref'] ?? '');
$successCertRef  = (string)($_GET['cert_ref']     ?? '');
$successClass    = (string)($_GET['class_code']   ?? '');
$successName     = (string)($_GET['class_name']   ?? '');
$successMember   = (string)($_GET['member_name']  ?? '');
$successMemberNum= (string)($_GET['member_num']   ?? '');
$successUnits    = (string)($_GET['units']        ?? '');
$successEmailSent= ((string)($_GET['email_sent']  ?? '0')) === '1';

// Bulk success params
$bulkClass       = (string)($_GET['class_code']    ?? '');
$bulkClassName   = (string)($_GET['class_name']    ?? '');
$bulkIssuedCount = (int)($_GET['issued_count']     ?? 0);
$bulkSkippedCount= (int)($_GET['skipped_count']    ?? 0);
$bulkSkippedList = array_filter(explode('|', (string)($_GET['skipped_list'] ?? '')));

$totalIssued = $totalCerts = $pendingEmail = 0;
$classBreakdown = $recentIssuances = [];
if ($tablesReady) {
    $totalIssued  = (int)(one($pdo, "SELECT COUNT(*) AS n FROM unit_issuance_register")['n'] ?? 0);
    $totalCerts   = (int)(one($pdo, "SELECT COUNT(*) AS n FROM unitholder_certificates")['n'] ?? 0);
    $pendingEmail = (int)(one($pdo, "SELECT COUNT(*) AS n FROM unitholder_certificates WHERE email_sent_at IS NULL")['n'] ?? 0);
    $classBreakdown = rows($pdo,
        "SELECT unit_class_code, unit_class_name, COUNT(*) AS cnt, SUM(units_issued) AS total_units
         FROM unit_issuance_register GROUP BY unit_class_code, unit_class_name ORDER BY unit_class_code");
    $recentIssuances = rows($pdo,
        "SELECT uir.*, m.full_name, m.member_number, m.email, uc.cert_ref, uc.email_sent_at
         FROM unit_issuance_register uir
         INNER JOIN members m ON m.id = uir.member_id
         LEFT JOIN unitholder_certificates uc ON uc.issuance_id = uir.id
         ORDER BY uir.created_at DESC LIMIT 50");
}

$defs      = uir_class_defs();
$csrf      = h(admin_csrf_token());
$todayDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unit Issuance Register — COG$ Admin</title>
<style>
.gate-banner{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:600}
.gate-ok{background:rgba(82,184,122,.12);border:1px solid rgba(82,184,122,.3);color:#a0e8b8}
.gate-warn{background:rgba(200,144,26,.12);border:1px solid rgba(200,144,26,.3);color:#e8cc80}
.gate-err{background:rgba(196,96,96,.12);border:1px solid rgba(196,96,96,.3);color:#f0a0a0}
.tab-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.tab-bar a{display:inline-block;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:1px solid var(--line2);background:var(--panel2);color:var(--text);text-decoration:none}
.tab-bar a:hover{background:rgba(255,255,255,.08)}
.tab-bar a.active{background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)}
.check-pass{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:rgba(82,184,122,.12);color:#7ee0a0;border:1px solid rgba(82,184,122,.25)}
.check-fail{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:rgba(196,96,96,.12);color:#f0a0a0;border:1px solid rgba(196,96,96,.25)}
.sum-table td{padding:6px 16px 6px 0;font-size:13px;vertical-align:top}
.sum-table td:first-child{color:var(--sub);white-space:nowrap}
</style>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="admin-shell">
<?php admin_sidebar_render('unit_issuance'); ?>
<main class="main">

<div class="card" style="margin-bottom:18px;">
  <div class="card-body">
    <h1 style="margin:0 0 6px">Unit Issuance Register
      <?php echo ops_admin_help_button('Unit Issuance Register',
        'Trustee-triggered issuance of COG$ units and unitholder certificates. ' .
        'Issue Class S and Class B now (Gate 1 open). ' .
        'Class C, kS, P, D, Lr available from Governance Foundation Day (Gate 2). ' .
        'Tier 2 classes (A, Lh, BP, R) require Expansion Day. ' .
        'Each issuance writes a legal register record with SHA-256 hash and queues the certificate email.'); ?>
    </h1>
    <p class="muted">Formal legal register of all issued units under Sub-Trust A cl.11. Manual trigger by Trustee only.</p>
  </div>
</div>

<?php if (!$tablesReady): ?>
<div class="gate-banner gate-err">
  ⚠ <strong>SQL migration required</strong> — Tables not found.
  Run <code>2026_04_25_unit_issuance_register.sql</code> then <code>2026_04_25_unitholder_certificate_email_template.sql</code>
  via phpMyAdmin against <code>cogsaust_TRUST</code>, then reload.
</div>
<?php endif; ?>

<?php if ($flash !== ''): ?>
<div class="gate-banner gate-ok"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="gate-banner gate-err">Error: <?= h($error) ?></div>
<?php endif; ?>

<?php if ($tablesReady): ?>

<!-- Gate status -->
<div class="gate-banner <?= $gate2Open ? 'gate-ok' : 'gate-warn' ?>">
  <?php if ($gate2Open): ?>
    ✅ <strong>Gate 2 open</strong> — Foundation Day declared <?= h($fdDate) ?>. Classes kS, C, P, D, Lr available.
  <?php elseif ($fdDeclared && $fdDate !== ''): ?>
    ⏳ <strong>Gate 2 pending</strong> — Foundation Day scheduled <?= h($fdDate) ?>. Classes kS, C, P, D, Lr unlock then.
  <?php else: ?>
    ℹ Gate 2 not yet declared. Only Classes S and B (Gate 1) are available.
    <a href="<?= h(admin_url('foundation_day.php')) ?>" style="margin-left:10px;color:var(--gold)">Foundation Day →</a>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats" style="margin-bottom:20px;">
  <div class="stat"><div class="stat-val"><?= h((string)$totalIssued) ?></div><div class="stat-label">Units Issued</div></div>
  <div class="stat"><div class="stat-val"><?= h((string)$totalCerts) ?></div><div class="stat-label">Certificates</div></div>
  <div class="stat">
    <div class="stat-val" style="color:<?= $pendingEmail > 0 ? 'var(--warn)' : 'var(--ok)' ?>"><?= h((string)$pendingEmail) ?></div>
    <div class="stat-label">Pending Email</div>
  </div>
  <div class="stat"><div class="stat-val"><?= count($defs) ?></div><div class="stat-label">Total Classes</div></div>
</div>

<!-- Tab bar -->
<div class="tab-bar">
  <a href="?tab=issue"    class="<?= $viewTab === 'issue'    ? 'active' : '' ?>">📋 Issue Units</a>
  <a href="?tab=register" class="<?= $viewTab === 'register' ? 'active' : '' ?>">📚 Issuance Register</a>
  <a href="?tab=certs"    class="<?= $viewTab === 'certs'    ? 'active' : '' ?>">🏅 Certificates</a>
  <?php if ($viewTab === 'issued'): ?>
  <a href="?tab=issued"      class="active" style="background:rgba(82,184,122,.15);border-color:rgba(82,184,122,.3);color:#7ee0a0;">✅ Issued</a>
  <?php endif; ?>
  <?php if ($viewTab === 'issued_bulk'): ?>
  <a href="?tab=issued_bulk" class="active" style="background:rgba(82,184,122,.15);border-color:rgba(82,184,122,.3);color:#7ee0a0;">✅ Bulk Issued</a>
  <?php endif; ?>
</div>

<?php if ($viewTab === 'issue'): ?>
<!-- ── ISSUE TAB ─────────────────────────────────────────────────────────── -->

<div class="card">
  <div class="card-head"><h2>All Unit Classes — Gate Status</h2></div>
  <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>Code</th><th>Class Name</th><th>Gate</th><th>Cert Type</th>
          <th>Consideration</th><th>Status</th><th>Issued</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($defs as $code => $def):
            $isOpen     = uir_class_is_open($def, $gate2Open);
            $issuedRow  = one($pdo, "SELECT COUNT(*) AS cnt FROM unit_issuance_register WHERE unit_class_code = ?", [$code]);
            $issuedCnt  = (int)($issuedRow['cnt'] ?? 0);
            $gateLabel  = $def['gate'] === 1 ? 'Gate 1' : ($def['gate'] === 2 ? 'Gate 2' : 'Gate 3');
            $certLabel  = $def['cert_type'] === 'financial' ? 'Financial' : ($def['cert_type'] === 'community' ? 'Community' : 'Gov. Alloc.');
            $consLabel  = $def['payment_required'] ? 'Payment required' : 'No fee';
        ?>
        <tr>
          <td><span class="mono"><?= h($code) ?></span></td>
          <td><?= h($def['name']) ?></td>
          <td><span class="st <?= $isOpen ? 'st-ok' : ($def['gate'] === 3 ? 'st-dim' : 'st-warn') ?>"><?= h($gateLabel) ?></span></td>
          <td><span class="st <?= $def['cert_type'] === 'financial' ? 'st-ok' : 'st-blue' ?>"><?= h($certLabel) ?></span></td>
          <td class="muted small"><?= h($consLabel) ?></td>
          <td>
            <?php if ($isOpen): ?><span class="st st-ok">Open</span>
            <?php elseif ($def['gate'] === 3): ?><span class="st st-dim">Expansion Day</span>
            <?php else: ?><span class="st st-warn">Pending FD</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= h((string)$issuedCnt) ?></td>
          <td>
            <?php if ($isOpen): ?>
              <a href="?tab=issue&amp;issue_class=<?= urlencode($code) ?>" class="btn btn-gold btn-sm">Issue <?= h($code) ?></a>
              <form method="POST" action="?tab=issue" style="display:inline;margin-left:6px;"
                    onsubmit="return confirm('Issue Class <?= h($code) ?> to ALL eligible members?
This will issue and send certificates in sequence. Cannot be undone.');">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="issue_class_all">
                <input type="hidden" name="unit_class_code" value="<?= h($code) ?>">
                <button type="submit" class="btn btn-sm" style="background:rgba(82,184,122,.12);border-color:rgba(82,184,122,.3);color:var(--ok);">Issue all</button>
              </form>
            <?php else: ?>
              <span class="muted small">Locked</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$issueClass = trim((string)($_GET['issue_class'] ?? ''));
if ($issueClass !== '' && isset($defs[$issueClass]) && uir_class_is_open($defs[$issueClass], $gate2Open)):
    $def             = $defs[$issueClass];
    $eligibleMembers = uir_eligible_members($pdo, $issueClass, $gate2Open, $tablesReady);
    $selectedMemberId = (int)($_GET['member_id'] ?? 0);
?>
<div class="card" style="margin-top:16px;">
  <div class="card-head"><h2>Issue: <?= h($def['name']) ?> (Class <?= h($issueClass) ?>)</h2></div>
  <div class="card-body">
    <?php if (empty($eligibleMembers)): ?>
      <div class="gate-banner gate-ok">All eligible members have already been issued Class <?= h($issueClass) ?> units.</div>
    <?php else: ?>
      <div style="margin-bottom:16px;">
        <label style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--sub);display:block;margin-bottom:6px;">Select Member</label>
        <select onchange="location='?tab=issue&issue_class=<?= urlencode($issueClass) ?>&member_id='+this.value"
                style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;color:var(--text);font-size:13px;padding:7px 10px;min-width:280px;">
          <option value="0">— Select member —</option>
          <?php foreach ($eligibleMembers as $em): ?>
          <option value="<?= h((string)$em['id']) ?>" <?= (int)$em['id'] === $selectedMemberId ? 'selected' : '' ?>>
            <?= h($em['full_name']) ?> — <?= h($em['member_number']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($selectedMemberId > 0):
            $selectedMember = one($pdo, "SELECT * FROM members WHERE id = ? AND is_active = 1 LIMIT 1", [$selectedMemberId]);
            if ($selectedMember):
                $unitsForClass = ($issueClass === 'C')
                    ? ($selectedMember['member_type'] === 'business' ? 10000 : 1000)
                    : (int)($def['initial_units'] ?? 1);
                $pre = uir_preconditions($pdo, $selectedMember, $issueClass, $gate2Open, $unitsForClass, $tablesReady);
      ?>

      <div class="card" style="background:var(--panel2);margin-bottom:14px;">
        <div class="card-head"><h2>Pre-condition Checklist — <?= h($selectedMember['full_name']) ?></h2></div>
        <table>
          <thead><tr><th>Condition</th><th>Status</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($pre['checks'] as $chk): ?>
            <tr>
              <td><?= h($chk['label']) ?></td>
              <td><?= $chk['ok'] ? '<span class="check-pass">✓ Pass</span>' : '<span class="check-fail">✗ Fail</span>' ?></td>
              <td class="muted small"><?= h($chk['note']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="card-body" style="padding-top:8px;">
          <strong style="font-size:13px;color:<?= $pre['pass'] ? 'var(--ok)' : 'var(--err)' ?>">
            <?= $pre['pass'] ? '✅ All conditions satisfied — ready to issue' : '❌ Pre-conditions not met — cannot issue' ?>
          </strong>
        </div>
      </div>

      <div class="card" style="background:var(--panel2);margin-bottom:14px;">
        <div class="card-head"><h2>Issuance Summary</h2></div>
        <div class="card-body">
          <table class="sum-table">
            <tr><td>Member</td><td><?= h($selectedMember['full_name']) ?> — <?= h($selectedMember['member_number']) ?></td></tr>
            <tr><td>Unit class</td><td><?= h($def['name']) ?> (Class <?= h($issueClass) ?>)</td></tr>
            <tr><td>Units</td><td class="mono"><?= h(number_format($unitsForClass)) ?></td></tr>
            <tr><td>Cert type</td><td><?= h($def['cert_type']) ?></td></tr>
            <tr><td>Issue trigger</td><td class="mono small"><?= h($def['issue_trigger']) ?></td></tr>
            <tr><td>Issue date</td><td><?= h($todayDate) ?></td></tr>
            <tr><td>Certificate email</td><td><?= h($selectedMember['email']) ?></td></tr>
          </table>
        </div>
      </div>

      <?php if ($pre['pass']): ?>
      <form method="POST"
            action="?tab=issue&issue_class=<?= urlencode($issueClass) ?>&member_id=<?= h((string)$selectedMemberId) ?>"
            onsubmit="return confirm('Issue <?= h($def['name']) ?> to <?= h(addslashes($selectedMember['full_name'])) ?>?\nThis is recorded in the legal register and cannot be undone.');">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="issue_unit">
        <input type="hidden" name="member_id" value="<?= h((string)$selectedMemberId) ?>">
        <input type="hidden" name="unit_class_code" value="<?= h($issueClass) ?>">
        <button type="submit" class="btn btn-gold">🏅 Issue <?= h($def['name']) ?> to <?= h($selectedMember['full_name']) ?></button>
        <span class="muted small" style="margin-left:12px;">This action is permanent and recorded in the legal unit register.</span>
      </form>
      <?php else: ?>
      <div class="gate-banner gate-err">Resolve all failed pre-conditions before issuing.</div>
      <?php endif; ?>

      <?php endif; // $selectedMember ?>
      <?php endif; // $selectedMemberId > 0 ?>
    <?php endif; // empty($eligibleMembers) ?>
  </div>
</div>
<?php endif; // $issueClass ?>

<?php elseif ($viewTab === 'register'): ?>
<!-- ── REGISTER TAB ───────────────────────────────────────────────────────── -->

<div class="card">
  <div class="card-head"><h2>Unit Issuance Register</h2><span class="muted small"><?= h((string)$totalIssued) ?> records</span></div>
  <?php if (empty($recentIssuances)): ?>
    <div class="empty" style="padding:24px;">No units have been issued yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr><th>Register Ref</th><th>Member</th><th>Class</th><th>Units</th><th>Cert Type</th>
            <th>Issue Date</th><th>Trigger</th><th>KYC</th><th>Pmt</th><th>Hash</th><th>Certificate</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentIssuances as $row): ?>
        <tr>
          <td class="mono small"><?= h($row['register_ref']) ?></td>
          <td>
            <div><?= h($row['full_name']) ?></div>
            <div class="muted small"><?= h($row['member_number']) ?></div>
          </td>
          <td>
            <span class="st st-ok"><?= h($row['unit_class_code']) ?></span>
            <div class="muted small"><?= h($row['unit_class_name']) ?></div>
          </td>
          <td class="mono"><?= h(number_format((float)$row['units_issued'])) ?></td>
          <td><span class="st <?= $row['cert_type'] === 'financial' ? 'st-ok' : 'st-blue' ?>"><?= h($row['cert_type']) ?></span></td>
          <td class="small"><?= h($row['issue_date']) ?></td>
          <td class="mono small"><?= h($row['issue_trigger']) ?></td>
          <td><?= $row['kyc_verified'] ? '<span class="st st-ok">✓</span>' : '<span class="st st-bad">✗</span>' ?></td>
          <td><?= $row['payment_cleared'] ? '<span class="st st-ok">✓</span>' : '<span class="st st-dim">—</span>' ?></td>
          <td class="mono small" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;"
              title="<?= h((string)($row['sha256_hash'] ?? '')) ?>"><?= h(substr((string)($row['sha256_hash'] ?? ''), 0, 10)) ?>…</td>
          <td>
            <?php if ($row['cert_ref']): ?>
              <div class="mono small"><?= h($row['cert_ref']) ?></div>
              <div class="muted small" style="margin-top:2px;">
                <?= $row['email_sent_at'] ? '✅ ' . h(substr($row['email_sent_at'], 0, 10)) : '⏳ Pending' ?>
                &nbsp;
                <a href="<?= h(admin_url('unit_certificate_view.php')) ?>?cert_ref=<?= urlencode($row['cert_ref']) ?>"
                   target="_blank" style="color:var(--gold);font-size:10px;font-weight:700;">VIEW</a>
              </div>
            <?php else: ?>
              <span class="muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($classBreakdown)): ?>
<div class="card" style="margin-top:16px;">
  <div class="card-head"><h2>Issued Units by Class</h2></div>
  <table>
    <thead><tr><th>Code</th><th>Class Name</th><th>Members Issued</th><th>Total Units</th></tr></thead>
    <tbody>
      <?php foreach ($classBreakdown as $cb): ?>
      <tr>
        <td><span class="mono st st-ok"><?= h($cb['unit_class_code']) ?></span></td>
        <td><?= h($cb['unit_class_name']) ?></td>
        <td class="mono"><?= h($cb['cnt']) ?></td>
        <td class="mono"><?= h(number_format((float)$cb['total_units'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php elseif ($viewTab === 'certs'): ?>
<!-- ── CERTS TAB ─────────────────────────────────────────────────────────── -->

<?php
$certRows = rows($pdo,
    "SELECT uc.*, m.full_name, m.member_number,
            uir.unit_class_name, uir.register_ref, uir.sha256_hash, uir.issue_trigger,
            eq.status AS queue_status, eq.last_error AS queue_error
     FROM unitholder_certificates uc
     INNER JOIN members m ON m.id = uc.member_id
     INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
     LEFT JOIN email_queue eq ON eq.id = uc.email_queue_id
     ORDER BY uc.created_at DESC LIMIT 100");

$failedCount = 0;
foreach ($certRows as $cr) {
    if (($cr['queue_status'] ?? null) === 'failed' || $cr['email_queue_id'] === null) $failedCount++;
}
$resendResult = isset($_GET['resend_result']) ? (int)$_GET['resend_result'] : -1;
?>

<?php if ($resendResult >= 0): ?>
<div style="background:rgba(82,184,122,.12);border:1px solid rgba(82,184,122,.3);border-radius:8px;
            padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:var(--ok)">
  ✅ Resend complete — <?= $resendResult ?> certificate email<?= $resendResult !== 1 ? 's' : '' ?> processed.
  <?php if ($resendResult === 0): ?>
    <span style="color:var(--sub)">No emails were in the queue — check SMTP configuration if certificates are still not arriving.</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($failedCount > 0): ?>
<div style="background:rgba(192,85,58,.10);border:1px solid rgba(192,85,58,.3);border-radius:8px;
            padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px">
  <div style="font-size:.85rem;color:var(--err)">
    ⚠ <?= $failedCount ?> certificate email<?= $failedCount !== 1 ? 's' : '' ?> failed to deliver.
    <span style="color:var(--sub);font-size:.78rem;margin-left:6px">SMTP was rejecting at time of issuance — resend now that email is configured correctly.</span>
  </div>
  <form method="POST" style="flex-shrink:0">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="resend_certs">
    <button type="submit" class="btn btn-warn"
            onclick="return confirm('Resend <?= $failedCount ?> failed certificate email<?= $failedCount !== 1 ? 's' : '' ?>?')">
      🔁 Resend Failed Certificates
    </button>
  </form>
</div>
<?php elseif ($pendingEmail > 0): ?>
<div style="background:rgba(212,178,92,.08);border:1px solid rgba(212,178,92,.25);border-radius:8px;
            padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px">
  <div style="font-size:.85rem;color:var(--warn)">
    ⏳ <?= $pendingEmail ?> certificate email<?= $pendingEmail !== 1 ? 's' : '' ?> pending in queue.
  </div>
  <form method="POST" style="flex-shrink:0">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="resend_certs">
    <button type="submit" class="btn">🔁 Flush Email Queue</button>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <div class="card-head"><h2>Certificate Register</h2><span class="muted small"><?= h((string)$totalCerts) ?> certificates</span></div>
  <?php if (empty($certRows)): ?>
    <div class="empty" style="padding:24px;">No certificates have been issued yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr><th>Cert Ref</th><th>Register Ref</th><th>Member</th><th>Class</th>
            <th>Cert Type</th><th>Units</th><th>Issue Date</th><th>Email Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($certRows as $c): ?>
        <tr>
          <td class="mono small">
            <?= h($c['cert_ref']) ?>
            <div style="margin-top:4px;">
              <a href="<?= h(admin_url('unit_certificate_view.php')) ?>?cert_ref=<?= urlencode($c['cert_ref']) ?>"
                 target="_blank" class="btn btn-sm btn-gold" style="font-size:11px;padding:3px 9px;">👁 View</a>
            </div>
          </td>
          <td class="mono small"><?= h($c['register_ref']) ?></td>
          <td>
            <div><?= h($c['full_name']) ?></div>
            <div class="muted small"><?= h($c['member_number']) ?></div>
          </td>
          <td>
            <span class="st st-ok"><?= h($c['unit_class_code']) ?></span>
            <div class="muted small"><?= h($c['unit_class_name']) ?></div>
          </td>
          <td><span class="st <?= $c['cert_type'] === 'financial' ? 'st-ok' : 'st-blue' ?>"><?= h($c['cert_type']) ?></span></td>
          <td class="mono"><?= h(number_format((float)$c['units'])) ?></td>
          <td class="small"><?= h($c['issue_date']) ?></td>
          <td>
            <?php
            $qs = $c['queue_status'] ?? null;
            if ($qs === 'sent'): ?>
              <span class="st st-ok">✅ Sent</span>
              <div class="muted small"><?= h($c['email_sent_to'] ?? '') ?></div>
              <?php if ($c['email_sent_at']): ?><div class="muted small"><?= h(substr($c['email_sent_at'], 0, 10)) ?></div><?php endif; ?>
            <?php elseif ($qs === 'failed'): ?>
              <span class="st st-err">❌ Failed</span>
              <?php if ($c['queue_error']): ?>
                <div class="muted small" style="color:var(--err);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                     title="<?= h($c['queue_error']) ?>"><?= h(substr($c['queue_error'], 0, 60)) ?></div>
              <?php endif; ?>
            <?php elseif ($qs === 'pending'): ?>
              <span class="st st-warn">⏳ Pending</span>
            <?php elseif ($c['email_sent_at']): ?>
              <span class="st st-ok">✅ <?= h(substr($c['email_sent_at'], 0, 10)) ?></span>
              <div class="muted small"><?= h($c['email_sent_to'] ?? '') ?></div>
            <?php else: ?>
              <span class="st st-warn">⏳ Queued</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($viewTab === 'issued'): ?>
<!-- ── ISSUED SUCCESS TAB ───────────────────────────────────────────────────── -->

<div class="card" style="border-color:rgba(82,184,122,.3);">
  <div class="card-head" style="border-color:rgba(82,184,122,.2);">
    <h2 style="color:#7ee0a0;">✅ Unit Issued Successfully</h2>
    <span class="muted small"><?= h(date('j M Y')) ?></span>
  </div>
  <div class="card-body">
    <p class="muted" style="margin-bottom:18px;font-size:13px;">
      The unit has been recorded in the legal register and a Certificate of Unit Holding has been queued for delivery.
      This record is permanent and cannot be undone.
    </p>

    <table class="sum-table" style="margin-bottom:20px;">
      <tr>
        <td>Register reference</td>
        <td><span class="mono" style="font-size:14px;color:var(--gold);"><?= h($successRegRef) ?></span></td>
      </tr>
      <tr>
        <td>Certificate reference</td>
        <td><span class="mono" style="font-size:14px;color:var(--gold);"><?= h($successCertRef) ?></span></td>
      </tr>
      <tr>
        <td>Unitholder</td>
        <td>
          <?= h($successMember) ?>
          <?php if ($successMemberNum !== ''): ?>
            <span class="muted small" style="margin-left:8px;"><?= h($successMemberNum) ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Unit class</td>
        <td>
          <?= h($successName) ?>
          <?php if ($successClass !== ''): ?>
            <span class="mono small" style="margin-left:8px;color:var(--sub);">(Class <?= h($successClass) ?>)</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Units issued</td>
        <td class="mono"><?= h(number_format((float)$successUnits)) ?></td>
      </tr>
      <tr>
        <td>Certificate email</td>
        <td>
          <?php if ($successEmailSent): ?>
            <span class="st st-ok">✅ Queued for delivery</span>
          <?php else: ?>
            <span class="st st-warn">⏳ Email queue unavailable — register record saved</span>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=issue&amp;issue_class=<?= urlencode($successClass) ?>"
         class="btn btn-gold">
        ＋ Issue another <?= h($successClass) ?> unit
      </a>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=issue"
         class="btn">
        📋 Back to Issue Units
      </a>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=register"
         class="btn">
        📚 View Register
      </a>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=certs"
         class="btn">
        🏅 View Certificates
      </a>
    </div>
  </div>
</div>

<?php elseif ($viewTab === 'issued_bulk'): ?>
<!-- ── BULK ISSUED SUCCESS TAB ──────────────────────────────────────────────── -->

<div class="card" style="border-color:rgba(82,184,122,.3);">
  <div class="card-head" style="border-color:rgba(82,184,122,.2);">
    <h2 style="color:#7ee0a0;">✅ Bulk Issuance Complete — Class <?= h($bulkClass) ?></h2>
    <span class="muted small"><?= h(date('j M Y')) ?></span>
  </div>
  <div class="card-body">
    <table class="sum-table" style="margin-bottom:20px;">
      <tr><td>Unit class</td><td><?= h($bulkClassName) ?> (Class <?= h($bulkClass) ?>)</td></tr>
      <tr>
        <td>Successfully issued</td>
        <td><span style="color:var(--ok);font-weight:700;font-size:15px;"><?= h((string)$bulkIssuedCount) ?></span>
            <span class="muted small"> members — certificates queued and sent</span></td>
      </tr>
      <?php if ($bulkSkippedCount > 0): ?>
      <tr>
        <td>Skipped</td>
        <td><span style="color:var(--warn);font-weight:700;"><?= h((string)$bulkSkippedCount) ?></span>
            <span class="muted small"> members did not meet pre-conditions</span></td>
      </tr>
      <?php endif; ?>
    </table>

    <?php if (!empty($bulkSkippedList)): ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:12px 16px;margin-bottom:18px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sub);margin-bottom:8px;">Skipped — pre-conditions not met</div>
      <?php foreach ($bulkSkippedList as $skippedItem): ?>
      <div style="font-size:12px;color:var(--warn);margin-bottom:4px;">⚠ <?= h($skippedItem) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <?php if ($bulkSkippedCount > 0): ?>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=issue&amp;issue_class=<?= urlencode($bulkClass) ?>"
         class="btn btn-gold">Review skipped members</a>
      <?php endif; ?>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=issue" class="btn">📋 Back to Issue Units</a>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=register" class="btn">📚 View Register</a>
      <a href="<?= h(admin_url('unit_issuance.php')) ?>?tab=certs" class="btn">🏅 View Certificates</a>
    </div>
  </div>
</div>

<?php endif; // viewTab ?>

<?php endif; // tablesReady — stats/tabs section ?>

</main>
</div>
</body>
</html>

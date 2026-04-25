<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
if (file_exists(__DIR__ . '/includes/LedgerEmitter.php'))   require_once __DIR__ . '/includes/LedgerEmitter.php';
if (file_exists(__DIR__ . '/includes/AccountingHooks.php')) require_once __DIR__ . '/includes/AccountingHooks.php';

ops_require_admin();
$pdo = ops_db();

// ── Helper functions ──────────────────────────────────────────────────────────

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

// ── Foundation Day gate check ─────────────────────────────────────────────────

$fdDeclared = ops_setting_get($pdo, 'governance_foundation_day_declared', '') === 'yes';
$fdDate     = ops_setting_get($pdo, 'governance_foundation_day_date', '');
$gate2Open  = $fdDeclared && $fdDate !== '' && date('Y-m-d') >= $fdDate;

// ── Class definition table ────────────────────────────────────────────────────
// For each unit class: which gate, which cert_type, whether monetary payment
// required, whether issued now vs locked, initial units for standing alloc.
function uir_class_defs(): array {
    return [
        'S'  => ['name' => 'Personal S-NFT COG$',       'gate' => 1, 'cert_type' => 'financial',             'payment_required' => true,  'open' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'B'  => ['name' => 'Business B-NFT COG$',        'gate' => 1, 'cert_type' => 'financial',             'payment_required' => true,  'open' => true,  'initial_units' => 1,    'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'kS' => ['name' => 'Kids S-NFT COG$',            'gate' => 2, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => 1,    'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'C'  => ['name' => 'Community COG$',             'gate' => 2, 'cert_type' => 'community',             'payment_required' => false, 'open' => false, 'initial_units' => null, 'issue_trigger' => 'standing_poll_cl23D3',  'kyc_required' => true],
        'P'  => ['name' => 'Pay It Forward COG$',        'gate' => 2, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => 1,    'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'D'  => ['name' => 'Donation COG$',              'gate' => 2, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => 1,    'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'Lr' => ['name' => 'Resident COG$',              'gate' => 2, 'cert_type' => 'governance_allocation', 'payment_required' => false, 'open' => false, 'initial_units' => 1000, 'issue_trigger' => 'auto_zone_allocation',  'kyc_required' => true],
        'A'  => ['name' => 'ASX COG$',                   'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => null, 'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'Lh' => ['name' => 'Landholder COG$',            'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => null, 'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'BP' => ['name' => 'Business Property COG$',     'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => null, 'issue_trigger' => 'trustee_manual',        'kyc_required' => true],
        'R'  => ['name' => 'RWA COG$',                   'gate' => 3, 'cert_type' => 'financial',             'payment_required' => true,  'open' => false, 'initial_units' => null, 'issue_trigger' => 'trustee_resolution_rwa', 'kyc_required' => true],
    ];
}

// Resolve whether each class is currently open (Gate 1 always, Gate 2 if FD declared)
function uir_class_is_open(array $def, bool $gate2Open): bool {
    if ($def['gate'] === 1) return true;
    if ($def['gate'] === 2) return $gate2Open;
    return false; // Gate 3 — Expansion Day only
}

// ── Next sequence reference generator ────────────────────────────────────────
function uir_next_ref(PDO $pdo, string $prefix, string $table, string $col): string {
    $row = one($pdo, "SELECT MAX(CAST(SUBSTRING({$col}, LENGTH(?)+2) AS UNSIGNED)) AS n FROM `{$table}` WHERE {$col} LIKE ?",
               ["{$prefix}", "{$prefix}-%"]);
    $n = (int)($row['n'] ?? 0) + 1;
    return $prefix . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

// ── Build SHA-256 hash for an issuance record ─────────────────────────────────
function uir_build_hash(string $registerRef, int $memberId, string $unitClassCode,
                        string $unitsIssued, string $issueDate, int $considerationCents): string {
    $payload = implode('|', [$registerRef, $memberId, $unitClassCode,
                              $unitsIssued, $issueDate, $considerationCents]);
    return hash('sha256', $payload);
}

// ── Fetch eligible members for a given class ─────────────────────────────────
function uir_eligible_members(PDO $pdo, string $unitClassCode, bool $gate2Open): array {
    $defs = uir_class_defs();
    $def  = $defs[$unitClassCode] ?? null;
    if (!$def || !uir_class_is_open($def, $gate2Open)) return [];

    // Already issued this class? Exclude those members.
    $alreadyIssued = rows($pdo,
        "SELECT member_id FROM unit_issuance_register WHERE unit_class_code = ?",
        [$unitClassCode]);
    $issuedIds = array_column($alreadyIssued, 'member_id');

    // Class C: eligible = members who have an issued Class S or B unit
    if ($unitClassCode === 'C') {
        $rows = rows($pdo,
            "SELECT m.id, m.member_number, m.full_name, m.email, m.member_type,
                    m.kyc_status, m.signup_payment_status,
                    uir.register_ref AS snft_ref
             FROM members m
             INNER JOIN unit_issuance_register uir
                ON uir.member_id = m.id AND uir.unit_class_code IN ('S','B')
             WHERE m.is_active = 1
             ORDER BY m.id ASC");
        return array_filter($rows, fn($r) => !in_array((int)$r['id'], $issuedIds));
    }

    // Class S: personal members, payment paid, not already issued
    if ($unitClassCode === 'S') {
        $rows = rows($pdo,
            "SELECT id, member_number, full_name, email, member_type,
                    kyc_status, signup_payment_status
             FROM members
             WHERE is_active = 1 AND member_type = 'personal'
             ORDER BY id ASC");
        return array_filter($rows, fn($r) => !in_array((int)$r['id'], $issuedIds));
    }

    // Class B: business members, payment paid, not already issued
    if ($unitClassCode === 'B') {
        $rows = rows($pdo,
            "SELECT id, member_number, full_name, email, member_type,
                    kyc_status, signup_payment_status
             FROM members
             WHERE is_active = 1 AND member_type = 'business'
             ORDER BY id ASC");
        return array_filter($rows, fn($r) => !in_array((int)$r['id'], $issuedIds));
    }

    // All other gate 2 classes — paid personal members not already issued
    $rows = rows($pdo,
        "SELECT id, member_number, full_name, email, member_type,
                kyc_status, signup_payment_status
         FROM members
         WHERE is_active = 1
         ORDER BY id ASC");
    return array_filter($rows, fn($r) => !in_array((int)$r['id'], $issuedIds));
}

// ── Pre-conditions check for a specific member + class ───────────────────────
// Returns array of ['pass' => bool, 'checks' => [['label','ok','note'],...]]
function uir_preconditions(PDO $pdo, array $member, string $unitClassCode,
                           bool $gate2Open, int $unitsRequested = 1): array {
    $defs    = uir_class_defs();
    $def     = $defs[$unitClassCode];
    $checks  = [];
    $allPass = true;

    // Gate check
    $gateOpen = uir_class_is_open($def, $gate2Open);
    $gateLabel = $def['gate'] === 1 ? 'Gate 1 (Declaration executed)'
               : ($def['gate'] === 2 ? 'Gate 2 (Foundation Day)' : 'Gate 3 (Expansion Day)');
    $checks[] = ['label' => $gateLabel, 'ok' => $gateOpen,
                 'note' => $gateOpen ? 'Open' : 'Not yet reached'];
    if (!$gateOpen) $allPass = false;

    // KYC
    $kycOk = in_array((string)($member['kyc_status'] ?? ''), ['verified', 'address_verified', 'manual_verified'], true);
    $checks[] = ['label' => 'KYC/AML-CTF verified', 'ok' => $kycOk,
                 'note' => $kycOk ? 'Recorded' : 'Not verified — issue blocked'];
    if (!$kycOk) $allPass = false;

    // Payment (skip for Class C standing allocation and Class Lr)
    if ($def['payment_required']) {
        $payOk = (string)($member['signup_payment_status'] ?? '') === 'paid';
        $checks[] = ['label' => 'Payment cleared', 'ok' => $payOk,
                     'note' => $payOk ? 'Cleared' : 'Awaiting payment'];
        if (!$payOk) $allPass = false;
    } else {
        $checks[] = ['label' => 'Payment', 'ok' => true,
                     'note' => 'No monetary consideration (standing allocation / allocation-based class)'];
    }

    // Anti-capture cap check
    $totalRow = one($pdo,
        "SELECT COALESCE(SUM(units_issued),0) AS total
         FROM unit_issuance_register
         WHERE member_id = ? AND unit_class_code NOT IN ('Lr')",
        [(int)$member['id']]);
    $currentTotal = (float)($totalRow['total'] ?? 0);
    $capOk        = ($currentTotal + $unitsRequested) <= 1000000;
    $checks[] = ['label' => 'Anti-capture cap (1,000,000)', 'ok' => $capOk,
                 'note' => "Current: {$currentTotal} + {$unitsRequested} = " .
                           ($currentTotal + $unitsRequested) . ($capOk ? ' ≤ cap' : ' EXCEEDS CAP')];
    if (!$capOk) $allPass = false;

    // Not already issued this class
    $existing = one($pdo,
        "SELECT id FROM unit_issuance_register WHERE member_id = ? AND unit_class_code = ? LIMIT 1",
        [(int)$member['id'], $unitClassCode]);
    $notDuplicate = $existing === null;
    $checks[] = ['label' => 'No duplicate issuance', 'ok' => $notDuplicate,
                 'note' => $notDuplicate ? 'No prior issuance for this class' : 'Already issued — blocked'];
    if (!$notDuplicate) $allPass = false;

    return ['pass' => $allPass, 'checks' => $checks];
}

// ── POST handler ──────────────────────────────────────────────────────────────
$flash = null; $flashType = 'ok'; $error = null;
$adminId = function_exists('ops_current_admin_id') ? (int)ops_current_admin_id($pdo) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action       = (string)($_POST['action'] ?? '');
        $memberId     = (int)($_POST['member_id'] ?? 0);
        $unitClassCode = trim((string)($_POST['unit_class_code'] ?? ''));

        if ($action === 'issue_unit') {
            if ($memberId <= 0) throw new RuntimeException('Member ID required.');
            if ($unitClassCode === '') throw new RuntimeException('Unit class required.');

            $defs = uir_class_defs();
            if (!isset($defs[$unitClassCode])) throw new RuntimeException('Unknown unit class code.');
            $def = $defs[$unitClassCode];

            if (!uir_class_is_open($def, $gate2Open)) {
                throw new RuntimeException("Class {$unitClassCode} gate is not yet open. Cannot issue.");
            }

            $member = one($pdo, "SELECT * FROM members WHERE id = ? AND is_active = 1 LIMIT 1", [$memberId]);
            if (!$member) throw new RuntimeException('Member not found or inactive.');

            // Determine units for this issuance
            if ($unitClassCode === 'C') {
                $unitsIssued = $member['member_type'] === 'business' ? 10000.0 : 1000.0;
            } elseif ($def['initial_units'] !== null) {
                $unitsIssued = (float)$def['initial_units'];
            } else {
                $unitsIssued = (float)(int)($_POST['units'] ?? 1);
                if ($unitsIssued <= 0) throw new RuntimeException('Invalid unit quantity.');
            }

            // Run pre-conditions
            $pre = uir_preconditions($pdo, $member, $unitClassCode, $gate2Open, (int)$unitsIssued);
            if (!$pre['pass']) {
                $failed = array_filter($pre['checks'], fn($c) => !$c['ok']);
                $labels = array_map(fn($c) => $c['label'], $failed);
                throw new RuntimeException('Pre-conditions not met: ' . implode('; ', $labels));
            }

            // Consideration in cents
            if ($def['payment_required']) {
                $tc = one($pdo, "SELECT unit_price_cents, business_unit_price_cents FROM token_classes WHERE unit_class_code = ? LIMIT 1", [$unitClassCode]);
                if ($member['member_type'] === 'business' && $tc && $tc['business_unit_price_cents'] !== null) {
                    $considerationCents = (int)$tc['business_unit_price_cents'];
                } else {
                    $considerationCents = (int)($tc['unit_price_cents'] ?? 0);
                }
            } else {
                $considerationCents = 0;
            }

            $issueDate = date('Y-m-d');

            $pdo->beginTransaction();

            // Generate references
            $registerRef = uir_next_ref($pdo, 'UIR-' . strtoupper($unitClassCode), 'unit_issuance_register', 'register_ref');
            $certRef     = uir_next_ref($pdo, 'CERT-' . strtoupper($unitClassCode), 'unitholder_certificates', 'cert_ref');

            // Build hash
            $hash = uir_build_hash($registerRef, $memberId, $unitClassCode,
                                   number_format($unitsIssued, 4, '.', ''),
                                   $issueDate, $considerationCents);

            // Insert issuance record
            $pdo->prepare("
                INSERT INTO unit_issuance_register
                    (register_ref, member_id, unit_class_code, unit_class_name, cert_type,
                     units_issued, consideration_cents, issue_date, issue_trigger, gate,
                     kyc_verified, payment_cleared, anti_cap_checked, gate_satisfied,
                     sha256_hash, issued_by_admin_id, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $registerRef,
                $memberId,
                $unitClassCode,
                $def['name'],
                $def['cert_type'],
                number_format($unitsIssued, 4, '.', ''),
                $considerationCents,
                $issueDate,
                $def['issue_trigger'],
                $def['gate'],
                1, // kyc_verified — pre-conditions confirmed above
                $def['payment_required'] ? 1 : 0,
                1, // anti_cap_checked
                1, // gate_satisfied
                $hash,
                $adminId ?: null,
                null,
                now_dt(),
                now_dt(),
            ]);
            $issuanceId = (int)$pdo->lastInsertId();

            // Insert certificate record
            $pdo->prepare("
                INSERT INTO unitholder_certificates
                    (cert_ref, issuance_id, member_id, unit_class_code, cert_type,
                     units, issue_date, email_sent_to, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $certRef,
                $issuanceId,
                $memberId,
                $unitClassCode,
                $def['cert_type'],
                number_format($unitsIssued, 4, '.', ''),
                $issueDate,
                $member['email'],
                now_dt(),
            ]);
            $certId = (int)$pdo->lastInsertId();

            // Update certificate_sent_at in issuance register
            $pdo->prepare("UPDATE unit_issuance_register SET certificate_sent_at = ? WHERE id = ?")
                ->execute([now_dt(), $issuanceId]);

            // Queue certificate email if mailer is available
            $emailQueueId = 0;
            if (function_exists('queueEmail')) {
                $emailPayload = [
                    'full_name'        => $member['full_name'],
                    'first_name'       => $member['first_name'] ?? '',
                    'email'            => $member['email'],
                    'member_number'    => $member['member_number'],
                    'unit_class_code'  => $unitClassCode,
                    'unit_class_name'  => $def['name'],
                    'cert_type'        => $def['cert_type'],
                    'units_issued'     => number_format($unitsIssued, 4, '.', ''),
                    'issue_date'       => $issueDate,
                    'register_ref'     => $registerRef,
                    'cert_ref'         => $certRef,
                    'sha256_hash'      => $hash,
                    'consideration_cents' => $considerationCents,
                    'issue_trigger'    => $def['issue_trigger'],
                    'gate'             => $def['gate'],
                    'member_type'      => $member['member_type'],
                ];
                $subject = "COG\$ Certificate of Unit Holding — {$def['name']} — {$certRef}";
                $emailQueueId = queueEmail($pdo, 'unit_certificate', $issuanceId,
                                           (string)$member['email'],
                                           'unitholder_certificate', $subject, $emailPayload);

                if ($emailQueueId > 0) {
                    $pdo->prepare("UPDATE unitholder_certificates SET email_sent_at = ?, email_queue_id = ? WHERE id = ?")
                        ->execute([now_dt(), $emailQueueId, $certId]);
                }
            }

            $pdo->commit();

            $flash = "Unit issued. Register ref: {$registerRef} | Certificate: {$certRef}" .
                     ($emailQueueId > 0 ? ' | Certificate email queued.' : ' | Email queue unavailable — certificate recorded only.');
            $flashType = 'ok';
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── Query filter and view mode ────────────────────────────────────────────────
$filterClass  = trim((string)($_GET['class'] ?? ''));
$filterMember = trim((string)($_GET['member'] ?? ''));
$viewTab      = in_array($_GET['tab'] ?? '', ['issue', 'register', 'certs'], true)
                ? $_GET['tab'] : 'issue';

// Stats
$totalIssued  = (int)(one($pdo, "SELECT COUNT(*) AS n FROM unit_issuance_register")['n'] ?? 0);
$totalCerts   = (int)(one($pdo, "SELECT COUNT(*) AS n FROM unitholder_certificates")['n'] ?? 0);
$pendingEmail = (int)(one($pdo, "SELECT COUNT(*) AS n FROM unitholder_certificates WHERE email_sent_at IS NULL")['n'] ?? 0);
$classBreakdown = rows($pdo,
    "SELECT unit_class_code, unit_class_name, COUNT(*) AS cnt, SUM(units_issued) AS total_units
     FROM unit_issuance_register GROUP BY unit_class_code, unit_class_name ORDER BY unit_class_code");

// Recent issuances
$recentIssuances = rows($pdo,
    "SELECT uir.*, m.full_name, m.member_number, m.email,
            uc.cert_ref, uc.email_sent_at
     FROM unit_issuance_register uir
     INNER JOIN members m ON m.id = uir.member_id
     LEFT JOIN unitholder_certificates uc ON uc.issuance_id = uir.id
     ORDER BY uir.created_at DESC LIMIT 50");

$defs      = uir_class_defs();
$csrf      = h(admin_csrf_token());
$todayDate = date('Y-m-d');

// ─────────────────────────────────────────────────────────────────────────────
admin_render_header('unit_issuance', 'Unit Issuance Register',
    'Issue units, generate certificates, and maintain the formal unitholder register');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err">Error: <?= h($error) ?></div>
<?php endif; ?>

<!-- Gate status banner -->
<div class="alert <?= $gate2Open ? 'alert-ok' : 'alert-amber' ?>" style="margin-bottom:18px;">
  <?php if ($gate2Open): ?>
    ✅ <strong>Gate 2 open</strong> — Governance Foundation Day declared on <?= h($fdDate) ?>.
    Classes kS, C, P, D, and Lr are available for issuance.
  <?php elseif ($fdDeclared && $fdDate !== ''): ?>
    ⏳ <strong>Gate 2 pending</strong> — Foundation Day scheduled for <?= h($fdDate) ?>.
    Classes kS, C, P, D, and Lr will unlock on that date.
  <?php else: ?>
    ⚠️ <strong>Gate 2 not yet declared</strong> — Foundation Day has not been declared.
    Only Classes S and B (Gate 1) are available for issuance.
    <a href="<?= h(admin_url('foundation_day.php')) ?>" class="btn btn-gold" style="margin-left:12px;font-size:12px;">Foundation Day →</a>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats" style="margin-bottom:22px;">
  <div class="stat">
    <div class="stat-val"><?= h((string)$totalIssued) ?></div>
    <div class="stat-label">Units Issued</div>
  </div>
  <div class="stat">
    <div class="stat-val"><?= h((string)$totalCerts) ?></div>
    <div class="stat-label">Certificates</div>
  </div>
  <div class="stat">
    <div class="stat-val" style="color:<?= $pendingEmail > 0 ? 'var(--warn)' : 'var(--ok)' ?>">
      <?= h((string)$pendingEmail) ?>
    </div>
    <div class="stat-label">Certs Pending Email</div>
  </div>
  <div class="stat">
    <div class="stat-val"><?= h((string)count($defs)) ?></div>
    <div class="stat-label">Total Classes</div>
  </div>
</div>

<!-- Tab nav -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
  <?php foreach (['issue' => '📋 Issue Units', 'register' => '📚 Issuance Register', 'certs' => '🏅 Certificates'] as $tab => $label): ?>
    <a href="?tab=<?= h($tab) ?>"
       class="btn <?= $viewTab === $tab ? 'btn-gold' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($viewTab === 'issue'): ?>
<!-- ── ISSUE UNITS TAB ──────────────────────────────────────────────────── -->

<div class="card">
  <div class="card-head"><h2>All Unit Classes — Gate Status &amp; Issuance</h2></div>
  <div class="card-body" style="padding:0;">
    <table>
      <thead>
        <tr>
          <th>Class</th>
          <th>Name</th>
          <th>Gate</th>
          <th>Cert Type</th>
          <th>Consideration</th>
          <th>Status</th>
          <th>Issued</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($defs as $code => $def):
            $isOpen    = uir_class_is_open($def, $gate2Open);
            $issuedRow = one($pdo, "SELECT COUNT(*) AS cnt FROM unit_issuance_register WHERE unit_class_code = ?", [$code]);
            $issuedCnt = (int)($issuedRow['cnt'] ?? 0);
            $gateLabel = $def['gate'] === 1 ? 'Gate 1' : ($def['gate'] === 2 ? 'Gate 2' : 'Gate 3');
            $certLabel = $def['cert_type'] === 'financial' ? 'Financial' :
                        ($def['cert_type'] === 'community' ? 'Community' : 'Governance Alloc.');
            $consLabel = $def['payment_required'] ? 'Payment required' : 'No fee';
        ?>
        <tr>
          <td><span class="mono"><?= h($code) ?></span></td>
          <td><?= h($def['name']) ?></td>
          <td>
            <span class="st <?= $isOpen ? 'st-ok' : ($def['gate'] === 3 ? 'st-dim' : 'st-warn') ?>">
              <?= h($gateLabel) ?>
            </span>
          </td>
          <td><span class="badge badge-<?= $def['cert_type'] === 'financial' ? 'ok' : 'pending' ?>"><?= h($certLabel) ?></span></td>
          <td class="muted small"><?= h($consLabel) ?></td>
          <td>
            <?php if ($isOpen): ?>
              <span class="st st-ok">Open</span>
            <?php elseif ($def['gate'] === 3): ?>
              <span class="st st-dim">Expansion Day</span>
            <?php else: ?>
              <span class="st st-warn">Pending</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= h((string)$issuedCnt) ?></td>
          <td>
            <?php if ($isOpen): ?>
              <a href="?tab=issue&amp;issue_class=<?= urlencode($code) ?>"
                 class="btn btn-gold btn-sm">Issue <?= h($code) ?></a>
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
// If a class was selected for issue, show the member selector and pre-condition table
$issueClass = trim((string)($_GET['issue_class'] ?? ''));
if ($issueClass !== '' && isset($defs[$issueClass]) && uir_class_is_open($defs[$issueClass], $gate2Open)):
    $def = $defs[$issueClass];
    $eligibleMembers = array_values(uir_eligible_members($pdo, $issueClass, $gate2Open));
    $selectedMemberId = (int)($_GET['member_id'] ?? 0);
?>

<div class="card" style="margin-top:16px;">
  <div class="card-head">
    <h2>Issue: <?= h($def['name']) ?> (Class <?= h($issueClass) ?>)</h2>
  </div>
  <div class="card-body">
    <?php if (empty($eligibleMembers)): ?>
      <div class="alert alert-ok">All eligible members have already been issued Class <?= h($issueClass) ?> units.</div>
    <?php else: ?>
      <!-- Member selector -->
      <div style="margin-bottom:16px;">
        <label style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--sub);display:block;margin-bottom:6px;">
          Select Member
        </label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <select id="member_select" onchange="location='?tab=issue&issue_class=<?= urlencode($issueClass) ?>&member_id='+this.value"
                  style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;color:var(--text);font-size:13px;padding:7px 10px;min-width:280px;">
            <option value="0">— Select member —</option>
            <?php foreach ($eligibleMembers as $em): ?>
              <option value="<?= h((string)$em['id']) ?>"
                      <?= (int)$em['id'] === $selectedMemberId ? 'selected' : '' ?>>
                <?= h($em['full_name']) ?> — <?= h($em['member_number']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if ($selectedMemberId > 0):
          $selectedMember = one($pdo, "SELECT * FROM members WHERE id = ? AND is_active = 1 LIMIT 1", [$selectedMemberId]);
          if ($selectedMember):
              $unitsForClass = ($issueClass === 'C')
                  ? ($selectedMember['member_type'] === 'business' ? 10000 : 1000)
                  : (int)($def['initial_units'] ?? 1);
              $pre = uir_preconditions($pdo, $selectedMember, $issueClass, $gate2Open, $unitsForClass);
      ?>

      <!-- Pre-condition checklist -->
      <div class="card" style="background:var(--panel2);margin-bottom:16px;">
        <div class="card-head"><h2>Pre-condition Checklist — <?= h($selectedMember['full_name']) ?></h2></div>
        <table>
          <thead>
            <tr><th>Condition</th><th>Status</th><th>Note</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pre['checks'] as $chk): ?>
            <tr>
              <td><?= h($chk['label']) ?></td>
              <td>
                <?php if ($chk['ok']): ?>
                  <span class="st st-ok">✓ Pass</span>
                <?php else: ?>
                  <span class="st st-bad">✗ Fail</span>
                <?php endif; ?>
              </td>
              <td class="muted small"><?= h($chk['note']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="card-body" style="padding-top:8px;">
          <strong style="font-size:13px;color:<?= $pre['pass'] ? 'var(--ok)' : 'var(--err)' ?>">
            <?= $pre['pass'] ? '✅ All conditions satisfied — ready to issue' : '❌ One or more conditions not met — cannot issue' ?>
          </strong>
        </div>
      </div>

      <!-- Issue summary -->
      <div class="card" style="background:var(--panel2);margin-bottom:16px;">
        <div class="card-head"><h2>Issuance Summary</h2></div>
        <div class="card-body">
          <table style="width:auto;">
            <tr><td style="color:var(--sub);padding-right:24px;">Member</td><td><?= h($selectedMember['full_name']) ?> — <?= h($selectedMember['member_number']) ?></td></tr>
            <tr><td style="color:var(--sub);">Unit class</td><td><?= h($def['name']) ?> (Class <?= h($issueClass) ?>)</td></tr>
            <tr><td style="color:var(--sub);">Units to issue</td><td class="mono"><?= h(number_format($unitsForClass)) ?></td></tr>
            <tr><td style="color:var(--sub);">Cert type</td><td><?= h($def['cert_type']) ?></td></tr>
            <tr><td style="color:var(--sub);">Issue trigger</td><td class="mono small"><?= h($def['issue_trigger']) ?></td></tr>
            <tr><td style="color:var(--sub);">Issue date</td><td><?= h($todayDate) ?></td></tr>
            <tr><td style="color:var(--sub);">Certificate</td><td>Generated and emailed to <?= h($selectedMember['email']) ?></td></tr>
          </table>
        </div>
      </div>

      <?php if ($pre['pass']): ?>
      <form method="POST" action="?tab=issue&issue_class=<?= urlencode($issueClass) ?>&member_id=<?= h((string)$selectedMemberId) ?>"
            onsubmit="return confirm('Confirm: Issue <?= h($def['name']) ?> to <?= h(addslashes($selectedMember['full_name'])) ?>? This action is recorded in the legal register and cannot be undone.');">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="issue_unit">
        <input type="hidden" name="member_id" value="<?= h((string)$selectedMemberId) ?>">
        <input type="hidden" name="unit_class_code" value="<?= h($issueClass) ?>">
        <button type="submit" class="btn btn-gold">
          🏅 Issue <?= h($def['name']) ?> to <?= h($selectedMember['full_name']) ?>
        </button>
        <span class="muted small" style="margin-left:12px;">
          This action is permanent and recorded in the legal unit register.
        </span>
      </form>
      <?php else: ?>
      <div class="alert alert-err">Resolve all failed pre-conditions before issuing.</div>
      <?php endif; ?>

      <?php endif; // $selectedMember ?>
      <?php endif; // $selectedMemberId > 0 ?>
    <?php endif; // empty($eligibleMembers) ?>
  </div>
</div>

<?php endif; // $issueClass ?>

<?php elseif ($viewTab === 'register'): ?>
<!-- ── ISSUANCE REGISTER TAB ───────────────────────────────────────────── -->

<div class="card">
  <div class="card-head">
    <h2>Unit Issuance Register</h2>
    <span class="muted small"><?= h((string)$totalIssued) ?> records</span>
  </div>
  <?php if (empty($recentIssuances)): ?>
    <div class="empty">No units have been issued yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>Register Ref</th>
          <th>Member</th>
          <th>Class</th>
          <th>Units</th>
          <th>Cert Type</th>
          <th>Issue Date</th>
          <th>Trigger</th>
          <th>KYC</th>
          <th>Payment</th>
          <th>Hash</th>
          <th>Certificate</th>
        </tr>
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
            <span class="badge badge-ok"><?= h($row['unit_class_code']) ?></span>
            <div class="muted small"><?= h($row['unit_class_name']) ?></div>
          </td>
          <td class="mono"><?= h(number_format((float)$row['units_issued'])) ?></td>
          <td><span class="st <?= $row['cert_type'] === 'financial' ? 'st-ok' : 'st-blue' ?>"><?= h($row['cert_type']) ?></span></td>
          <td class="small"><?= h($row['issue_date']) ?></td>
          <td class="mono small"><?= h($row['issue_trigger']) ?></td>
          <td><?= $row['kyc_verified'] ? '<span class="st st-ok">✓</span>' : '<span class="st st-bad">✗</span>' ?></td>
          <td><?= $row['payment_cleared'] ? '<span class="st st-ok">✓</span>' : '<span class="st st-dim">N/A</span>' ?></td>
          <td class="mono small" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;" title="<?= h($row['sha256_hash'] ?? '') ?>">
            <?= h(substr((string)($row['sha256_hash'] ?? ''), 0, 12)) ?>…
          </td>
          <td>
            <?php if ($row['cert_ref']): ?>
              <div class="mono small"><?= h($row['cert_ref']) ?></div>
              <div class="muted small">
                <?= $row['email_sent_at'] ? '✅ Sent ' . h(substr($row['email_sent_at'], 0, 10)) : '⏳ Pending' ?>
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

<!-- Class breakdown -->
<?php if (!empty($classBreakdown)): ?>
<div class="card">
  <div class="card-head"><h2>Issued Units by Class</h2></div>
  <table>
    <thead>
      <tr><th>Code</th><th>Class Name</th><th>Members Issued</th><th>Total Units</th></tr>
    </thead>
    <tbody>
      <?php foreach ($classBreakdown as $cb): ?>
      <tr>
        <td><span class="mono badge badge-ok"><?= h($cb['unit_class_code']) ?></span></td>
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
<!-- ── CERTIFICATES TAB ────────────────────────────────────────────────── -->

<?php
$certRows = rows($pdo,
    "SELECT uc.*, m.full_name, m.member_number, m.email,
            uir.unit_class_name, uir.register_ref, uir.sha256_hash,
            uir.consideration_cents, uir.issue_trigger, uir.gate
     FROM unitholder_certificates uc
     INNER JOIN members m ON m.id = uc.member_id
     INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
     ORDER BY uc.created_at DESC LIMIT 100");
?>
<div class="card">
  <div class="card-head">
    <h2>Certificate Register</h2>
    <span class="muted small"><?= h((string)$totalCerts) ?> certificates</span>
  </div>
  <?php if (empty($certRows)): ?>
    <div class="empty">No certificates have been issued yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>Cert Ref</th>
          <th>Register Ref</th>
          <th>Member</th>
          <th>Class</th>
          <th>Cert Type</th>
          <th>Units</th>
          <th>Issue Date</th>
          <th>Email Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($certRows as $c): ?>
        <tr>
          <td class="mono small"><?= h($c['cert_ref']) ?></td>
          <td class="mono small"><?= h($c['register_ref']) ?></td>
          <td>
            <div><?= h($c['full_name']) ?></div>
            <div class="muted small"><?= h($c['member_number']) ?></div>
          </td>
          <td>
            <span class="badge badge-ok"><?= h($c['unit_class_code']) ?></span>
            <div class="muted small"><?= h($c['unit_class_name']) ?></div>
          </td>
          <td><span class="st <?= $c['cert_type'] === 'financial' ? 'st-ok' : 'st-blue' ?>"><?= h($c['cert_type']) ?></span></td>
          <td class="mono"><?= h(number_format((float)$c['units'])) ?></td>
          <td class="small"><?= h($c['issue_date']) ?></td>
          <td>
            <?php if ($c['email_sent_at']): ?>
              <span class="st st-ok">✅ <?= h(substr($c['email_sent_at'], 0, 10)) ?></span>
              <div class="muted small"><?= h($c['email_sent_to']) ?></div>
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

<?php endif; // viewTab ?>

<?php admin_render_footer(); ?>

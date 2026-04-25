<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
if (file_exists(__DIR__ . '/includes/LedgerEmitter.php'))   require_once __DIR__ . '/includes/LedgerEmitter.php';
if (file_exists(__DIR__ . '/includes/AccountingHooks.php')) require_once __DIR__ . '/includes/AccountingHooks.php';

ops_require_admin();
$pdo = ops_db();

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
if (!function_exists('uir_class_defs')) {
function uir_class_defs(): array {
    return [
        'S'  => ['name'=>'Personal S-NFT COG$',     'gate'=>1,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>1,   'issue_trigger'=>'trustee_manual'],
        'B'  => ['name'=>'Business B-NFT COG$',      'gate'=>1,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>1,   'issue_trigger'=>'trustee_manual'],
        'kS' => ['name'=>'Kids S-NFT COG$',          'gate'=>2,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>1,   'issue_trigger'=>'trustee_manual'],
        'C'  => ['name'=>'Community COG$',           'gate'=>2,'cert_type'=>'community',            'payment_required'=>false,'initial_units'=>null,'issue_trigger'=>'standing_poll_cl23D3'],
        'P'  => ['name'=>'Pay It Forward COG$',      'gate'=>2,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>1,   'issue_trigger'=>'trustee_manual'],
        'D'  => ['name'=>'Donation COG$',            'gate'=>2,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>1,   'issue_trigger'=>'trustee_manual'],
        'Lr' => ['name'=>'Resident COG$',            'gate'=>2,'cert_type'=>'governance_allocation','payment_required'=>false,'initial_units'=>1000,'issue_trigger'=>'auto_zone_allocation'],
        'A'  => ['name'=>'ASX COG$',                 'gate'=>3,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>null,'issue_trigger'=>'trustee_manual'],
        'Lh' => ['name'=>'Landholder COG$',          'gate'=>3,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>null,'issue_trigger'=>'trustee_manual'],
        'BP' => ['name'=>'Business Property COG$',   'gate'=>3,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>null,'issue_trigger'=>'trustee_manual'],
        'R'  => ['name'=>'RWA COG$',                 'gate'=>3,'cert_type'=>'financial',            'payment_required'=>true, 'initial_units'=>null,'issue_trigger'=>'trustee_resolution_rwa'],
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
    $row = one($pdo,
        "SELECT MAX(CAST(SUBSTRING({$col}, LENGTH(?)+2) AS UNSIGNED)) AS n FROM `{$table}` WHERE {$col} LIKE ?",
        [$prefix, "{$prefix}-%"]);
    $n = (int)($row['n'] ?? 0) + 1;
    return $prefix . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}
}
if (!function_exists('uir_build_hash')) {
function uir_build_hash(string $ref, int $memberId, string $classCode, string $units, string $date, int $cents): string {
    return hash('sha256', implode('|', [$ref, $memberId, $classCode, $units, $date, $cents]));
}
}
if (!function_exists('uir_eligible_members')) {
function uir_eligible_members(PDO $pdo, string $classCode, bool $gate2Open, bool $tablesReady): array {
    $defs = uir_class_defs();
    $def  = $defs[$classCode] ?? null;
    if (!$def || !uir_class_is_open($def, $gate2Open)) return [];
    $issuedIds = [];
    if ($tablesReady) {
        $issued = rows($pdo, "SELECT member_id FROM unit_issuance_register WHERE unit_class_code = ?", [$classCode]);
        $issuedIds = array_map('intval', array_column($issued, 'member_id'));
    }
    if ($classCode === 'C') {
        $all = $tablesReady ? rows($pdo,
            "SELECT m.id,m.member_number,m.full_name,m.email,m.member_type,m.kyc_status,m.signup_payment_status
             FROM members m
             INNER JOIN unit_issuance_register uir ON uir.member_id=m.id AND uir.unit_class_code IN ('S','B')
             WHERE m.is_active=1 ORDER BY m.id ASC") : [];
    } elseif ($classCode === 'S') {
        $all = rows($pdo,
            "SELECT id,member_number,full_name,email,member_type,kyc_status,signup_payment_status
             FROM members WHERE is_active=1 AND member_type='personal' ORDER BY id ASC");
    } elseif ($classCode === 'B') {
        $all = rows($pdo,
            "SELECT id,member_number,full_name,email,member_type,kyc_status,signup_payment_status
             FROM members WHERE is_active=1 AND member_type='business' ORDER BY id ASC");
    } else {
        $all = rows($pdo,
            "SELECT id,member_number,full_name,email,member_type,kyc_status,signup_payment_status
             FROM members WHERE is_active=1 ORDER BY id ASC");
    }
    return array_values(array_filter($all, function($r) use ($issuedIds) {
        return !in_array((int)$r['id'], $issuedIds, true);
    }));
}
}
if (!function_exists('uir_preconditions')) {
function uir_preconditions(PDO $pdo, array $member, string $classCode, bool $gate2Open, int $units, bool $tablesReady): array {
    $defs   = uir_class_defs();
    $def    = $defs[$classCode];
    $checks = [];
    $pass   = true;
    $gateOpen  = uir_class_is_open($def, $gate2Open);
    $gateLabel = 'Gate ' . $def['gate'] . ($def['gate']===1 ? ' (Declaration executed)' : ($def['gate']===2 ? ' (Foundation Day)' : ' (Expansion Day)'));
    $checks[] = ['label'=>$gateLabel,'ok'=>$gateOpen,'note'=>$gateOpen?'Open':'Not yet reached'];
    if (!$gateOpen) $pass = false;
    $kycOk = in_array((string)($member['kyc_status']??''), ['verified','address_verified','manual_verified'], true);
    $checks[] = ['label'=>'KYC / AML-CTF verified','ok'=>$kycOk,'note'=>$kycOk?'Recorded':'Not verified — blocked'];
    if (!$kycOk) $pass = false;
    if ($def['payment_required']) {
        $payOk = (string)($member['signup_payment_status']??'') === 'paid';
        $checks[] = ['label'=>'Payment cleared','ok'=>$payOk,'note'=>$payOk?'Cleared':'Awaiting payment'];
        if (!$payOk) $pass = false;
    } else {
        $checks[] = ['label'=>'Payment','ok'=>true,'note'=>'No monetary consideration for this class'];
    }
    if ($tablesReady) {
        $tot  = one($pdo, "SELECT COALESCE(SUM(units_issued),0) AS t FROM unit_issuance_register WHERE member_id=? AND unit_class_code NOT IN ('Lr')", [(int)$member['id']]);
        $curr = (float)($tot['t']??0);
        $capOk = ($curr + $units) <= 1000000;
        $checks[] = ['label'=>'Anti-capture cap','ok'=>$capOk,'note'=>number_format($curr).' + '.number_format($units).' = '.number_format($curr+$units).($capOk?' ≤ cap':' EXCEEDS CAP')];
        if (!$capOk) $pass = false;
        $dup  = one($pdo, "SELECT id FROM unit_issuance_register WHERE member_id=? AND unit_class_code=? LIMIT 1", [(int)$member['id'],$classCode]);
        $dupOk = ($dup === null);
        $checks[] = ['label'=>'No duplicate issuance','ok'=>$dupOk,'note'=>$dupOk?'None found':'Already issued — blocked'];
        if (!$dupOk) $pass = false;
    } else {
        $checks[] = ['label'=>'Anti-capture cap','ok'=>true,'note'=>'Will be enforced on issue'];
        $checks[] = ['label'=>'No duplicate issuance','ok'=>true,'note'=>'Will be checked on issue'];
    }
    return ['pass'=>$pass,'checks'=>$checks];
}
}

// ── State ─────────────────────────────────────────────────────────────────────

$fdDeclared  = ops_setting_get($pdo, 'governance_foundation_day_declared', '') === 'yes';
$fdDate      = ops_setting_get($pdo, 'governance_foundation_day_date', '');
$gate2Open   = $fdDeclared && $fdDate !== '' && date('Y-m-d') >= $fdDate;
$tablesReady = ops_has_table($pdo, 'unit_issuance_register') && ops_has_table($pdo, 'unitholder_certificates');

$totalIssued=$totalCerts=$pendingEmail=0;
$classBreakdown=$recentIssuances=[];
if ($tablesReady) {
    $totalIssued  = (int)(one($pdo,"SELECT COUNT(*) AS n FROM unit_issuance_register")['n']??0);
    $totalCerts   = (int)(one($pdo,"SELECT COUNT(*) AS n FROM unitholder_certificates")['n']??0);
    $pendingEmail = (int)(one($pdo,"SELECT COUNT(*) AS n FROM unitholder_certificates WHERE email_sent_at IS NULL")['n']??0);
    $classBreakdown = rows($pdo,"SELECT unit_class_code,unit_class_name,COUNT(*) AS cnt,SUM(units_issued) AS total_units FROM unit_issuance_register GROUP BY unit_class_code,unit_class_name ORDER BY unit_class_code");
    $recentIssuances = rows($pdo,"SELECT uir.*,m.full_name,m.member_number,m.email,uc.cert_ref,uc.email_sent_at FROM unit_issuance_register uir INNER JOIN members m ON m.id=uir.member_id LEFT JOIN unitholder_certificates uc ON uc.issuance_id=uir.id ORDER BY uir.created_at DESC LIMIT 50");
}

// ── POST ──────────────────────────────────────────────────────────────────────

$flash=''; $flashType='ok'; $error='';
$adminId = function_exists('ops_current_admin_id') ? (int)ops_current_admin_id($pdo) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        if (!$tablesReady) throw new RuntimeException('SQL migration not yet run — execute migration files via phpMyAdmin first.');
        $action        = (string)($_POST['action']??'');
        $memberId      = (int)($_POST['member_id']??0);
        $unitClassCode = trim((string)($_POST['unit_class_code']??''));
        if ($action === 'issue_unit') {
            if ($memberId <= 0)       throw new RuntimeException('Member ID required.');
            if ($unitClassCode === '') throw new RuntimeException('Unit class required.');
            $defs = uir_class_defs();
            if (!isset($defs[$unitClassCode])) throw new RuntimeException('Unknown unit class.');
            $def = $defs[$unitClassCode];
            if (!uir_class_is_open($def, $gate2Open)) throw new RuntimeException("Class {$unitClassCode} gate not open.");
            $member = one($pdo,"SELECT * FROM members WHERE id=? AND is_active=1 LIMIT 1",[$memberId]);
            if (!$member) throw new RuntimeException('Member not found.');
            if ($unitClassCode === 'C') {
                $unitsIssued = $member['member_type']==='business' ? 10000.0 : 1000.0;
            } elseif ($def['initial_units'] !== null) {
                $unitsIssued = (float)$def['initial_units'];
            } else {
                $unitsIssued = max(1.0,(float)(int)($_POST['units']??1));
            }
            $pre = uir_preconditions($pdo,$member,$unitClassCode,$gate2Open,(int)$unitsIssued,$tablesReady);
            if (!$pre['pass']) {
                $failed = array_filter($pre['checks'],function($c){return !$c['ok'];});
                throw new RuntimeException('Pre-conditions not met: '.implode('; ',array_column($failed,'label')));
            }
            if ($def['payment_required']) {
                $tc = one($pdo,"SELECT unit_price_cents,business_unit_price_cents FROM token_classes WHERE unit_class_code=? LIMIT 1",[$unitClassCode]);
                $cents = ($member['member_type']==='business' && $tc && $tc['business_unit_price_cents']!==null)
                    ? (int)$tc['business_unit_price_cents'] : (int)($tc['unit_price_cents']??0);
            } else { $cents=0; }
            $issueDate = date('Y-m-d');
            $unitsStr  = number_format($unitsIssued,4,'.','' );
            $pdo->beginTransaction();
            $registerRef = uir_next_ref($pdo,'UIR-'.strtoupper($unitClassCode),'unit_issuance_register','register_ref');
            $certRef     = uir_next_ref($pdo,'CERT-'.strtoupper($unitClassCode),'unitholder_certificates','cert_ref');
            $hash        = uir_build_hash($registerRef,$memberId,$unitClassCode,$unitsStr,$issueDate,$cents);
            $pdo->prepare("INSERT INTO unit_issuance_register (register_ref,member_id,unit_class_code,unit_class_name,cert_type,units_issued,consideration_cents,issue_date,issue_trigger,gate,kyc_verified,payment_cleared,anti_cap_checked,gate_satisfied,sha256_hash,issued_by_admin_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,1,1,?,?,?,?)")->execute([$registerRef,$memberId,$unitClassCode,$def['name'],$def['cert_type'],$unitsStr,$cents,$issueDate,$def['issue_trigger'],$def['gate'],$def['payment_required']?1:0,$hash,$adminId?:null,now_dt(),now_dt()]);
            $issuanceId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO unitholder_certificates (cert_ref,issuance_id,member_id,unit_class_code,cert_type,units,issue_date,email_sent_to,created_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$certRef,$issuanceId,$memberId,$unitClassCode,$def['cert_type'],$unitsStr,$issueDate,$member['email'],now_dt()]);
            $certId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE unit_issuance_register SET certificate_sent_at=? WHERE id=?")->execute([now_dt(),$issuanceId]);
            $emailQueueId=0;
            if (function_exists('queueEmail')) {
                $payload=['full_name'=>$member['full_name'],'first_name'=>$member['first_name']??'','email'=>$member['email'],'member_number'=>$member['member_number'],'unit_class_code'=>$unitClassCode,'unit_class_name'=>$def['name'],'cert_type'=>$def['cert_type'],'units_issued'=>$unitsStr,'issue_date'=>$issueDate,'register_ref'=>$registerRef,'cert_ref'=>$certRef,'sha256_hash'=>$hash,'consideration_cents'=>$cents,'issue_trigger'=>$def['issue_trigger'],'gate'=>$def['gate'],'member_type'=>$member['member_type']];
                $emailQueueId=queueEmail($pdo,'unit_certificate',$issuanceId,(string)$member['email'],'unitholder_certificate','COG$ Certificate — '.$def['name'].' — '.$certRef,$payload);
                if ($emailQueueId>0) $pdo->prepare("UPDATE unitholder_certificates SET email_sent_at=?,email_queue_id=? WHERE id=?")->execute([now_dt(),$emailQueueId,$certId]);
            }
            $pdo->commit();
            $flash='Unit issued. Register: '.$registerRef.' | Certificate: '.$certRef.($emailQueueId>0?' | Email queued.':' | Email unavailable — record saved.');
            $flashType='ok';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}

$viewTab     = in_array((string)($_GET['tab']??''),['issue','register','certs'],true) ? (string)$_GET['tab'] : 'issue';
$issueClass  = trim((string)($_GET['issue_class']??''));
$selMemberId = (int)($_GET['member_id']??0);
$defs        = uir_class_defs();
$csrf        = h(admin_csrf_token());
$todayDate   = date('Y-m-d');

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unit Issuance — COG$ Admin</title>
<style>
.tab-nav{display:flex;gap:0;margin-bottom:20px;border:1px solid var(--line);border-radius:12px;overflow:hidden;width:fit-content}
.tab-link{padding:9px 22px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;border-right:1px solid var(--line);transition:all .15s}
.tab-link:last-child{border-right:none}
.tab-link.active{background:rgba(212,178,92,.15);color:var(--gold)}
.tab-link:hover:not(.active){background:rgba(255,255,255,.04);color:var(--text)}
.chk-pass{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;background:var(--okb);color:var(--ok)}
.chk-fail{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;background:var(--errb);color:var(--err)}
.gate-open{background:var(--okb);color:var(--ok);border:1px solid rgba(82,184,122,.25);border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;text-transform:uppercase}
.gate-pend{background:var(--warnb);color:var(--warn);border:1px solid rgba(200,144,26,.25);border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;text-transform:uppercase}
.gate-lock{background:rgba(255,255,255,.04);color:var(--dim);border:1px solid var(--line);border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;text-transform:uppercase}
</style>
</head>
<body>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
<div class="admin-shell">
<?php admin_sidebar_render('unit_issuance'); ?>
<main class="main">

<div class="card" style="margin-bottom:20px;">
  <div class="card-body">
    <h1 style="margin:0 0 6px">🏅 Unit Issuance Register</h1>
    <p class="muted">Trustee-triggered unit issuance across all classes. Records each issue in the formal legal register and dispatches unitholder certificates. Run SQL migrations before first use.</p>
  </div>
</div>

<?php if ($flash !== ''): ?>
<div class="alert alert-<?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-err">Error: <?= h($error) ?></div>
<?php endif; ?>

<?php if (!$tablesReady): ?>
<div class="alert alert-err">
  <strong>&#x26A0; SQL migration required</strong> &mdash; Tables not found in the database.<br>
  Run these files via phpMyAdmin against <code>cogsaust_TRUST</code>, then reload:
  <ol style="margin:.5rem 0 0 1.2rem;line-height:2;">
    <li><code>2026_04_25_unit_issuance_register.sql</code></li>
    <li><code>2026_04_25_unitholder_certificate_email_template.sql</code></li>
  </ol>
</div>
<?php else: ?>

<div class="alert <?= $gate2Open ? 'alert-ok' : 'alert-amber' ?>" style="margin-bottom:18px;">
<?php if ($gate2Open): ?>
  &#x2705; <strong>Gate 2 open</strong> &mdash; Foundation Day declared <?= h($fdDate) ?>. Classes kS, C, P, D, Lr now available.
<?php elseif ($fdDeclared && $fdDate !== ''): ?>
  &#x23F3; <strong>Gate 2 pending</strong> &mdash; Foundation Day scheduled <?= h($fdDate) ?>. Classes kS, C, P, D, Lr unlock on that date.
<?php else: ?>
  &#x26A0;&#xFE0F; <strong>Gate 2 not declared</strong> &mdash; Only Classes S and B (Gate 1) are currently issuable.
  <a href="<?= h(admin_url('foundation_day.php')) ?>" class="btn btn-gold" style="margin-left:12px;font-size:12px;">Foundation Day &rarr;</a>
<?php endif; ?>
</div>

<div class="stats" style="margin-bottom:22px;">
  <div class="stat"><div class="stat-val"><?= $totalIssued ?></div><div class="stat-label">Units Issued</div></div>
  <div class="stat"><div class="stat-val"><?= $totalCerts ?></div><div class="stat-label">Certificates</div></div>
  <div class="stat">
    <div class="stat-val" style="color:<?= $pendingEmail > 0 ? 'var(--warn)' : 'var(--ok)' ?>"><?= $pendingEmail ?></div>
    <div class="stat-label">Certs Pending Email</div>
  </div>
  <div class="stat"><div class="stat-val"><?= count($defs) ?></div><div class="stat-label">Total Classes</div></div>
</div>

<div class="tab-nav">
  <a href="?tab=issue"    class="tab-link <?= $viewTab==='issue'    ? 'active' : '' ?>">&#x1F4CB; Issue Units</a>
  <a href="?tab=register" class="tab-link <?= $viewTab==='register' ? 'active' : '' ?>">&#x1F4DA; Issuance Register</a>
  <a href="?tab=certs"    class="tab-link <?= $viewTab==='certs'    ? 'active' : '' ?>">&#x1F3C5; Certificates</a>
</div>

<?php if ($viewTab === 'issue'): ?>

<div class="card">
  <div class="card-head"><h2>All Unit Classes &mdash; Gate Status &amp; Issuance</h2></div>
  <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr><th>Code</th><th>Class Name</th><th>Gate</th><th>Cert Type</th><th>Consideration</th><th>Status</th><th>Issued</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($defs as $code => $def):
            $isOpen    = uir_class_is_open($def, $gate2Open);
            $issuedCnt = $tablesReady ? (int)(one($pdo,"SELECT COUNT(*) AS n FROM unit_issuance_register WHERE unit_class_code=?",[$code])['n']??0) : 0;
            $certLabel = $def['cert_type']==='financial' ? 'Financial' : ($def['cert_type']==='community' ? 'Community' : 'Gov. Alloc.');
        ?>
        <tr>
          <td><span class="mono"><?= h($code) ?></span></td>
          <td><?= h($def['name']) ?></td>
          <td class="mono small">Gate <?= $def['gate'] ?></td>
          <td><span class="st <?= $def['cert_type']==='financial' ? 'st-ok' : 'st-blue' ?>"><?= h($certLabel) ?></span></td>
          <td class="muted small"><?= $def['payment_required'] ? 'Payment required' : 'No fee' ?></td>
          <td>
            <?php if ($isOpen): ?><span class="gate-open">Open</span>
            <?php elseif ($def['gate']===3): ?><span class="gate-lock">Expansion Day</span>
            <?php else: ?><span class="gate-pend">Pending</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= $issuedCnt ?></td>
          <td>
            <?php if ($isOpen): ?>
              <a href="?tab=issue&amp;issue_class=<?= urlencode($code) ?>" class="btn btn-gold btn-sm">Issue <?= h($code) ?></a>
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

<?php if ($issueClass !== '' && isset($defs[$issueClass]) && uir_class_is_open($defs[$issueClass], $gate2Open)):
    $iDef  = $defs[$issueClass];
    $eligs = uir_eligible_members($pdo, $issueClass, $gate2Open, $tablesReady);
?>
<div class="card" style="margin-top:16px;">
  <div class="card-head"><h2>Issue: <?= h($iDef['name']) ?> (Class <?= h($issueClass) ?>)</h2></div>
  <div class="card-body">
    <?php if (empty($eligs)): ?>
      <div class="alert alert-ok">All eligible members have already been issued Class <?= h($issueClass) ?> units.</div>
    <?php else: ?>
      <div style="margin-bottom:16px;">
        <label style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--sub);display:block;margin-bottom:6px;">Select Member</label>
        <select onchange="location='?tab=issue&amp;issue_class=<?= urlencode($issueClass) ?>&amp;member_id='+this.value" style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;color:var(--text);font-size:13px;padding:7px 10px;min-width:300px;">
          <option value="0">&mdash; Select member &mdash;</option>
          <?php foreach ($eligs as $em): ?>
          <option value="<?= h((string)$em['id']) ?>" <?= (int)$em['id']===$selMemberId ? 'selected' : '' ?>><?= h($em['full_name']) ?> &mdash; <?= h($em['member_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($selMemberId > 0):
          $selMember = one($pdo,"SELECT * FROM members WHERE id=? AND is_active=1 LIMIT 1",[$selMemberId]);
          if ($selMember !== null):
              $unitsForClass = $issueClass==='C' ? ($selMember['member_type']==='business' ? 10000 : 1000) : (int)($iDef['initial_units']??1);
              $pre = uir_preconditions($pdo,$selMember,$issueClass,$gate2Open,$unitsForClass,$tablesReady);
      ?>
      <div class="card" style="background:var(--panel2);margin-bottom:14px;">
        <div class="card-head"><h2>Pre-condition Checklist &mdash; <?= h($selMember['full_name']) ?></h2></div>
        <table>
          <thead><tr><th>Condition</th><th>Status</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($pre['checks'] as $chk): ?>
            <tr>
              <td><?= h($chk['label']) ?></td>
              <td><?= $chk['ok'] ? '<span class="chk-pass">&#x2713; Pass</span>' : '<span class="chk-fail">&#x2717; Fail</span>' ?></td>
              <td class="muted small"><?= h($chk['note']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="card-body" style="padding-top:8px;">
          <strong style="color:<?= $pre['pass'] ? 'var(--ok)' : 'var(--err)' ?>">
            <?= $pre['pass'] ? '&#x2705; All conditions satisfied &mdash; ready to issue' : '&#x274C; One or more conditions failed &mdash; cannot issue' ?>
          </strong>
        </div>
      </div>
      <div class="card" style="background:var(--panel2);margin-bottom:14px;">
        <div class="card-head"><h2>Issuance Summary</h2></div>
        <div class="card-body">
          <table style="width:auto;">
            <tr><td style="color:var(--sub);padding-right:24px;padding-bottom:5px;">Member</td><td><?= h($selMember['full_name']) ?> &mdash; <span class="mono"><?= h($selMember['member_number']) ?></span></td></tr>
            <tr><td style="color:var(--sub);padding-bottom:5px;">Unit class</td><td><?= h($iDef['name']) ?> (Class <?= h($issueClass) ?>)</td></tr>
            <tr><td style="color:var(--sub);padding-bottom:5px;">Units</td><td class="mono"><?= number_format($unitsForClass) ?></td></tr>
            <tr><td style="color:var(--sub);padding-bottom:5px;">Cert type</td><td><?= h($iDef['cert_type']) ?></td></tr>
            <tr><td style="color:var(--sub);padding-bottom:5px;">Trigger</td><td class="mono small"><?= h($iDef['issue_trigger']) ?></td></tr>
            <tr><td style="color:var(--sub);padding-bottom:5px;">Issue date</td><td><?= h($todayDate) ?></td></tr>
            <tr><td style="color:var(--sub);">Email to</td><td><?= h($selMember['email']) ?></td></tr>
          </table>
        </div>
      </div>
      <?php if ($pre['pass']): ?>
      <form method="POST" action="?tab=issue&amp;issue_class=<?= urlencode($issueClass) ?>&amp;member_id=<?= h((string)$selMemberId) ?>"
            onsubmit="return confirm('Issue <?= h($iDef['name']) ?> to <?= h(addslashes($selMember['full_name'])) ?>?\n\nThis is recorded in the legal unit register and cannot be undone.');">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="issue_unit">
        <input type="hidden" name="member_id" value="<?= h((string)$selMemberId) ?>">
        <input type="hidden" name="unit_class_code" value="<?= h($issueClass) ?>">
        <button type="submit" class="btn btn-gold">&#x1F3C5; Issue <?= h($iDef['name']) ?> to <?= h($selMember['full_name']) ?></button>
        <span class="muted small" style="margin-left:12px;">Permanent. Recorded in legal register.</span>
      </form>
      <?php else: ?>
      <div class="alert alert-err">Resolve all failed conditions before issuing.</div>
      <?php endif; ?>
      <?php endif; // selMember ?>
      <?php endif; // selMemberId ?>
    <?php endif; // eligs ?>
  </div>
</div>
<?php endif; // issueClass ?>

<?php elseif ($viewTab === 'register'): ?>

<div class="card">
  <div class="card-head"><h2>Unit Issuance Register</h2><span class="muted small"><?= $totalIssued ?> records</span></div>
  <?php if (empty($recentIssuances)): ?>
    <div class="empty">No units have been issued yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;"><table>
    <thead><tr><th>Register Ref</th><th>Member</th><th>Class</th><th>Units</th><th>Cert Type</th><th>Date</th><th>KYC</th><th>Paid</th><th>SHA-256</th><th>Certificate</th></tr></thead>
    <tbody>
      <?php foreach ($recentIssuances as $row): ?>
      <tr>
        <td class="mono small"><?= h($row['register_ref']) ?></td>
        <td><div><?= h($row['full_name']) ?></div><div class="muted small"><?= h($row['member_number']) ?></div></td>
        <td><span class="badge badge-ok"><?= h($row['unit_class_code']) ?></span><div class="muted small"><?= h($row['unit_class_name']) ?></div></td>
        <td class="mono"><?= number_format((float)$row['units_issued']) ?></td>
        <td><span class="st <?= $row['cert_type']==='financial'?'st-ok':'st-blue' ?>"><?= h($row['cert_type']) ?></span></td>
        <td class="small"><?= h($row['issue_date']) ?></td>
        <td><?= $row['kyc_verified'] ? '<span class="st st-ok">&#x2713;</span>' : '<span class="st st-bad">&#x2717;</span>' ?></td>
        <td><?= $row['payment_cleared'] ? '<span class="st st-ok">&#x2713;</span>' : '<span class="st st-dim">&mdash;</span>' ?></td>
        <td class="mono small" title="<?= h((string)($row['sha256_hash']??'')) ?>"><?= h(substr((string)($row['sha256_hash']??''),0,12)) ?>&hellip;</td>
        <td>
          <?php if (!empty($row['cert_ref'])): ?>
            <div class="mono small"><?= h($row['cert_ref']) ?></div>
            <div class="muted small"><?= !empty($row['email_sent_at']) ? '&#x2705; '.h(substr($row['email_sent_at'],0,10)) : '&#x23F3; Queued' ?></div>
          <?php else: ?><span class="muted small">&mdash;</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if (!empty($classBreakdown)): ?>
<div class="card">
  <div class="card-head"><h2>Issued by Class</h2></div>
  <table>
    <thead><tr><th>Code</th><th>Class Name</th><th>Members</th><th>Total Units</th></tr></thead>
    <tbody>
      <?php foreach ($classBreakdown as $cb): ?>
      <tr>
        <td><span class="badge badge-ok mono"><?= h($cb['unit_class_code']) ?></span></td>
        <td><?= h($cb['unit_class_name']) ?></td>
        <td class="mono"><?= h($cb['cnt']) ?></td>
        <td class="mono"><?= number_format((float)$cb['total_units']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php elseif ($viewTab === 'certs'): ?>

<?php $certRows = rows($pdo,"SELECT uc.*,m.full_name,m.member_number,uir.unit_class_name,uir.register_ref,uir.sha256_hash FROM unitholder_certificates uc INNER JOIN members m ON m.id=uc.member_id INNER JOIN unit_issuance_register uir ON uir.id=uc.issuance_id ORDER BY uc.created_at DESC LIMIT 100"); ?>
<div class="card">
  <div class="card-head"><h2>Certificate Register</h2><span class="muted small"><?= $totalCerts ?> certificates</span></div>
  <?php if (empty($certRows)): ?>
    <div class="empty">No certificates have been issued yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;"><table>
    <thead><tr><th>Cert Ref</th><th>Register Ref</th><th>Member</th><th>Class</th><th>Cert Type</th><th>Units</th><th>Date</th><th>Email</th></tr></thead>
    <tbody>
      <?php foreach ($certRows as $c): ?>
      <tr>
        <td class="mono small"><?= h($c['cert_ref']) ?></td>
        <td class="mono small"><?= h($c['register_ref']) ?></td>
        <td><div><?= h($c['full_name']) ?></div><div class="muted small"><?= h($c['member_number']) ?></div></td>
        <td><span class="badge badge-ok"><?= h($c['unit_class_code']) ?></span><div class="muted small"><?= h($c['unit_class_name']) ?></div></td>
        <td><span class="st <?= $c['cert_type']==='financial'?'st-ok':'st-blue' ?>"><?= h($c['cert_type']) ?></span></td>
        <td class="mono"><?= number_format((float)$c['units']) ?></td>
        <td class="small"><?= h($c['issue_date']) ?></td>
        <td>
          <?php if (!empty($c['email_sent_at'])): ?>
            <span class="st st-ok">&#x2705; <?= h(substr($c['email_sent_at'],0,10)) ?></span>
            <div class="muted small"><?= h((string)($c['email_sent_to']??'')) ?></div>
          <?php else: ?><span class="st st-warn">&#x23F3; Queued</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php endif; // viewTab ?>
<?php endif; // tablesReady ?>

</main>
</div>
</body>
</html>

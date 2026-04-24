<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// bootstrap.php → cogs_load_env_once() + env() — confirmed working (stripe_diag used it)
require_once __DIR__ . '/_app/api/config/bootstrap.php';

// Now use env() to get DB credentials — same as database.php does
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST','localhost'), env('DB_PORT','3306'), env('DB_DATABASE',''));
    $db = new PDO($dsn, env('DB_USERNAME',''), env('DB_PASSWORD',''),
        [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    die('<pre style="color:red">DB connect failed: '.htmlspecialchars($e->getMessage()).'</pre>');
}

$cookieVal = $_COOKIE['cogs_session'] ?? '';
$memberId  = 0; $memberNum = '';

if ($cookieVal) {
    $s = $db->prepare("SELECT principal_id FROM sessions WHERE id = ? AND expires_at > NOW() LIMIT 1");
    $s->execute([$cookieVal]);
    $row = $s->fetch();
    if ($row) {
        $memberId = (int)$row['principal_id'];
        $mr = $db->prepare("SELECT member_number FROM members WHERE id = ? LIMIT 1");
        $mr->execute([$memberId]);
        $m = $mr->fetch();
        $memberNum = $m['member_number'] ?? '?';
    }
}

$labelToFrontend = [
    'donation cog$'       => 'donation_tokens',
    'donation'            => 'donation_tokens',
    'pay it forward cog$' => 'pay_it_forward_tokens',
    'pay it forward'      => 'pay_it_forward_tokens',
];
?><!DOCTYPE html><html><head><title>vault diag</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:20px;font-size:.82rem;line-height:1.6}
pre{background:#181108;border:1px solid #333;padding:10px 14px;border-radius:6px;white-space:pre-wrap;word-break:break-all;margin:4px 0 12px}
h3{color:#e8b84b;margin:18px 0 6px}.ok{color:#3ecf6e}.err{color:#ff6b6b}.warn{color:#e8b84b}</style>
</head><body>
<h2>Vault payment diagnostic</h2>
<?php if (!$cookieVal): ?>
<p class="err">No cogs_session cookie — must be visited while logged into the IV wallet.</p>
<?php elseif (!$memberId): ?>
<p class="err">Cookie present but session not found or expired. Log in again and retry.</p>
<?php else: ?>
<p class="ok">✓ Member ID: <strong><?=$memberId?></strong> (<?=htmlspecialchars($memberNum)?>)</p>

<?php
// Gift pool rows
$gpStmt = $db->prepare(
    "SELECT id,external_reference,amount_cents,payment_type,payment_status,notes,received_at,created_at
       FROM payments WHERE member_id=? AND payment_type='adjustment'
         AND payment_status='pending' AND received_at IS NULL ORDER BY id ASC");
$gpStmt->execute([$memberId]);
$gpRows = $gpStmt->fetchAll();
?>
<h3>Gift pool payments (adjustment/pending/no received_at) — <?=count($gpRows)?> rows</h3>
<?php foreach ($gpRows as $r):
    $notes=$r['notes']??'';
    $regexOk=preg_match('/(\d+)\s*x\s+(.+?)\.\s*Reference:/i',$notes,$nm);
    $cls=''; $units=0;
    if ($regexOk){ $units=(int)$nm[1]; $cls=$labelToFrontend[strtolower(trim($nm[2]))]??''; }
?>
<pre>id=<?=$r['id']?> cents=<?=$r['amount_cents']?> status=<?=$r['payment_status']?>  received_at=<?=$r['received_at']??'NULL'?>
notes=<?=htmlspecialchars($notes)?>

regex: <?=$regexOk?'<span class="ok">MATCH</span>':'<span class="err">NO MATCH → invisible to checkout</span>'?>
<?php if($regexOk):?>parsed units=<?=$units?> class=<?=$cls?'<span class="ok">'.htmlspecialchars($cls).'</span>':'<span class="err">UNRECOGNISED: '.htmlspecialchars(trim($nm[2])).'</span>'?><?php endif?>
</pre>
<?php endforeach; if(!$gpRows) echo '<p class="warn">No gift pool rows found.</p>'; ?>

<?php
// Kids rows
$kStmt=$db->prepare(
    "SELECT id,amount_cents,payment_type,payment_status,notes,received_at
       FROM payments WHERE member_id=? AND payment_type='kids_snft'
         AND payment_status='pending' AND received_at IS NULL ORDER BY id ASC");
$kStmt->execute([$memberId]);
$kRows=$kStmt->fetchAll();
?>
<h3>Kids payments — <?=count($kRows)?> rows</h3>
<?php foreach($kRows as $r): ?>
<pre>id=<?=$r['id']?> cents=<?=$r['amount_cents']?> notes=<?=htmlspecialchars($r['notes']??'')?></pre>
<?php endforeach; if(!$kRows) echo '<p>None.</p>'; ?>

<?php
// All payments
$allStmt=$db->prepare(
    "SELECT id,payment_type,payment_status,amount_cents,received_at,notes,created_at
       FROM payments WHERE member_id=? ORDER BY id DESC LIMIT 20");
$allStmt->execute([$memberId]);
$allRows=$allStmt->fetchAll();
?>
<h3>All payments (last 20, newest first)</h3>
<?php foreach($allRows as $r): ?>
<pre>id=<?=$r['id']?> type=<?=$r['payment_type']?> status=<?=$r['payment_status']?> cents=<?=$r['amount_cents']?> received_at=<?=$r['received_at']??'NULL'?>
notes=<?=htmlspecialchars(substr($r['notes']??'',0,150))?></pre>
<?php endforeach; ?>

<?php endif; ?>
<p class="err" style="margin-top:20px"><strong>⚠ DELETE vault_diag.php immediately.</strong></p>
</body></html>

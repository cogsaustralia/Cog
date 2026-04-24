<?php
/**
 * Self-contained vault diagnostic — no helper dependencies.
 * DELETE immediately after viewing.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ── Read .env manually ───────────────────────────────────────────────────────
$env = [];
foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $ef) {
    if (!file_exists($ef)) continue;
    foreach (file($ef) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    break;
}

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $host = $env['DB_HOST'] ?? 'localhost';
    $port = $env['DB_PORT'] ?? '3306';
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? '';
    $pass = $env['DB_PASSWORD'] ?? '';
    $dsn  = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $db   = new PDO($dsn, $user, $pass, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    die('<pre style="color:red">DB connect failed: ' . htmlspecialchars($e->getMessage()) . '</pre>');
}

// ── Session cookie → member_id ───────────────────────────────────────────────
$cookieVal = $_COOKIE['cogs_session'] ?? '';
$memberId  = 0;
$memberNum = '';

if ($cookieVal) {
    // sessions.id IS the cookie value
    $s = $db->prepare("SELECT principal_id FROM sessions WHERE id = ? AND expires_at > NOW() LIMIT 1");
    $s->execute([$cookieVal]);
    $row = $s->fetch();
    if ($row) {
        $memberId = (int)$row['principal_id'];
        $mRow = $db->prepare("SELECT member_number FROM members WHERE id = ? LIMIT 1");
        $mRow->execute([$memberId]);
        $mr = $mRow->fetch();
        $memberNum = $mr['member_number'] ?? '?';
    }
}

?><!DOCTYPE html><html><head><title>vault diag</title>
<style>
body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:20px;font-size:.82rem;line-height:1.6}
pre{background:#181108;border:1px solid #333;padding:10px 14px;border-radius:6px;white-space:pre-wrap;word-break:break-all;margin:4px 0 12px}
h3{color:#e8b84b;margin:18px 0 6px;font-size:1rem}
.ok{color:#3ecf6e}.err{color:#ff6b6b}.warn{color:#e8b84b}
</style></head><body>
<h2>Vault payment diagnostic</h2>

<?php if (!$cookieVal): ?>
<p class="err">No cogs_session cookie. Must be visited while logged into the IV wallet.</p>
<?php elseif (!$memberId): ?>
<p class="err">Cookie found but session not valid or expired. Log in again then retry.</p>
<?php else: ?>
<p class="ok">✓ Logged in — Member ID: <strong><?= $memberId ?></strong> (<?= htmlspecialchars($memberNum) ?>)</p>

<?php
// ── Gift pool rows (adjustment type, pending, no received_at) ──────────────
$labelToFrontend = [
    'donation cog$'        => 'donation_tokens',
    'donation'             => 'donation_tokens',
    'pay it forward cog$'  => 'pay_it_forward_tokens',
    'pay it forward'       => 'pay_it_forward_tokens',
];

$gpStmt = $db->prepare(
    "SELECT id, external_reference, amount_cents, payment_type, payment_status, notes, received_at, created_at
       FROM payments
      WHERE member_id = ?
        AND payment_type = 'adjustment'
        AND payment_status = 'pending'
        AND received_at IS NULL
      ORDER BY id ASC"
);
$gpStmt->execute([$memberId]);
$gpRows = $gpStmt->fetchAll();
?>
<h3>Gift pool payments (adjustment/pending/no received_at) — <?= count($gpRows) ?> rows</h3>
<?php foreach ($gpRows as $r):
    $notes = (string)($r['notes'] ?? '');
    $regexOk = false; $cls = ''; $units = 0;
    if (preg_match('/(\d+)\s*x\s+(.+?)\.\s*Reference:/i', $notes, $nm)) {
        $units = (int)$nm[1];
        $lbl   = trim($nm[2]);
        $cls   = $labelToFrontend[strtolower($lbl)] ?? '';
        $regexOk = true;
    }
?>
<pre>id=<?= $r['id'] ?> cents=<?= $r['amount_cents'] ?> type=<?= $r['payment_type'] ?> status=<?= $r['payment_status'] ?>
received_at=<?= $r['received_at'] ?? 'NULL' ?>
notes=<?= htmlspecialchars($notes) ?>
regex match: <?= $regexOk ? '<span class="ok">YES</span>' : '<span class="err">NO → invisible to checkout</span>' ?>
<?php if ($regexOk): ?>parsed: units=<?= $units ?> class=<?= $cls ? '<span class="ok">'.htmlspecialchars($cls).'</span>' : '<span class="err">UNRECOGNISED label: '.htmlspecialchars(trim($nm[2] ?? '')).'</span>' ?><?php endif ?>
</pre>
<?php endforeach;
if (!$gpRows) echo '<pre class="warn">No rows found — no outstanding gift pool items.</pre>';

// ── Kids rows ──────────────────────────────────────────────────────────────
$kStmt = $db->prepare(
    "SELECT id, amount_cents, payment_type, payment_status, notes, received_at, created_at
       FROM payments
      WHERE member_id = ?
        AND payment_type = 'kids_snft'
        AND payment_status = 'pending'
        AND received_at IS NULL
      ORDER BY id ASC"
);
$kStmt->execute([$memberId]);
$kRows = $kStmt->fetchAll();
?>
<h3>Kids payments (kids_snft/pending/no received_at) — <?= count($kRows) ?> rows</h3>
<?php foreach ($kRows as $r): ?>
<pre>id=<?= $r['id'] ?> cents=<?= $r['amount_cents'] ?> notes=<?= htmlspecialchars((string)$r['notes']) ?></pre>
<?php endforeach;
if (!$kRows) echo '<pre>None.</pre>';

// ── ALL pending payments ────────────────────────────────────────────────────
$allStmt = $db->prepare(
    "SELECT id, payment_type, payment_status, amount_cents, received_at, notes, created_at
       FROM payments WHERE member_id = ?
       ORDER BY id DESC LIMIT 20"
);
$allStmt->execute([$memberId]);
$allRows = $allStmt->fetchAll();
?>
<h3>All payments for this member (last 20) — <?= count($allRows) ?> rows</h3>
<?php foreach ($allRows as $r): ?>
<pre>id=<?= $r['id'] ?> type=<?= $r['payment_type'] ?> status=<?= $r['payment_status'] ?> cents=<?= $r['amount_cents'] ?> received_at=<?= $r['received_at']??'NULL' ?>
notes=<?= htmlspecialchars(substr((string)$r['notes'],0,120)) ?></pre>
<?php endforeach; ?>

<?php endif; ?>
<p class="err" style="margin-top:24px"><strong>⚠ DELETE vault_diag.php from public_html immediately.</strong></p>
</body></html>

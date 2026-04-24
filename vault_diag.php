<?php
/**
 * Shows raw outstanding payment rows for the logged-in member.
 * DELETE immediately after viewing.
 */
require_once __DIR__ . '/_app/api/config/database.php';
require_once __DIR__ . '/_app/api/helpers.php';

$sessionToken = $_COOKIE['cogs_session'] ?? '';
if (!$sessionToken) die('<pre>No cogs_session cookie. Must be logged in.</pre>');

try {
    $db = getDB();
} catch (Throwable $e) { die('<pre>DB error: '.htmlspecialchars($e->getMessage()).'</pre>'); }

$sess = $db->prepare("SELECT principal_id FROM sessions WHERE token = ? AND expires_at > NOW() LIMIT 1");
$sess->execute([$sessionToken]);
$s = $sess->fetch();
if (!$s) die('<pre>Session not found or expired.</pre>');
$memberId = (int)$s['principal_id'];

// Adjustment payments (Donation / PIF) — mirrors vault.php exactly
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
$gpRows = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

// Kids payments
$kStmt = $db->prepare(
    "SELECT id, external_reference, amount_cents, payment_type, payment_status, notes, received_at, created_at
       FROM payments
      WHERE member_id = ?
        AND payment_type = 'kids_snft'
        AND payment_status = 'pending'
        AND received_at IS NULL
      ORDER BY id ASC"
);
$kStmt->execute([$memberId]);
$kRows = $kStmt->fetchAll(PDO::FETCH_ASSOC);

// ALL pending payments — catch-all to see everything
$allStmt = $db->prepare(
    "SELECT id, payment_type, payment_status, amount_cents, notes, received_at, created_at
       FROM payments WHERE member_id = ? AND payment_status = 'pending'
       ORDER BY id ASC LIMIT 20"
);
$allStmt->execute([$memberId]);
$allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC);

// Test regex on each gift row
$labelToFrontend = [
    'donation cog$' => 'donation_tokens',
    'donation'      => 'donation_tokens',
    'pay it forward cog$' => 'pay_it_forward_tokens',
    'pay it forward'      => 'pay_it_forward_tokens',
];
?><!DOCTYPE html><html><head><title>vault diag</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:20px;font-size:.82rem}
pre{background:#181108;border:1px solid #333;padding:12px;border-radius:6px;white-space:pre-wrap;word-break:break-all}
h3{color:#e8b84b;margin:16px 0 6px}.ok{color:#3ecf6e}.err{color:#ff6b6b}</style></head><body>
<h2>Vault payment diagnostic</h2>
<p>Member ID: <strong><?= $memberId ?></strong></p>

<h3>Adjustment payments (gift pool) — <?= count($gpRows) ?> rows</h3>
<?php foreach ($gpRows as $r):
    $notes = (string)($r['notes'] ?? '');
    $parsed = false; $cls = ''; $units = 0;
    if (preg_match('/(\d+)\s*x\s+(.+?)\.\s*Reference:/i', $notes, $nm)) {
        $units = (int)$nm[1]; $label = trim($nm[2]);
        $cls = $labelToFrontend[strtolower($label)] ?? '(UNRECOGNISED: '.htmlspecialchars($label).')';
        $parsed = true;
    }
?>
<pre>id=<?= $r['id'] ?> amount=<?= $r['amount_cents'] ?>c status=<?= $r['payment_status'] ?>
notes=<?= htmlspecialchars($notes) ?>
regex match: <?= $parsed ? '<span class="ok">YES</span>' : '<span class="err">NO — row will be invisible to checkout</span>' ?>
<?php if ($parsed): ?>parsed: units=<?= $units ?> class=<?= htmlspecialchars($cls) ?><?php endif ?>
</pre>
<?php endforeach; if (!$gpRows): ?><p class="err">No adjustment/pending rows found.</p><?php endif; ?>

<h3>Kids payments — <?= count($kRows) ?> rows</h3>
<?php foreach ($kRows as $r): ?>
<pre>id=<?= $r['id'] ?> amount=<?= $r['amount_cents'] ?>c notes=<?= htmlspecialchars((string)$r['notes']) ?></pre>
<?php endforeach; if (!$kRows): ?><p>None.</p><?php endif; ?>

<h3>All pending payments (any type) — <?= count($allRows) ?> rows</h3>
<?php foreach ($allRows as $r): ?>
<pre>id=<?= $r['id'] ?> type=<?= $r['payment_type'] ?> status=<?= $r['payment_status'] ?> amount=<?= $r['amount_cents'] ?>c received_at=<?= $r['received_at']??'NULL' ?>
notes=<?= htmlspecialchars((string)$r['notes']) ?></pre>
<?php endforeach; if (!$allRows): ?><p>No pending payments at all.</p><?php endif; ?>

<p class="err" style="margin-top:20px"><strong>⚠ DELETE vault_diag.php immediately.</strong></p>
</body></html>

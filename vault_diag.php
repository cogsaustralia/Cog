<?php
/**
 * Shows outstanding payment data from vault for the authenticated session.
 * DELETE immediately after viewing.
 * URL: https://cogsaustralia.org/vault_diag.php
 */
require_once __DIR__ . '/_app/api/config/database.php';
require_once __DIR__ . '/_app/api/helpers.php';

// Read session cookie
$sessionToken = $_COOKIE['cogs_session'] ?? '';
if (!$sessionToken) { die('<pre>No cogs_session cookie found. Must be logged in to the IV wallet.</pre>'); }

$db = getDB();

// Resolve member from session
$sess = $db->prepare("SELECT principal_id FROM sessions WHERE token = ? AND expires_at > NOW() LIMIT 1");
$sess->execute([$sessionToken]);
$s = $sess->fetch();
if (!$s) { die('<pre>Session not found or expired.</pre>'); }
$memberId = (int)$s['principal_id'];

// Raw gift pool payments
$gpStmt = $db->prepare("
    SELECT p.id, p.amount_cents, p.status, p.notes, p.created_at
    FROM payments p
    WHERE p.member_id = ?
      AND p.status = 'pending'
      AND p.payment_type IN ('gift_pool', 'donation', 'pay_it_forward')
    ORDER BY p.id ASC
");
$gpStmt->execute([$memberId]);
$gpRows = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

// Kids pending orders
$kStmt = $db->prepare("
    SELECT id, units, amount_cents, status, created_at
    FROM kids_snft_orders
    WHERE member_id = ? AND status = 'pending'
    ORDER BY id ASC
");
$kStmt->execute([$memberId]);
$kRows = $kStmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre style='background:#0f0d09;color:#f0e8d6;padding:20px;font-family:monospace;font-size:.82rem'>";
echo "Member ID: $memberId\n\n";
echo "=== Pending gift_pool payments (" . count($gpRows) . " rows) ===\n";
foreach ($gpRows as $r) {
    echo "  id={$r['id']} amount_cents={$r['amount_cents']} status={$r['status']}\n";
    echo "  notes=" . json_encode($r['notes']) . "\n";
    echo "  created_at={$r['created_at']}\n\n";
}
echo "=== Pending kids_snft_orders (" . count($kRows) . " rows) ===\n";
foreach ($kRows as $r) {
    echo "  id={$r['id']} units={$r['units']} amount_cents={$r['amount_cents']} status={$r['status']}\n";
}
echo "</pre>";
echo "<p style='color:#ff6b6b;font-family:monospace;padding:0 20px'><strong>⚠ DELETE vault_diag.php immediately.</strong></p>";

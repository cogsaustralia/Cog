<?php
/**
 * gen_magic_link.php — Admin CLI tool
 * Generates a one-time magic login link for a named member.
 * Run from server CLI only:
 *   php /home4/cogsaust/public_html/admin/gen_magic_link.php "Pamela Singley"
 *
 * SECURITY: CLI-only. Never expose via HTTP.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$name = trim($argv[1] ?? '');
if ($name === '') {
    exit("Usage: php gen_magic_link.php \"Full Name\"\n");
}

require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/config/database.php';

$db = getDB();

// Look up member by full name (case-insensitive)
$stmt = $db->prepare(
    "SELECT id, member_number, full_name, email, wallet_status
       FROM snft_memberships
      WHERE LOWER(full_name) = LOWER(?)
      LIMIT 5"
);
$stmt->execute([$name]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    exit("No member found with name: {$name}\n");
}
if (count($rows) > 1) {
    echo "Multiple matches — using first result:\n";
    foreach ($rows as $r) {
        echo "  [{$r['member_number']}] {$r['full_name']} <{$r['email']}>\n";
    }
    echo "\n";
}

$row          = $rows[0];
$memberNumber = (string)$row['member_number'];
$memberEmail  = strtolower(trim((string)$row['email']));
$memberName   = (string)$row['full_name'];

echo "Member:  {$memberName}\n";
echo "Number:  {$memberNumber}\n";
echo "Email:   {$memberEmail}\n";
echo "Status:  {$row['wallet_status']}\n\n";

if ($memberEmail === '') {
    exit("No email address on record for this member.\n");
}

// Generate token — same logic as auth.php magic link
$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$purpose   = 'member_login:' . $memberNumber;
$expires   = gmdate('Y-m-d H:i:s', time() + 86400); // 24 hours for admin-generated links

try {
    $db->prepare("DELETE FROM one_time_tokens WHERE purpose = ? AND used_at IS NULL")
       ->execute([$purpose]);
    $db->prepare("INSERT INTO one_time_tokens (token_hash, purpose, expires_at, created_at) VALUES (?,?,?,UTC_TIMESTAMP())")
       ->execute([$tokenHash, $purpose, $expires]);
} catch (Throwable $e) {
    exit("DB error: " . $e->getMessage() . "\n");
}

$link = 'https://cogsaustralia.org/partners/?login_token=' . urlencode($rawToken);

echo "Magic login link (valid 24 hours, single use):\n";
echo $link . "\n\n";
echo "Send to: {$memberEmail}\n";

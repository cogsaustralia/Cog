<?php
/**
 * COGs Australia — GitHub Webhook Deployment Script
 * Place at: /home4/cogsaust/public_html/deploy.php
 * Keep secret — do not share the webhook secret
 */

// ── Configuration ──────────────────────────────────────────────────────────
define('WEBHOOK_SECRET', getenv('COGS_DEPLOY_SECRET') ?: 'mV5xyeP3XC1gNxn4l0HfpVvBuZUTPKxI9qjK2xvg');
define('REPO_PATH',      '/home4/cogsaust/public_html');
define('GIT_BRANCH',     'main');
define('LOG_FILE',       '/home4/cogsaust/deploy.log');
define('ALLOWED_IPS', [
    '192.30.252.0/22',   // GitHub IP ranges
    '185.199.108.0/22',
    '140.82.112.0/20',
    '143.55.64.0/20',
]);

// ── Logging ────────────────────────────────────────────────────────────────
function logMsg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

// ── IP validation ──────────────────────────────────────────────────────────
function ipInRange(string $ip, string $cidr): bool {
    [$subnet, $bits] = explode('/', $cidr);
    $ip     = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask   = -1 << (32 - (int)$bits);
    return ($ip & $mask) === ($subnet & $mask);
}

function isGithubIp(string $ip): bool {
    foreach (ALLOWED_IPS as $cidr) {
        if (ipInRange($ip, $cidr)) return true;
    }
    return false;
}

// ── Main ───────────────────────────────────────────────────────────────────
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$method  = $_SERVER['REQUEST_METHOD'] ?? '';
$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Only allow POST
if ($method !== 'POST') {
    http_response_code(405);
    logMsg("Rejected: not POST from $ip");
    exit;
}

// Verify GitHub signature
$expected = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    logMsg("Rejected: invalid signature from $ip");
    exit;
}

// Parse payload
$data   = json_decode($payload, true);
$branch = $data['ref'] ?? '';
$pusher = $data['pusher']['name'] ?? 'unknown';

// Only deploy on main branch push
if ($branch !== 'refs/heads/' . GIT_BRANCH) {
    http_response_code(200);
    logMsg("Ignored: push to $branch by $pusher");
    echo json_encode(['status' => 'ignored', 'branch' => $branch]);
    exit;
}

// ── Run deployment ─────────────────────────────────────────────────────────
logMsg("Deploying: push by $pusher to $branch");

$cmd = sprintf(
    'cd %s && git pull origin %s 2>&1',
    escapeshellarg(REPO_PATH),
    escapeshellarg(GIT_BRANCH)
);

$output = shell_exec($cmd);
logMsg("Git output: " . trim($output));

http_response_code(200);
echo json_encode([
    'status'  => 'deployed',
    'branch'  => $branch,
    'pusher'  => $pusher,
    'output'  => trim($output),
    'time'    => date('Y-m-d H:i:s'),
]);

logMsg("Deploy complete");

<?php
// admin/_proof/diag.php — pre-auth diagnostic
// Identifies which step fails before the full proof page renders.
// DELETE THIS FILE after the proof page is confirmed working.
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

echo "=== COGS Proof Diagnostic ===\n\n";

// Step 1 — admin_paths.php
echo "1. Loading admin_paths.php ... ";
try {
    require_once dirname(__DIR__) . '/includes/admin_paths.php';
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

// Step 2 — ops_workflow.php
echo "2. Loading ops_workflow.php ... ";
try {
    require_once dirname(__DIR__) . '/includes/ops_workflow.php';
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

// Step 3 — TrusteeDecisionService.php
echo "3. Loading TrusteeDecisionService.php ... ";
try {
    require_once dirname(__DIR__) . '/../_app/api/services/TrusteeDecisionService.php';
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

// Step 4 — DB connection (no auth)
echo "4. DB connection ... ";
try {
    $pdo = ops_db();
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit;
}

// Step 5 — Session status
echo "5. Session status ... ";
echo session_status() === PHP_SESSION_ACTIVE ? "ACTIVE\n" : "NOT STARTED\n";

// Step 6 — Admin session vars (without starting one)
echo "6. Admin session vars ... ";
$hasSession = !empty($_SESSION['admin_user']) && !empty($_SESSION['admin_id']);
echo $hasSession ? "FOUND (admin_id=" . ($_SESSION['admin_id'] ?? 'none') . ")\n" : "NOT FOUND — ops_require_admin() will redirect to login\n";

// Step 7 — Cookie check
echo "7. Cookies present ... ";
echo !empty($_COOKIE) ? implode(', ', array_keys($_COOKIE)) . "\n" : "NONE\n";

echo "\nDiagnostic complete. If Step 6 says NOT FOUND, you need to be\n";
echo "logged into the admin panel first, then visit the proof page.\n";

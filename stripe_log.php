<?php
/**
 * Shows recent PHP error log lines mentioning Stripe or create-checkout.
 * DELETE immediately after viewing.
 * URL: https://cogsaustralia.org/stripe_log.php
 */

// Try common cPanel error log paths
$candidates = [
    ini_get('error_log'),
    '/home4/cogsaust/logs/error_log',
    '/home4/cogsaust/logs/php_errors.log',
    dirname(__DIR__) . '/logs/error_log',
];

$logFile = '';
foreach ($candidates as $c) {
    if ($c && file_exists($c) && is_readable($c)) { $logFile = $c; break; }
}
?><!DOCTYPE html><html><head><title>Stripe log</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:24px}
pre{background:#181108;border:1px solid #333;padding:14px;border-radius:8px;white-space:pre-wrap;word-break:break-all;font-size:.8rem;max-height:600px;overflow-y:auto}
.del{color:#ff6b6b;margin-top:20px}</style></head><body>
<h2>Stripe / checkout error log</h2>
<?php if (!$logFile): ?>
<p style="color:#ff6b6b">Could not find PHP error log. Try checking cPanel → Error Logs.</p>
<?php else: ?>
<p>Log: <code><?= htmlspecialchars($logFile) ?></code></p>
<?php
    // Read last 200 lines, filter for relevant entries
    $lines = file($logFile) ?: [];
    $relevant = array_filter($lines, fn($l) =>
        stripos($l, 'stripe') !== false ||
        stripos($l, 'create-checkout') !== false ||
        stripos($l, 'checkout') !== false
    );
    $relevant = array_slice($relevant, -40); // last 40 matching lines
    if ($relevant): ?>
<pre><?= htmlspecialchars(implode('', $relevant)) ?></pre>
<?php else: ?>
<p style="color:#e8b84b">No Stripe-related entries found in log yet. Trigger a card payment attempt first, then reload this page.</p>
<?php endif; endif; ?>
<p class="del"><strong>⚠ DELETE stripe_log.php immediately after reading.</strong></p>
</body></html>

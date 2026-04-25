<?php
$file = '/home4/cogsaust/public_html/admin/trustee_decisions.php';
$lines = file($file);

// Find the exact line by testing 10-line windows around the failure point
// We know 404 passes, 405 fails — but let's scan the whole file in 50-line chunks
// to find ALL failure points
$failures = [];
for ($n = 50; $n <= count($lines); $n += 50) {
    $tmp = tempnam(sys_get_temp_dir(), 'phpchk');
    file_put_contents($tmp, implode('', array_slice($lines, 0, $n)));
    exec('php -d display_errors=1 -d error_reporting=32767 -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    unlink($tmp);
    if ($rc !== 0) {
        $msg = trim($out[0] ?? 'unknown');
        // Extract line number from error
        preg_match('/on line (\d+)/', $msg, $m);
        $errLine = $m[1] ?? '?';
        echo "Prefix 1-{$n}: FAIL at line {$errLine} | {$msg}\n";
    } else {
        echo "Prefix 1-{$n}: OK\n";
    }
}

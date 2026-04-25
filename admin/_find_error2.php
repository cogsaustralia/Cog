<?php
$file = '/home4/cogsaust/public_html/admin/trustee_decisions.php';
$lines = file($file);

// Test specific ranges with verbose output
foreach ([403, 404, 405, 406, 540, 541, 542, 543, 544, 545] as $n) {
    $tmp = tempnam(sys_get_temp_dir(), 'phpchk');
    file_put_contents($tmp, implode('', array_slice($lines, 0, $n)));
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    unlink($tmp);
    $status = $rc === 0 ? 'OK' : 'FAIL';
    echo "Lines 1-{$n}: {$status}";
    if ($rc !== 0) echo " | " . trim($out[0] ?? '');
    echo "\n";
}

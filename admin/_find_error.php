<?php
$file = '/home4/cogsaust/public_html/admin/trustee_decisions.php';
$lines = file($file);
$total = count($lines);

// Binary search: find the smallest prefix that fails to compile
function check(array $lines, int $n): bool {
    $tmp = tempnam(sys_get_temp_dir(), 'phpchk');
    file_put_contents($tmp, implode('', array_slice($lines, 0, $n)));
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    unlink($tmp);
    return $rc === 0;
}

$lo = 1; $hi = $total;
while ($lo < $hi) {
    $mid = (int)(($lo + $hi) / 2);
    if (check($lines, $mid)) {
        $lo = $mid + 1;
    } else {
        $hi = $mid;
    }
}
echo "First failing line count: $lo\n";
echo "Line $lo content: " . ($lines[$lo-1] ?? '(EOF)');
echo "\nLine " . ($lo-1) . " content: " . ($lines[$lo-2] ?? '(none)');

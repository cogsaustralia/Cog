<?php
$file = '/home4/cogsaust/public_html/admin/trustee_decisions.php';
$all = file($file);

// Test just lines 401-450 as a standalone snippet
$tmp = tempnam(sys_get_temp_dir(), 'phpchk');
$chunk = implode('', array_slice($all, 400, 50));
file_put_contents($tmp, $chunk);
exec('php -d display_errors=1 -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
echo "Lines 401-450 standalone: " . ($rc===0?'OK':'FAIL') . " | " . implode(' ', $out) . "\n";
unlink($tmp);

// Test the full file but with a known-good stub replacing lines 1-241
// to isolate whether the issue is in the HTML/template section
$tmp2 = tempnam(sys_get_temp_dir(), 'phpchk');
$stub = "<?php\n// stub\n?>\n";
$rest = implode('', array_slice($all, 241)); // from line 242 onwards
file_put_contents($tmp2, $stub . $rest);
exec('php -d display_errors=1 -l ' . escapeshellarg($tmp2) . ' 2>&1', $out2, $rc2);
echo "Stub + lines 242-end: " . ($rc2===0?'OK':'FAIL') . " | " . implode(' ', $out2) . "\n";
unlink($tmp2);

// Test: full file but only lines 242-end
$tmp3 = tempnam(sys_get_temp_dir(), 'phpchk');
file_put_contents($tmp3, implode('', array_slice($all, 241)));
exec('php -d display_errors=1 -l ' . escapeshellarg($tmp3) . ' 2>&1', $out3, $rc3);
echo "Lines 242-end standalone: " . ($rc3===0?'OK':'FAIL') . " | " . implode(' ', $out3) . "\n";
unlink($tmp3);

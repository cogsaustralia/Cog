<?php
$f = __DIR__ . '/hubs/_shared/hub.js';
$c = file_get_contents($f);
$lines = explode("\n", $c);
echo "<pre>MD5: " . md5($c) . "\nTotal lines: " . count($lines) . "\n\n";
echo "=== Lines 1275-1290 ===\n";
for ($i = 1274; $i <= 1289 && $i < count($lines); $i++) {
    echo sprintf("L%d: %s\n", $i+1, htmlspecialchars($lines[$i]));
}
echo "\n=== Lines around _INFO var definition ===\n";
foreach ($lines as $n => $l) {
    if (strpos($l, 'var _INFO') !== false || strpos($l, '_INFO =') !== false) {
        echo sprintf("L%d: %s\n", $n+1, htmlspecialchars($l));
    }
}
echo "\n=== hub.js?v= version tag in any hub HTML ===\n";
$h = glob(__DIR__ . '/hubs/*/index.html');
foreach (array_slice($h, 0, 2) as $p) {
    preg_match('/hub\.js\?v=(\d+)/', file_get_contents($p), $m);
    echo basename(dirname($p)) . ': v=' . ($m[1] ?? 'NOT FOUND') . "\n";
}
echo "</pre>";

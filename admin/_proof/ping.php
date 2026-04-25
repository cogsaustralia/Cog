<?php
// admin/_proof/ping.php — minimal diagnostic, no auth, no DB
// Visit this URL to confirm PHP executes in the _proof directory
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo 'PHP OK — version ' . PHP_VERSION . ' — ' . date('Y-m-d H:i:s T') . "\n";
echo 'Script: ' . __FILE__ . "\n";
echo 'Doc root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";

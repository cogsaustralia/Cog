<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$file = '/home4/cogsaust/public_html/admin/trustee_decisions.php';
$tokens = token_get_all(file_get_contents($file));
echo "Token count: " . count($tokens) . "\n";
echo "Parse OK via token_get_all\n";

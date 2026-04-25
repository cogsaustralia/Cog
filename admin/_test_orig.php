<?php
exec('php -l /tmp/orig.php 2>&1', $out, $rc);
echo "Original file: " . ($rc===0?'OK':'FAIL') . "\n";
echo implode("\n", $out) . "\n";

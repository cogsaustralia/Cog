<?php
/**
 * Bumps hub.js?v=12 → hub.js?v=13 and hub.css?v=12 → hub.css?v=13
 * in all hub index.html pages on the server.
 * Upload to public_html, visit once, DELETE immediately.
 * URL: https://cogsaustralia.org/hub_vbump.php
 */
$dir   = __DIR__ . '/hubs';
$files = glob($dir . '/*/index.html');
$done  = []; $skip = [];

foreach ($files as $path) {
    $c  = file_get_contents($path);
    $c2 = str_replace(['hub.js?v=12','hub.css?v=12'], ['hub.js?v=13','hub.css?v=13'], $c);
    if ($c2 !== $c) {
        file_put_contents($path, $c2) !== false ? $done[] : $skip[];
        $done[] = basename(dirname($path));
    } else {
        $skip[] = basename(dirname($path)) . ' (already v=13 or not found)';
    }
}
?><!DOCTYPE html><html><head><title>v bump</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:30px}
.ok{color:#3ecf6e}.warn{color:#e8b84b}</style></head><body>
<h2>hub.js version bump v=12 → v=13</h2>
<p class="ok">Updated (<?= count($done) ?>): <?= implode(', ', $done) ?></p>
<?php if($skip): ?><p class="warn">Skipped: <?= implode(', ', $skip) ?></p><?php endif; ?>
<p class="ok"><strong>Done. Hard-refresh any open hub page to load hub.js?v=13.</strong></p>
<p style="color:#ff6b6b;margin-top:20px"><strong>DELETE hub_vbump.php from public_html now.</strong></p>
</body></html>

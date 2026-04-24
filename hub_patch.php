<?php
/**
 * COG$ hub.js patch — one-shot fix for apostrophe syntax errors in _INFO block.
 * Upload to public_html root, visit once, then DELETE IMMEDIATELY.
 * URL: https://cogsaustralia.org/hub_patch.php
 */

$target = __DIR__ . '/hubs/_shared/hub.js';

if (!file_exists($target)) {
    die('ERROR: hub.js not found at ' . htmlspecialchars($target));
}

$content = file_get_contents($target);
if ($content === false) {
    die('ERROR: could not read hub.js');
}

$original_md5 = md5($content);

// Each replacement: broken single-quoted string → backtick string
// Ordered exactly as they appear in the file
$replacements = [

    // hub-roster-vis
    [
        "    body: '<p>Controls whether your name appears in this hub's member list. Hiding yourself is a global setting \xe2\x80\x94 it applies to all hubs you belong to.</p>'",
        "    body: \`<p>Controls whether your name appears in this hub's member list. Hiding yourself is a global setting \xe2\x80\x94 it applies to all hubs you belong to.</p>\`",
    ],

    // phase-draft
    [
        "    body: '<p>The coordinator is still preparing the proposal. It's visible to participants but not open for community input yet.</p><p>Only the coordinator can advance to the next phase.</p>'",
        "    body: \`<p>The coordinator is still preparing the proposal. It's visible to participants but not open for community input yet.</p><p>Only the coordinator can advance to the next phase.</p>\`",
    ],

    // phase-advance (double-escaped href)
    [
        "    body: '<p>Moves this project to the next governance phase. Only the project coordinator can do this. Each phase has a minimum period \xe2\x80\x94 the button's label shows the next phase name.</p><p><a href=\"../../guide/\" target=\"_blank\">See the full lifecycle guide \xe2\x80\xba</a></p>'",
        "    body: \`<p>Moves this project to the next governance phase. Only the project coordinator can do this. Each phase has a minimum period \xe2\x80\x94 the button's label shows the next phase name.</p><p><a href='../../guide/' target='_blank'>See the full lifecycle guide \xe2\x80\xba</a></p>\`",
    ],

    // vote-block
    [
        "    body: '<p>I have a <strong>paramount objection</strong> based on the Foundation's purpose or governing principles. Requires written reasoning. <strong>A single block re-opens deliberation.</strong></p><p>This is not a personal veto \xe2\x80\x94 it must relate to the Foundation's purpose or rules.</p>'",
        "    body: \`<p>I have a <strong>paramount objection</strong> based on the Foundation's purpose or governing principles. Requires written reasoning. <strong>A single block re-opens deliberation.</strong></p><p>This is not a personal veto \xe2\x80\x94 it must relate to the Foundation's purpose or rules.</p>\`",
    ],

    // milestone-list
    [
        "    body: '<p>Specific deliverables the coordinator has committed to. All members can see progress. Only the coordinator can add or toggle milestones.</p><p>Milestones feed the Foundation's quarterly evidence compilation under the JVPA Schedule.</p>'",
        "    body: \`<p>Specific deliverables the coordinator has committed to. All members can see progress. Only the coordinator can add or toggle milestones.</p><p>Milestones feed the Foundation's quarterly evidence compilation under the JVPA Schedule.</p>\`",
    ],

    // ai-assistant
    [
        "    body: '<p>Answers governance questions about this hub \xe2\x80\x94 how the rules work, what phases mean, and how to interpret the Foundation's governing instruments.</p><p>Powered by Claude. Governance context only \xe2\x80\x94 it does not have access to your wallet or personal data.</p>'",
        "    body: \`<p>Answers governance questions about this hub \xe2\x80\x94 how the rules work, what phases mean, and how to interpret the Foundation's governing instruments.</p><p>Powered by Claude. Governance context only \xe2\x80\x94 it does not have access to your wallet or personal data.</p>\`",
    ],
];

$applied  = 0;
$notfound = 0;

foreach ($replacements as $i => [$old, $new]) {
    $count = substr_count($content, $old);
    if ($count === 1) {
        $content = str_replace($old, $new, $content);
        $applied++;
    } elseif ($count === 0) {
        $notfound++;
    }
}

$new_md5 = md5($content);
$changed  = $original_md5 !== $new_md5;

?><!DOCTYPE html>
<html>
<head><title>hub.js patch</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:30px;max-width:700px}
.ok{color:#3ecf6e} .warn{color:#e8b84b} .err{color:#ff6b6b}
pre{background:#181108;border:1px solid #333;padding:14px;border-radius:8px;white-space:pre-wrap;word-break:break-all}</style>
</head>
<body>
<h2>COG$ hub.js patch</h2>
<p>Target: <code><?= htmlspecialchars($target) ?></code></p>
<p>Original MD5: <code><?= $original_md5 ?></code></p>
<p>Applied: <span class="ok"><?= $applied ?>/<?= count($replacements) ?></span>
   &nbsp; Not found: <span class="<?= $notfound > 0 ? 'warn' : 'ok' ?>"><?= $notfound ?></span></p>

<?php if ($notfound > 0): ?>
<p class="warn">⚠ <?= $notfound ?> replacement(s) not found — the server file may already be patched or may differ from expected.</p>
<?php endif; ?>

<?php if (!$changed): ?>
<p class="warn">⚠ File unchanged — either already patched or strings were not found. Check not-found count above.</p>
<?php elseif (file_put_contents($target, $content) !== false): ?>
<p class="ok">✓ hub.js written successfully. New MD5: <code><?= md5($content) ?></code></p>
<p class="ok"><strong>✓ Done. Hub pages should now load correctly.</strong></p>
<p>Hard-refresh your browser: <strong>Ctrl+Shift+R</strong> (Windows/Linux) or <strong>Cmd+Shift+R</strong> (Mac).</p>
<?php else: ?>
<p class="err">✗ Write failed — check file permissions on hubs/_shared/hub.js</p>
<?php endif; ?>

<p class="err" style="margin-top:24px;font-size:1.1em"><strong>⚠ DELETE this file from public_html immediately after viewing this page.</strong></p>
<p>File to delete: <code>/home4/cogsaust/public_html/hub_patch.php</code></p>
</body>
</html>

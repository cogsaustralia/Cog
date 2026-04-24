<?php
/**
 * Stripe key diagnostic — reads .env safely, never exposes the key value.
 * Upload to public_html, visit once, DELETE immediately.
 * URL: https://cogsaustralia.org/stripe_diag.php
 */
require_once __DIR__ . '/_app/api/config/bootstrap.php';

$key = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';

$status   = '';
$advice   = '';
$keySnip  = '';

if ($key === '') {
    $status  = 'MISSING';
    $advice  = 'STRIPE_SECRET_KEY is not present in .env. Add it as: STRIPE_SECRET_KEY=sk_live_...';
    $colour  = '#ff6b6b';
} elseif (!str_starts_with($key, 'sk_')) {
    $status  = 'INVALID FORMAT';
    $advice  = 'Key does not start with sk_. Must be a Stripe secret key (sk_live_... or sk_test_...).';
    $colour  = '#ff6b6b';
    $keySnip = substr($key, 0, 8) . '…';
} elseif (str_starts_with($key, 'sk_test_')) {
    $status  = 'TEST KEY';
    $advice  = 'Key is a test key (sk_test_...). For live payments, replace with a sk_live_... key from your Stripe dashboard → Developers → API Keys.';
    $colour  = '#e8b84b';
    $keySnip = substr($key, 0, 12) . '…' . substr($key, -4);
} else {
    $status  = 'LIVE KEY — format OK';
    $colour  = '#3ecf6e';
    $keySnip = substr($key, 0, 12) . '…' . substr($key, -4);

    // Do a lightweight Stripe API call to verify the key actually works
    $ch = curl_init('https://api.stripe.com/v1/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $status = 'LIVE KEY — cURL error';
        $advice = 'cURL could not reach Stripe: ' . htmlspecialchars($curlErr) . '. Check server outbound HTTPS.';
        $colour = '#ff6b6b';
    } elseif ($httpCode === 200) {
        $status = 'LIVE KEY — VERIFIED ✓';
        $advice = 'Key is valid and Stripe accepted the test call. Checkout sessions should work.';
        $colour = '#3ecf6e';
    } elseif ($httpCode === 401) {
        $status = 'LIVE KEY — REJECTED (401)';
        $advice = 'Stripe returned 401 Unauthorized. The key may have been rolled/revoked. Generate a new secret key in Stripe Dashboard → Developers → API Keys and update .env.';
        $colour = '#ff6b6b';
    } else {
        $result = json_decode((string)$resp, true);
        $strErr = $result['error']['message'] ?? 'Unknown';
        $status = 'LIVE KEY — Stripe error ' . $httpCode;
        $advice = 'Stripe said: ' . htmlspecialchars($strErr);
        $colour = '#ff6b6b';
    }
}
?><!DOCTYPE html>
<html><head><title>Stripe key diagnostic</title>
<style>
body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:30px;max-width:620px}
.status{font-size:1.3rem;font-weight:700;color:<?= $colour ?>;margin:12px 0}
.advice{background:#181108;border:1px solid #333;border-radius:8px;padding:14px;margin:12px 0;line-height:1.6}
.snip{color:#aaa}
.del{color:#ff6b6b;margin-top:24px;font-size:1.1em}
</style></head><body>
<h2>Stripe key diagnostic</h2>
<p class="status"><?= htmlspecialchars($status) ?></p>
<?php if ($keySnip): ?>
<p>Key preview: <span class="snip"><?= htmlspecialchars($keySnip) ?></span></p>
<?php endif ?>
<div class="advice"><?= htmlspecialchars($advice) ?></div>
<p class="del"><strong>⚠ DELETE stripe_diag.php from public_html immediately after reading this.</strong></p>
</body></html>

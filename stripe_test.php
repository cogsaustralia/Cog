<?php
/**
 * Makes a minimal Stripe Checkout Session request and shows the raw response.
 * DELETE immediately after viewing.
 * URL: https://cogsaustralia.org/stripe_test.php
 */
require_once __DIR__ . '/_app/api/config/bootstrap.php';

$key  = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
$site = defined('SITE_URL') ? SITE_URL : 'https://cogsaustralia.org';

// Minimal checkout session — one $4.00 AUD line item
$postData = [
    'mode'                    => 'payment',
    'success_url'             => $site . '/wallets/member.html?payment=success',
    'cancel_url'              => $site . '/wallets/member.html?payment=cancelled',
    'client_reference_id'     => 'DIAG-TEST',
    'customer_email'          => 'test@cogsaustralia.org',
    'line_items[0][price_data][currency]'                    => 'aud',
    'line_items[0][price_data][unit_amount]'                 => 400,
    'line_items[0][price_data][product_data][name]'          => 'Donation COG$',
    'line_items[0][price_data][product_data][description]'   => 'Test line item',
    'line_items[0][quantity]'                                => 1,
    'payment_intent_data[statement_descriptor]'              => 'COGS AUSTRALIA',
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
    CURLOPT_TIMEOUT        => 12,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$result = json_decode((string)$response, true);
$ok     = $httpCode === 200 && !empty($result['url']);
?><!DOCTYPE html><html><head><title>Stripe test</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:24px;max-width:700px}
.ok{color:#3ecf6e}.err{color:#ff6b6b}.warn{color:#e8b84b}
pre{background:#181108;border:1px solid #333;padding:14px;border-radius:8px;white-space:pre-wrap;word-break:break-all;font-size:.82rem}
.del{color:#ff6b6b;margin-top:20px;font-size:1.1em}</style></head><body>
<h2>Stripe Checkout Session — live test</h2>
<p>HTTP status: <strong class="<?= $ok?'ok':'err' ?>"><?= $httpCode ?></strong>
<?php if ($curlErr): ?>
<p class="err">cURL error: <?= htmlspecialchars($curlErr) ?></p>
<?php elseif ($ok): ?>
<p class="ok">✓ Checkout session created successfully. Stripe is working.</p>
<p>Session URL: <a href="<?= htmlspecialchars($result['url']) ?>" style="color:#e8b84b"><?= htmlspecialchars(substr($result['url'],0,60)) ?>…</a></p>
<?php else: ?>
<p class="err">✗ Stripe returned an error.</p>
<p><strong>Error type:</strong> <?= htmlspecialchars($result['error']['type'] ?? '—') ?></p>
<p><strong>Error code:</strong> <?= htmlspecialchars($result['error']['code'] ?? '—') ?></p>
<p><strong>Error message:</strong> <?= htmlspecialchars($result['error']['message'] ?? '—') ?></p>
<?php endif; ?>
<p>Full Stripe response:</p>
<pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) ?></pre>
<p>POST data sent:</p>
<pre><?= htmlspecialchars(json_encode($postData, JSON_PRETTY_PRINT)) ?></pre>
<p class="del"><strong>⚠ DELETE stripe_test.php from public_html immediately.</strong></p>
</body></html>

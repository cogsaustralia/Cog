<?php
/**
 * Tests the full Stripe checkout payload — mirrors exactly what vault.php sends.
 * DELETE immediately after viewing.
 */
require_once __DIR__ . '/_app/api/config/bootstrap.php';
$key  = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
$site = defined('SITE_URL') ? SITE_URL : 'https://cogsaustralia.org';

function testCall($label, $postData, $key) {
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => 12,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $r = json_decode($resp, true);
    $ok = $code === 200 && !empty($r['url']);
    $errMsg = $r['error']['message'] ?? '';
    $errCode = $r['error']['code'] ?? '';
    $errType = $r['error']['type'] ?? '';
    echo "<tr style='background:" . ($ok ? 'rgba(62,207,110,.08)' : 'rgba(255,80,80,.08)') . "'>";
    echo "<td style='padding:8px 12px;color:" . ($ok ? '#3ecf6e' : '#ff6b6b') . ";font-weight:700'>" . htmlspecialchars($label) . "</td>";
    echo "<td style='padding:8px 12px'>" . ($ok ? '✓ 200 OK' : "✗ HTTP $code") . "</td>";
    echo "<td style='padding:8px 12px;font-size:.82rem'>" . htmlspecialchars($ok ? 'Session created' : "$errType / $errCode: $errMsg") . "</td>";
    echo "</tr>";
}

// Base payload used in all tests
$base = [
    'mode'                    => 'payment',
    'success_url'             => $site . '/wallets/member.html?payment=success',
    'cancel_url'              => $site . '/wallets/member.html?payment=cancelled',
    'client_reference_id'     => 'TEST-001',
    'customer_email'          => 'test@cogsaustralia.org',
    'line_items[0][price_data][currency]'                  => 'aud',
    'line_items[0][price_data][unit_amount]'               => 400,
    'line_items[0][price_data][product_data][name]'        => 'Donation COG$',
    'line_items[0][price_data][product_data][description]' => 'Community project contribution.',
    'line_items[0][quantity]'                              => 1,
];

// Test each extra field in isolation
$withImage = array_merge($base, [
    'line_items[0][price_data][product_data][images][0]' => $site . '/assets/cogs_garden.webp',
]);
$withCustomText = array_merge($base, [
    'custom_text[submit][message]' => 'Your contribution goes directly to the COG$ community trust. Thank you for building something that lasts.',
]);
$withDescription = array_merge($base, [
    'payment_intent_data[description]' => 'COG$ Community Gift Pool — Thomas Cunliffe (COGS-0001)',
]);
$withDescriptor = array_merge($base, [
    'payment_intent_data[statement_descriptor]' => 'COGS AUSTRALIA',
]);
$withMetadata = array_merge($base, [
    'metadata[member_number]' => 'COGS-0001',
    'metadata[member_id]'     => '1',
    'metadata[purchase_type]' => 'gift_pool',
    'metadata[items]'         => 'donation_tokens:1',
]);
$withFee = array_merge($base, [
    'line_items[1][price_data][currency]'                  => 'aud',
    'line_items[1][price_data][unit_amount]'               => 40,
    'line_items[1][price_data][product_data][name]'        => 'Stripe processing fee',
    'line_items[1][price_data][product_data][description]' => 'Card processing fee.',
    'line_items[1][quantity]'                              => 1,
]);
$withAll = array_merge($base, [
    'line_items[0][price_data][product_data][images][0]' => $site . '/assets/cogs_garden.webp',
    'line_items[1][price_data][currency]'                  => 'aud',
    'line_items[1][price_data][unit_amount]'               => 40,
    'line_items[1][price_data][product_data][name]'        => 'Stripe processing fee',
    'line_items[1][price_data][product_data][description]' => 'Card processing fee.',
    'line_items[1][quantity]'                              => 1,
    'custom_text[submit][message]' => 'Your contribution goes directly to the COG$ community trust. Thank you for building something that lasts.',
    'payment_intent_data[description]' => 'COG$ Community Gift Pool — Thomas Cunliffe (COGS-0001)',
    'payment_intent_data[statement_descriptor]' => 'COGS AUSTRALIA',
    'metadata[member_number]' => 'COGS-0001',
    'metadata[member_id]'     => '1',
    'metadata[purchase_type]' => 'gift_pool',
    'metadata[items]'         => 'donation_tokens:1',
]);
?><!DOCTYPE html><html><head><title>Stripe full test</title>
<style>body{font-family:monospace;background:#0f0d09;color:#f0e8d6;padding:24px}
table{width:100%;border-collapse:collapse;margin:12px 0}
th{background:rgba(255,255,255,.06);padding:8px 12px;text-align:left;font-size:.8rem;letter-spacing:.05em}
.del{color:#ff6b6b;margin-top:20px}</style></head><body>
<h2>Stripe full payload tests</h2>
<p style="color:#aaa;font-size:.85rem">Each row adds one field to the base payload — identifies which field Stripe rejects.</p>
<table>
<thead><tr><th>Test</th><th>Result</th><th>Stripe message</th></tr></thead>
<tbody>
<?php
testCall('Base only (minimal)', $base, $key);
testCall('+ Image URL', $withImage, $key);
testCall('+ custom_text[submit][message]', $withCustomText, $key);
testCall('+ payment_intent_data[description]', $withDescription, $key);
testCall('+ statement_descriptor', $withDescriptor, $key);
testCall('+ metadata fields', $withMetadata, $key);
testCall('+ $0.40 fee line item', $withFee, $key);
testCall('ALL fields combined', $withAll, $key);
?>
</tbody></table>
<p class="del"><strong>⚠ DELETE stripe_full_test.php immediately after reading.</strong></p>
</body></html>

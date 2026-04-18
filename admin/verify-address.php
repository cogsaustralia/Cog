<?php
declare(strict_types=1);

/**
 * Admin G-NAF verification trigger
 * Route: POST /api/admin/verify-address
 *
 * Triggers GnafAddressAgent for a specific member.
 * Admin-only. Requires superadmin or governance_admin role.
 *
 * Request body:
 *   { "member_id": 1 }
 *
 * Response:
 *   GnafAddressAgent::verify() result
 *
 * Deploy to: admin/verify-address.php
 */

require_once __DIR__ . '/../_app/api/config/database.php';
require_once __DIR__ . '/../_app/api/helpers.php';
require_once __DIR__ . '/../_app/api/integrations/GnafAddressAgent.php';
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo  = ops_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_once __DIR__ . '/includes/admin_sidebar.php';
    ob_start();
    ?>
    <?php ops_admin_help_assets_once(); ?>
    <?= ops_admin_info_panel('Address · Verification service', 'What this endpoint does', 'This route triggers a G-NAF address verification run for a personal Partner record. It is primarily called by admin pages such as the registry screens rather than used as a manual page on its own.', [
      'Use it to refresh address, parcel, and affected-zone information from the member record.',
      'It expects a member_id and runs as a POST service endpoint.',
      'It does not itself approve, pay, or execute anything.'
    ]) ?>

    <?= ops_admin_workflow_panel('Typical workflow', 'Address verification is a supporting compliance and zoning step.', [
      ['title' => 'Trigger from a registry page', 'body' => 'Run G-NAF verification from the relevant member or business admin surface.'],
      ['title' => 'Review the result', 'body' => 'Check the returned match quality, parcel, and zone information.'],
      ['title' => 'Use the result downstream', 'body' => 'The verification outcome can inform zoning, evidence, and land-related decisions later.']
    ]) ?>

    <?= ops_admin_status_panel('Operator notes', 'This page describes the service endpoint rather than replacing the screens that trigger it.', [
      ['label' => 'POST only for execution', 'body' => 'The actual verification call requires a POST request with member_id.'],
      ['label' => 'Best used from registry screens', 'body' => 'Run it from the member/business pages where the operator already has the record in context.'],
      ['label' => 'Address fields required', 'body' => 'Street, suburb, state, and postcode must already be present on the member record.']
    ]) ?>
    <?php
    $body = ob_get_clean();
    ops_render_page('Address Verification Service', 'zones', $body);
}

requireMethod('POST');
$body = jsonBody();

$memberId = (int)($body['member_id'] ?? 0);
if ($memberId <= 0) {
    apiError('member_id is required.');
}

// Load member record
$stmt = $pdo->prepare(
    'SELECT id, member_number, street_address, suburb, state_code, postcode, full_name
     FROM members WHERE id = ? AND member_type = \'personal\' LIMIT 1'
);
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    apiError('Member not found.', 404);
}

$street   = (string)($member['street_address'] ?? '');
$suburb   = (string)($member['suburb']         ?? '');
$state    = (string)($member['state_code']     ?? '');
$postcode = (string)($member['postcode']       ?? '');

if ($street === '' || $suburb === '' || $state === '' || $postcode === '') {
    apiError('Member is missing address fields required for G-NAF verification.');
}

$agent  = new GnafAddressAgent($pdo);
$result = $agent->mockMode
    ? $agent->verifyMock(
        (int)$member['id'],
        (string)$member['member_number'],
        $street, $suburb, $state, $postcode
      )
    : $agent->verify(
        (int)$member['id'],
        (string)$member['member_number'],
        $street, $suburb, $state, $postcode
      );

// Log admin action
try {
    $adminId = function_exists('ops_current_admin_id') ? ops_current_admin_id($pdo) : null;
    $pdo->prepare(
        "INSERT INTO wallet_events (subject_type, subject_ref, event_type, description, created_at)
         VALUES ('snft_member', ?, 'address_verification_triggered',
                 CONCAT('Admin triggered G-NAF verification. Status: ', ?, '. Zone: ', ?),
                 UTC_TIMESTAMP())"
    )->execute([
        $member['member_number'],
        $result['status']    ?? 'unknown',
        $result['zone_code'] ?? 'none',
    ]);
} catch (Throwable $e) {
    // Non-fatal
}

apiSuccess(array_merge($result, [
    'member_id'     => $memberId,
    'member_number' => $member['member_number'],
    'full_name'     => $member['full_name'],
]));

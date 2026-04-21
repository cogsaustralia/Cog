<?php
declare(strict_types=1);

/**
 * trustee/verify.php
 * Public verification endpoint for the founding Trustee Counterpart Record.
 *
 * GET ?jvpa_sha256={hash}
 * Returns public fields of the matched Trustee Counterpart Record as JSON.
 * No personal fields. No ip_device_data.
 * 404 if no matching record.
 */

require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/config/database.php';
require_once __DIR__ . '/../_app/api/services/TrusteeCounterpartService.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$jvpaSha256 = trim((string)($_GET['jvpa_sha256'] ?? ''));
if ($jvpaSha256 === '' || !preg_match('/^[a-f0-9]{64}$/', $jvpaSha256)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing jvpa_sha256 parameter. Provide a 64-character lowercase hex SHA-256 hash.']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT record_id, trustee_full_name, jvpa_version, jvpa_title,
                jvpa_execution_date, jvpa_sha256, acceptance_timestamp_utc,
                record_sha256, onchain_commitment_txid, capacity_type, created_at
         FROM trustee_counterpart_records
         WHERE jvpa_sha256 = ?
           AND capacity_type = \'founding_caretaker\'
           AND superseded_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$jvpaSha256]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        http_response_code(404);
        echo json_encode(['error' => 'No Trustee Counterpart Record found for the provided JVPA SHA-256 hash.']);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'record_id'                => $record['record_id'],
        'jvpa_version'             => $record['jvpa_version'],
        'jvpa_title'               => $record['jvpa_title'],
        'jvpa_execution_date'      => $record['jvpa_execution_date'],
        'jvpa_sha256'              => $record['jvpa_sha256'],
        'acceptance_timestamp_utc' => $record['acceptance_timestamp_utc'],
        'record_sha256'            => $record['record_sha256'],
        'onchain_commitment_txid'  => $record['onchain_commitment_txid'],
        'capacity_type'            => $record['capacity_type'],
        'note'                     => 'This is the foundational Trustee Counterpart Record of the COGS of Australia Foundation Joint Venture Participation Agreement. Verify by comparing jvpa_sha256 against the SHA-256 of the canonical JVPA PDF and record_sha256 against the on-chain commitment.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error. Please try again.']);
}
exit;

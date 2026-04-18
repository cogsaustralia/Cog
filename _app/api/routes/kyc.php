<?php
declare(strict_types=1);

/**
 * vault/kyc — Medicare KYC submission and status routes
 *
 * GET  vault/kyc/status            → member's current KYC status
 * POST vault/kyc/submit-medicare   → submit Medicare card details
 */

requireMethod($_SERVER['REQUEST_METHOD'] === 'GET' ? 'GET' : 'POST');
$principal = requireAuth('snft');
$db        = getDB();
$body      = $_SERVER['REQUEST_METHOD'] === 'POST' ? jsonBody() : [];

require_once __DIR__ . '/../services/MedicareKycAgent.php';

$subAction = trim((string)($id ?? ''), '/');

// ── GET vault/kyc/status ──────────────────────────────────────────────────────
if ($subAction === 'status' || $_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $db->prepare(
        "SELECT kyc_status, kyc_method, kyc_verified_at, kyc_submission_id
         FROM snft_memberships WHERE id = ? LIMIT 1"
    );
    $stmt->execute([(int)$principal['principal_id']]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get latest submission if exists
    $latest = null;
    if ($member && $member['kyc_submission_id']) {
        $ls = $db->prepare(
            "SELECT id, status, purpose, created_at, verified_at, rejection_reason,
                    medicare_name_initial, medicare_number_last4,
                    medicare_expiry_month, medicare_expiry_year
             FROM kyc_medicare_submissions WHERE id = ? LIMIT 1"
        );
        $ls->execute([(int)$member['kyc_submission_id']]);
        $latest = $ls->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        // Get most recent submission regardless
        $ls = $db->prepare(
            "SELECT id, status, purpose, created_at, verified_at, rejection_reason,
                    medicare_name_initial, medicare_number_last4,
                    medicare_expiry_month, medicare_expiry_year
             FROM kyc_medicare_submissions
             WHERE member_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $ls->execute([(int)$principal['principal_id']]);
        $latest = $ls->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    apiSuccess([
        'kyc_status'      => $member['kyc_status']     ?? 'pending',
        'kyc_method'      => $member['kyc_method']      ?? 'none',
        'kyc_verified_at' => $member['kyc_verified_at'] ?? null,
        'latest_submission' => $latest,
    ]);
}

// ── POST vault/kyc/submit-medicare ────────────────────────────────────────────
if ($subAction === 'submit-medicare') {
    requireMethod('POST');

    $medicareCardName = trim(sanitize((string)($body['medicare_card_name'] ?? '')));
    $medicareNumber   = preg_replace('/\D/', '', (string)($body['medicare_number'] ?? ''));
    $medicareIrn      = trim(sanitize((string)($body['medicare_irn'] ?? '')));
    $medicareExpiry   = trim(sanitize((string)($body['medicare_expiry'] ?? '')));
    $purpose          = trim(sanitize((string)($body['purpose'] ?? 'guardian_ksnft')));
    $kidsRegId        = isset($body['kids_registration_id']) ? (int)$body['kids_registration_id'] : null;
    $declaration      = !empty($body['declaration_accepted']);

    if (!$declaration) {
        apiError('You must accept the privacy declaration before submitting.');
    }
    if ($medicareCardName === '') {
        apiError('Name on Medicare card is required.');
    }
    if (strlen($medicareNumber) !== 10) {
        apiError('Medicare number must be 10 digits.');
    }
    if (!preg_match('/^[1-9]$/', $medicareIrn)) {
        apiError('Individual Reference Number must be 1–9.');
    }
    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $medicareExpiry)) {
        apiError('Expiry must be in MM/YYYY format.');
    }

    // Check card not expired
    [$expMm, $expYy] = explode('/', $medicareExpiry);
    $cardExpiry = mktime(0, 0, 0, (int)$expMm + 1, 1, (int)$expYy);
    if ($cardExpiry < time()) {
        apiError('Your Medicare card has expired. Please use a current card.');
    }

    try {
        $agent = new MedicareKycAgent($db);
        $submissionId = $agent->submit(
            (int)$principal['principal_id'],
            (string)$principal['subject_ref'],
            $medicareCardName,
            $medicareNumber,
            $medicareIrn,
            $medicareExpiry,
            $purpose,
            $kidsRegId,
            (string)($_SERVER['HTTP_CF_CONNECTING_IP']
                ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['REMOTE_ADDR']
                ?? '')
        );

        recordWalletEvent($db, 'snft_member', (string)$principal['subject_ref'],
            'kyc_medicare_submitted',
            "Medicare KYC submitted for review. Submission #{$submissionId}."
        );

        apiSuccess([
            'submission_id' => $submissionId,
            'status'        => 'pending',
            'message'       => 'Your identity details have been submitted for review. '
                . 'Our team will verify them within 1–2 business days.',
        ]);

    } catch (InvalidArgumentException $e) {
        apiError($e->getMessage());
    } catch (Throwable $e) {
        error_log('[vault/kyc] submit failed: ' . $e->getMessage());
        apiError('Could not save your submission. Please try again.');
    }
}

apiError('Unknown KYC action.', 404);

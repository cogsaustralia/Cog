<?php
declare(strict_types=1);
ignore_user_abort(true); // Continue executing after HTTP response is sent — required for inline email queue processing
set_time_limit(60);      // Ensure sufficient time for SMTP send after join completes

requireMethod('POST');
$db = getDB();
$body = jsonBody();

function bnft_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function bnft_get_setting(PDO $db, string $key, ?string $default = null): ?string {
    if (!bnft_table_exists($db, 'admin_settings')) return $default;
    try {
        $stmt = $db->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string)$val;
    } catch (Throwable $e) {
        return $default;
    }
}
function bnft_invite_mode(PDO $db): string {
    $raw = strtolower(trim((string)(bnft_get_setting($db, 'partner_invitation_mode', bnft_get_setting($db, 'invite_program_mode', 'required')) ?? 'required')));
    return match ($raw) {
        'required', 'on_required', 'enforced' => 'required',
        'disabled', 'off', 'inactive' => 'disabled',
        default => 'required',
    };
}
function bnft_validate_partner_invite(PDO $db, string $publicCode, string $entryType = 'business'): array {
    $result = [
        'ok' => false,
        'status' => 'missing',
        'invite_code_id' => null,
        'inviter_partner_id' => null,
        'invite_code_used' => null,
        'verified_at' => null,
    ];
    $publicCode = strtoupper(trim($publicCode));
    if ($publicCode === '') return $result;
    $result['invite_code_used'] = $publicCode;
    if (!bnft_table_exists($db, 'partner_invite_codes')) {
        $result['status'] = 'unavailable';
        return $result;
    }
    try {
        $stmt = $db->prepare('SELECT id, inviter_partner_id, public_code, status, allowed_entry_type, max_uses, use_count, expires_at FROM partner_invite_codes WHERE public_code = ? LIMIT 1');
        $stmt->execute([$publicCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $result['status'] = 'invalid';
            return $result;
        }
        if (($row['status'] ?? '') !== 'active') {
            $result['status'] = (($row['status'] ?? '') === 'revoked') ? 'revoked' : 'expired';
            return $result;
        }
        $allowed = strtolower((string)($row['allowed_entry_type'] ?? 'both'));
        if ($allowed !== 'both' && $allowed !== strtolower($entryType)) {
            $result['status'] = 'wrong_entry_type';
            return $result;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) !== false && strtotime((string)$row['expires_at']) < time()) {
            $result['status'] = 'expired';
            return $result;
        }
        $maxUses = isset($row['max_uses']) ? (int)$row['max_uses'] : 0;
        $useCount = isset($row['use_count']) ? (int)$row['use_count'] : 0;
        if ($maxUses > 0 && $useCount >= $maxUses) {
            $result['status'] = 'expired';
            return $result;
        }
        $result['ok'] = true;
        $result['status'] = 'valid';
        $result['invite_code_id'] = (int)$row['id'];
        $result['inviter_partner_id'] = (int)$row['inviter_partner_id'];
        $result['verified_at'] = gmdate('c');
        return $result;
    } catch (Throwable $e) {
        error_log('[bnft-reserve] invite validation failed: ' . $e->getMessage());
        $result['status'] = 'error';
        return $result;
    }
}

// Link responsible person — prefer active SNFT session, fall back to
// verified member number submitted by the join form.
$responsibleMemberId = null;
try {
    $principal = getAuthPrincipal();
    if ($principal && ($principal['user_type'] ?? '') === 'snft') {
        $responsibleMemberId = (int)$principal['principal_id'];
    }
} catch (Throwable $e) {
    // Not logged in — that's fine, we'll try the submitted member number below
}

// Fallback: form submitted a verified SNFT member number (verified client-side
// via member-mobile-check). Cross-check it server-side against snft_memberships.
if ($responsibleMemberId === null) {
    $submittedMemberNumber = preg_replace('/\D+/', '', (string)($body['snft_member_number'] ?? ''));
    if (strlen($submittedMemberNumber) === 16) {
        $snftRow = $db->prepare('SELECT id FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $snftRow->execute([$submittedMemberNumber]);
        $snftRecord = $snftRow->fetch();
        if ($snftRecord) {
            $responsibleMemberId = (int)$snftRecord['id'];
        } else {
            apiError('The S-NFT partnership could not be verified. Please re-verify using your registered mobile number and try again.');
        }
    } else {
        apiError('A verified personal S-NFT partnership is required. Please complete the mobile verification step on the Responsible Person section.');
    }
}

$legalName = sanitize($body['legal_name'] ?? '');
$tradingName = sanitize($body['trading_name'] ?? '');
$abn = preg_replace('/\D+/', '', (string)($body['abn'] ?? ''));
$entityType = sanitize($body['entity_type'] ?? '');
$contactName = sanitize($body['contact_name'] ?? '');
$positionTitle = sanitize($body['position_title'] ?? '');
$email = strtolower(sanitize($body['email'] ?? ''));
$mobile = sanitize($body['mobile'] ?? '');
$state = sanitize($body['state'] ?? '');
$street = sanitize($body['street'] ?? $body['street_address'] ?? '');
$suburb = sanitize($body['suburb'] ?? '');
$postcode = sanitize($body['postcode'] ?? '');
$industry = sanitize($body['industry'] ?? '');
$website = sanitize($body['website'] ?? '');
$useCase = trim((string)($body['use_case'] ?? ''));
$gnafPid = sanitize($body['gnaf_pid'] ?? '');
$noticeAccepted = !empty($body['reservation_notice_accepted']);
$noticeVersion = sanitize($body['reservation_notice_version'] ?? '');
$noticeAcceptedAtRaw = sanitize($body['reservation_notice_accepted_at'] ?? '');

// ── Partner Invitation Pathway fields ────────────────────────────────────────
$inviteCodeUsed = strtoupper(substr(sanitize($body['invite_code_used'] ?? ''), 0, 60));
$inviteMode = bnft_invite_mode($db);
$inviteState = bnft_validate_partner_invite($db, $inviteCodeUsed, 'business');
$invitedByPartnerId = (int)($inviteState['inviter_partner_id'] ?? 0);
$inviteValidationStatus = (string)($inviteState['status'] ?? 'missing');
$inviteVerifiedAtDb = !empty($inviteState['verified_at'])
    ? gmdate('Y-m-d H:i:s', strtotime((string)$inviteState['verified_at']))
    : null;
if ($inviteMode === 'required' && empty($inviteState['ok'])) {
    apiError('A valid Partner invitation code is required to join the partnership. Please return to the Partner who introduced you or contact administration.');
}
if ($inviteCodeUsed !== '' && empty($inviteState['ok'])) {
    apiError('The Partner invitation code could not be verified. Please check the code and try again.');
}

$reservedTokens = normalizeTokenCount($body['reserved_tokens'] ?? 0, 0, 100000);
$investTokens = normalizeTokenCount($body['invest_tokens'] ?? 0, 0, 100000);
$donationTokens = normalizeTokenCount($body['donation_tokens'] ?? 0, 0, 100000);
$payItForwardTokens = normalizeTokenCount($body['pay_it_forward_tokens'] ?? 0, 0, 100000);
$landholderHectares = max(0, (float)($body['landholder_hectares'] ?? 0));
$landholderTokens = normalizeLandholderTokensForHectares($body['landholder_tokens'] ?? calculateLandholderTokensFromHectares($landholderHectares), $landholderHectares);

if ($legalName === '') apiError('Business legal name is required.');
if (!preg_match('/^\d{11}$/', $abn)) apiError('A valid 11 digit ABN is required.');
if ($entityType === '') apiError('Entity type is required.');
if ($contactName === '') apiError('Contact person is required.');
if ($positionTitle === '') apiError('Position title is required.');
if (!validateEmail($email)) apiError('A valid business email is required.');
if ($mobile === '') apiError('Mobile is required.');
if ($state === '') apiError('State or territory is required.');
if (!$noticeAccepted) apiError('You must accept the beta business reservation notice before continuing.');
if ($noticeVersion === '') apiError('Reservation notice version is required.');

$exists = $db->prepare('SELECT id FROM bnft_memberships WHERE abn = ? OR email = ? LIMIT 1');
$exists->execute([$abn, $email]);
if ($exists->fetch()) {
    apiError('A BNFT reservation already exists for this ABN or email.');
}

$reservationValue = calculateReservationValueFromTokenMix($reservedTokens, $investTokens, $donationTokens, $payItForwardTokens, true, 0, $landholderTokens);
$stewardshipModule = evaluateStewardshipModule((array)($body['stewardship_module'] ?? []), false);
$acceptedAt = $noticeAcceptedAtRaw !== '' ? gmdate('Y-m-d H:i:s', strtotime($noticeAcceptedAtRaw) ?: time()) : nowUtc();

$db->beginTransaction();
try {
    $stmt = $db->prepare('
        INSERT INTO bnft_memberships
        (responsible_member_id, abn, legal_name, trading_name, entity_type, contact_name, position_title, email, mobile, state_code, street_address, suburb, postcode, gnaf_pid, industry, website, use_case, reservation_notice_accepted, reservation_notice_version, reservation_notice_accepted_at, reserved_tokens, invest_tokens, donation_tokens, pay_it_forward_tokens, landholder_hectares, landholder_tokens, reservation_value, wallet_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
        $responsibleMemberId,
        $abn,
        $legalName,
        $tradingName !== '' ? $tradingName : null,
        $entityType,
        $contactName,
        $positionTitle,
        $email,
        $mobile,
        stateCode($state),
        $street !== '' ? $street : null,
        $suburb !== '' ? $suburb : null,
        validatePostcode($postcode) ? $postcode : null,
        $gnafPid !== '' ? $gnafPid : null,
        $industry !== '' ? $industry : null,
        $website !== '' ? $website : null,
        $useCase !== '' ? $useCase : null,
        1,
        $noticeVersion,
        $acceptedAt,
        $reservedTokens,
        $investTokens,
        $donationTokens,
        $payItForwardTokens,
        $landholderHectares,
        $landholderTokens,
        $reservationValue,
        'reserved',
    ]);
    $businessId = (int)$db->lastInsertId();

    api_seed_business_community_cog($db, $businessId, $abn, 10000, 'join_seed');

    $wallet = $db->prepare('
        INSERT INTO vault_wallets
        (wallet_type, subject_type, subject_id, wallet_ref, wallet_status)
        VALUES ("business", "bnft_business", ?, ?, "pending_setup")
        ON DUPLICATE KEY UPDATE
            subject_id    = VALUES(subject_id),
            wallet_status = "pending_setup",
            updated_at    = UTC_TIMESTAMP()
    ');
    $wallet->execute([$businessId, $abn]);

    $db->prepare('UPDATE bnft_memberships SET attestation_hash = ? WHERE id = ?')->execute([$stewardshipModule['attestation_hash'], $businessId]);
    recordStewardshipAttestation($db, 'bnft_business', $businessId, $abn, $stewardshipModule);

    $breakdown = [
        'reserved_tokens' => $reservedTokens,
        'investment_tokens' => $investTokens,
        'donation_tokens' => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'landholder_hectares' => $landholderHectares,
        'landholder_tokens' => $landholderTokens,
        'total_tokens' => totalTokenUnits($reservedTokens, $investTokens, $donationTokens, $payItForwardTokens, 0, $landholderTokens),
    ];

    recordWalletEvent($db, 'bnft_business', $abn, 'reservation_created', 'BNFT beta reservation created at $' . number_format($reservationValue, 2) . '. ' . formatTokenBreakdownNote($breakdown) . '. Fixed BNFT fee included.');
    recordWalletEvent($db, 'bnft_business', $abn, 'community_cog_seeded', 'Opening Community COG$ allocation recorded: 10,000 CC.');
    recordWalletEvent($db, 'bnft_business', $abn, 'stewardship_module_passed', 'Stewardship Awareness Module passed with a full score of ' . (int)$stewardshipModule['score'] . ' / ' . (int)$stewardshipModule['total_questions'] . '.');
    $initialUnits = totalTokenUnits($reservedTokens, $investTokens, $donationTokens, $payItForwardTokens, 0, $landholderTokens);
    recordReservationUpdate($db, 'bnft_business', $abn, 0, $initialUnits, 0.00, $reservationValue, 'Initial reservation from polished BNFT form');
    recordReservationTransaction($db, 'bnft_business', $businessId, $abn, ['total_tokens' => 0], $breakdown, 0.00, $reservationValue, 'Initial reservation from polished BNFT form', 'initial_reservation', 'join_form', 'business', $abn, ['pathway' => 'bnft']);

    queueCrmSync($db, 'bnft_business', $businessId, [
        'abn' => $abn,
        'legal_name' => $legalName,
        'trading_name' => $tradingName,
        'entity_type' => $entityType,
        'contact_name' => $contactName,
        'position_title' => $positionTitle,
        'email' => $email,
        'mobile' => $mobile,
        'state' => stateCode($state),
        'street_address' => $street,
        'suburb' => $suburb,
        'postcode' => validatePostcode($postcode) ? $postcode : '',
        'industry' => $industry,
        'website' => $website,
        'use_case' => $useCase,
        'reserved_tokens' => $reservedTokens,
        'invest_tokens' => $investTokens,
        'donation_tokens' => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'landholder_hectares' => $landholderHectares,
        'landholder_tokens' => $landholderTokens,
        'reservation_value' => $reservationValue,
        'reservation_notice_version' => $noticeVersion,
        'reservation_notice_accepted_at' => $acceptedAt,
        'binding_status' => 'beta_non_binding',
        'stewardship_module_passed' => true,
        'stewardship_module_score' => (int)$stewardshipModule['score'],
        'stewardship_module_total_questions' => (int)$stewardshipModule['total_questions'],
        'stewardship_module_completed_at' => $stewardshipModule['completed_at'],
        'stewardship_attestation_hash' => $stewardshipModule['attestation_hash'],
    ]);

    enqueueReservationEmails($db, 'bnft_business', $businessId, [
        'legal_name' => $legalName,
        'trading_name' => $tradingName,
        'entity_type' => $entityType,
        'contact_name' => $contactName,
        'position_title' => $positionTitle,
        'email' => $email,
        'mobile' => $mobile,
        'state' => stateCode($state),
        'street_address' => $street,
        'suburb' => $suburb,
        'postcode' => validatePostcode($postcode) ? $postcode : '',
        'industry' => $industry,
        'website' => $website,
        'use_case' => $useCase,
        'reservation_notice_version' => $noticeVersion,
        'reservation_notice_accepted_at' => $acceptedAt,
        'abn' => $abn,
        'wallet_path' => 'wallets/business.html',
        'reservation_value' => $reservationValue,
        'reserved_tokens' => $reservedTokens,
        'invest_tokens' => $investTokens,
        'donation_tokens' => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'landholder_hectares' => $landholderHectares,
        'landholder_tokens' => $landholderTokens,
        'trace_line' => 'Trace: bnft_business#' . $businessId . ' | ABN ' . $abn . ' | ' . $acceptedAt,
    ]);

    // ── Partner Invitation Pathway — record invitation event ─────────────────
    if (!empty($inviteState['ok']) && $invitedByPartnerId > 0) {
        try {
            $invCodesExists = bnft_table_exists($db, 'partner_invite_codes');
            $invEvtExists = bnft_table_exists($db, 'partner_invitations');

            if ($invCodesExists && $invEvtExists) {
                $invStmt = $db->prepare(
                    'INSERT INTO partner_invitations
                     (invite_code_id, inviter_partner_id, invitee_email_nullable,
                      invitee_mobile_nullable, accepted_at, accepted_partner_id,
                      entry_type, created_at)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $invStmt->execute([
                    $inviteState['invite_code_id'] ? (int)$inviteState['invite_code_id'] : null,
                    $invitedByPartnerId,
                    $email !== '' ? $email : null,
                    $mobile !== '' ? $mobile : null,
                    $now,
                    $businessId,
                    'business',
                    $now,
                ]);

                if (!empty($inviteState['invite_code_id'])) {
                    $db->prepare(
                        'UPDATE partner_invite_codes
                         SET use_count = use_count + 1, updated_at = NOW()
                         WHERE id = ?'
                    )->execute([(int)$inviteState['invite_code_id']]);
                }
            }
        } catch (Throwable $invEx) {
            error_log('[bnft-reserve] invite linkage failed: ' . $invEx->getMessage());
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

processCrmQueue($db, 1);
processEmailQueue($db, 4);

apiSuccess([
    'member_number' => $abn,
    'abn' => $abn,
    'wallet_path' => 'wallets/business.html',
    'wallet_status' => 'pending_setup',
    'wallet_mode' => 'setup',
    'legal_name' => $legalName,
    'trading_name' => $tradingName,
    'contact_name' => $contactName,
    'email' => $email,
    'mobile' => $mobile,
    'street' => $street,
    'suburb' => $suburb,
    'state' => stateCode($state),
    'postcode' => validatePostcode($postcode) ? $postcode : '',
    'reserved_tokens' => $reservedTokens,
    'invest_tokens' => $investTokens,
    'donation_tokens' => $donationTokens,
    'pay_it_forward_tokens' => $payItForwardTokens,
    'landholder_tokens' => $landholderTokens,
    'community_tokens' => 10000,
    'joining_fee_due_now' => '$' . number_format(BNFT_TOKEN_PRICE, 2),
    'reservation_value' => '$' . number_format($reservationValue, 2),
    'tokens_total' => totalTokenUnits($reservedTokens, $investTokens, $donationTokens, $payItForwardTokens, 0, $landholderTokens),
    'stewardship_module_score' => (int)$stewardshipModule['score'],
    'stewardship_module_total_questions' => (int)$stewardshipModule['total_questions'],
], 201);

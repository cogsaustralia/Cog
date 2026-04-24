<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/TrusteeCounterpartService.php';

$_vaultId = trim((string)($id ?? ''), '/');
// Split action from optional sub-path: 'proposal-comments/4' → action='proposal-comments', subId='4'
$_vaultParts = explode('/', $_vaultId, 2);
$action      = $_vaultParts[0];
$subId       = $_vaultParts[1] ?? null;  // numeric sub-id for endpoints that use it
if ($action === 'member') {
    memberVault();
}
if ($action === 'member-update') {
    memberReservationUpdate();
}
if ($action === 'business') {
    businessVault();
}
if ($action === 'business-update') {
    businessReservationUpdate();
}
if ($action === 'member-transfer') {
    memberP2PTransfer();
}
if ($action === 'business-transfer') {
    businessP2PTransfer();
}
if ($action === 'cast-poll') {
    castPollVote();
}
if ($action === 'payment-intent') {
    createPaymentIntent();
}
if ($action === 'mark-read') {
    markAnnouncementReadVault();
}
if ($action === 'member-business') {
    memberBusinessCheck();
}
if ($action === 'create-checkout') {
    createStripeCheckout();
}
if ($action === 'stewardship-answers') {
    saveVaultStewardshipAnswers();
}
if ($action === 'kids-details') {
    saveKidsDetails();
}
if ($action === 'cancel-gift-order') {
    cancelGiftOrder();
}
if ($action === 'update-email') {
    updateMemberEmail();
}
if ($action === 'change-request') {
    submitChangeRequest();
}
if ($action === 'propose-poll') {
    proposePoll();
}
if ($action === 'join-poll-initiation') {
    joinPollInitiation();
}
if ($action === 'withdraw-poll-initiation') {
    withdrawPollInitiation();
}
if ($action === 'participation') {
    vaultParticipation();
}
if ($action === 'accept-jvpa') {
    acceptJvpa();
}
if ($action === 'kids-order') {
    createKidsOrder();
}
if ($action === 'cancel-kids-order') {
    cancelKidsOrder();
}
if ($action === 'vote-proposal') {
    voteOnProposal();
}
if ($action === 'proposal-comments') {
    handleProposalComments();
}
if ($action === 'proposal-tallies') {
    handleProposalTallies();
}
if ($action === 'partner-op-threads') {
    handlePartnerOpThreads();
}
if ($action === 'partner-op-reply') {
    handlePartnerOpReply();
}
if ($action === 'partner-op-read') {
    handlePartnerOpRead();
}
if ($action === 'notify-bank-payment') {
    notifyBankPayment();
}


/* ═══════════════════════════════════════════════════════════════
   POST vault/notify-bank-payment
   Member signals they have sent a bank transfer or PayID payment.
   Appends a timestamped note to all pending adjustment payments for
   this member. Record stays pending — admin confirms receipt.
   Returns: { notified: N }  (number of records updated)
════════════════════════════════════════════════════════════════ */
function notifyBankPayment(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();

    $mStmt = $db->prepare('SELECT id FROM snft_memberships WHERE id = ? LIMIT 1');
    $mStmt->execute([(int)$principal['principal_id']]);
    if (!$mStmt->fetch()) apiError('Member not found.', 404);

    $memberId = (int)$principal['principal_id'];
    $ts       = gmdate('Y-m-d H:i') . ' UTC';

    // Find all pending adjustment payments (donation + PIF) for this member
    $rows = $db->prepare(
        "SELECT id, notes FROM payments
          WHERE member_id = ?
            AND payment_type = 'adjustment'
            AND payment_status = 'pending'
            AND received_at IS NULL
          ORDER BY id ASC"
    );
    $rows->execute([$memberId]);
    $pending = $rows->fetchAll();

    $updated = 0;
    foreach ($pending as $row) {
        $note = trim((string)($row['notes'] ?? ''));
        // Avoid duplicate notifications
        if (strpos($note, '[Member: payment sent') !== false) continue;
        $newNote = $note . ' [Member: payment sent ' . $ts . ']';
        $db->prepare(
            "UPDATE payments SET notes = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND payment_status = 'pending'"
        )->execute([trim($newNote), (int)$row['id']]);
        $updated++;
    }

    // If nothing was pending (primary $4 only), still succeed — UI handles it
    apiSuccess(['notified' => $updated]);
}

// Management Hubs (v1) — one route module, ten+ endpoints.
// Include here so $action is in scope and hub handlers fire before the 404.
require_once __DIR__ . '/vault-hubs.php';

apiError('Unknown vault endpoint', 404);

function memberVault(): void {
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db = getDB();

    $subjectRef = (string)($principal['subject_ref'] ?? '');
    $legacyStmt = $db->prepare('SELECT * FROM snft_memberships WHERE member_number = ? OR id = ? LIMIT 1');
    $legacyStmt->execute([$subjectRef, (int)$principal['principal_id']]);
    $legacy = $legacyStmt->fetch() ?: null;

    $memberStmt = $db->prepare('SELECT * FROM members WHERE member_type = ? AND (member_number = ? OR id = ?) LIMIT 1');
    $memberStmt->execute(['personal', $subjectRef, (int)$principal['principal_id']]);
    $member = $memberStmt->fetch() ?: null;
    if (!$member && $legacy && !empty($legacy['member_number'])) {
        $memberStmt->execute(['personal', (string)$legacy['member_number'], 0]);
        $member = $memberStmt->fetch() ?: null;
    }
    if (!$member && !$legacy) {
        apiError('Member not found', 404);
    }

    $meta = [];
    if ($member && !empty($member['meta_json'])) {
        $meta = json_decode((string)$member['meta_json'], true) ?: [];
    }

    $memberNumber = (string)($member['member_number'] ?? ($legacy['member_number'] ?? $subjectRef));
    $fullName = (string)($member['full_name'] ?? ($legacy['full_name'] ?? 'Member'));
    $email = (string)($member['email'] ?? ($legacy['email'] ?? ''));
    $mobile = (string)($member['phone'] ?? ($legacy['mobile'] ?? ($meta['mobile'] ?? '')));
    $state = (string)($meta['state'] ?? ($legacy['state_code'] ?? ''));
    $suburb = (string)($meta['suburb'] ?? ($legacy['suburb'] ?? ''));
    $postcode = (string)($meta['postcode'] ?? ($legacy['postcode'] ?? ''));
    $street = (string)($meta['street_address'] ?? ($legacy['street'] ?? ''));
    $walletStatus = (string)($member['wallet_status'] ?? ($legacy['wallet_status'] ?? 'invited'));
    $intentStatus = (string)($legacy['intent_status'] ?? 'proposed');
    $entitlementStatus = (string)($member['stewardship_status'] ?? ($legacy['entitlement_status'] ?? 'inactive'));

    $breakdown = [
        'reserved_tokens' => 0,
        'investment_tokens' => 0,
        'donation_tokens' => 0,
        'pay_it_forward_tokens' => 0,
        'community_tokens' => 0,
        'kids_tokens' => 0,
        'landholder_hectares' => (float)($legacy['landholder_hectares'] ?? 0),
        'landholder_tokens' => 0,
        'rwa_tokens' => 0,
        'lr_tokens' => 0,
        'total_tokens' => 0,
    ];
    $reservationValue = 0.0;
    $approvedTokensTotal = 0;
    $approvedReservationValue = 0.0;

    if ($member) {
        $lineStmt = $db->prepare("
            SELECT tc.class_code, tc.unit_price_cents, mrl.requested_units, mrl.approved_units, mrl.paid_units
            FROM member_reservation_lines mrl
            INNER JOIN token_classes tc ON tc.id = mrl.token_class_id
            WHERE mrl.member_id = ?
        ");
        $lineStmt->execute([(int)$member['id']]);
        foreach ($lineStmt->fetchAll() as $line) {
            $requested = (int)($line['requested_units'] ?? 0);
            $approved = (int)($line['approved_units'] ?? 0);
            $price = ((int)($line['unit_price_cents'] ?? 0)) / 100;
            $classCode = (string)$line['class_code'];
            switch ($classCode) {
                case 'PERSONAL_SNFT': $breakdown['reserved_tokens'] = $requested; break;
                case 'KIDS_SNFT': $breakdown['kids_tokens'] = $requested; break;
                case 'ASX_INVESTMENT_COG': $breakdown['investment_tokens'] = $requested; break;
                case 'DONATION_COG': $breakdown['donation_tokens'] = $requested; break;
                case 'PAY_IT_FORWARD_COG': $breakdown['pay_it_forward_tokens'] = $requested; break;
                case 'LANDHOLDER_COG': $breakdown['landholder_tokens'] = $requested; break;
                case 'RWA_COG': $breakdown['rwa_tokens'] = $requested; break;
                case 'LR_COG': $breakdown['lr_tokens'] = $requested; break;
                case 'COM_COG': $breakdown['community_tokens'] = $requested; break;
            }
            if ($classCode !== 'COM_COG') {
                $breakdown['total_tokens'] += $requested;
                $reservationValue += ($requested * $price);
                $approvedTokensTotal += $approved;
                $approvedReservationValue += ($approved * $price);
            }
        }
    }

    if ($breakdown['total_tokens'] === 0 && $legacy) {
        $breakdown = tokenBreakdownFromRow($legacy, 'snft');
        $reservationValue = (float)($legacy['reservation_value'] ?? 0);
        $approvedTokensTotal = (int)($legacy['approved_tokens_total'] ?? 0);
        $approvedReservationValue = (float)($legacy['approved_reservation_value'] ?? 0);
    }

    $events = fetchWalletEvents($db, 'snft_member', $memberNumber);
    $history = fetchReservationTransactions($db, 'snft_member', $memberNumber);
    $announcements = fetchAnnouncementsForSubject($db, 'snft', $memberNumber);
    $notices = fetchWalletNotices($db, 'personal', (int)($member['id'] ?? 0));
    $votes = fetchProposalsForSubject($db, 'snft', $memberNumber, true);
    $partnersPollInitiations = fetchPollInitiationsForSubject($db, 'snft', $memberNumber, (int)($member['id'] ?? 0), true);
    $walletPolls = fetchWalletPolls($db, 'snft', (int)$principal['principal_id']);

    // ── Payment status ────────────────────────────────────────────────────────
    // Resolve from members first, fall back to snft_memberships.
    // The frontend renderPaymentPill() reads d.payment_status and d.amount_due.
    // Without these fields it defaults to 'pending'/$4 and shows the pill for
    // every member regardless of actual payment state.
    $signupPaymentStatus = (string)($member['signup_payment_status'] ?? ($legacy['signup_payment_status'] ?? 'pending'));
    $snftUnitPriceCents = 400; // default — overwritten from token_classes below
    try {
        $priceRow = $db->prepare("SELECT unit_price_cents FROM token_classes WHERE class_code = 'PERSONAL_SNFT' LIMIT 1");
        $priceRow->execute();
        $pr = $priceRow->fetch();
        if ($pr) $snftUnitPriceCents = (int)$pr['unit_price_cents'];
    } catch (Throwable $ignored) {}
    $amountDue = round($snftUnitPriceCents / 100, 2);

    // ── Gift pool outstanding (Donation + PIF + Kids pending purchases) ───────
    // Only counts adjustment-type payments with no received_at (genuinely unpaid).
    // payment_allocations has NO rows for adjustment/intent payments — they are only
    // allocated after admin confirmation. We parse token class and units from the
    // payment notes field (format: "Member payment intent: N x Display Name. Reference: ...").
    $giftPoolOutstanding = 0.0;
    $giftPoolUnpaidLines = [];
    try {
        $memberId = (int)($member['id'] ?? 0);
        if ($memberId > 0) {
            $gpStmt = $db->prepare(
                "SELECT p.id, p.external_reference, p.amount_cents, p.notes, p.created_at
                   FROM payments p
                  WHERE p.member_id = ?
                    AND p.payment_type = 'adjustment'
                    AND p.payment_status = 'pending'
                    AND p.received_at IS NULL
                  ORDER BY p.id ASC"
            );
            $gpStmt->execute([$memberId]);
            $gpRows = $gpStmt->fetchAll();

            // Map notes display names → frontend field key (Donation and PIF only)
            // Kids S-NFT uses its own pathway: payment_type='kids_snft', read via kids_pending_orders
            $labelToFrontend = [
                'donation cog$'          => 'donation_tokens',
                'donation'               => 'donation_tokens',
                'pay it forward cog$'    => 'pay_it_forward_tokens',
                'pay it forward'         => 'pay_it_forward_tokens',
            ];
            $frontendToLabel = [
                'donation_tokens'       => 'Donation COG$',
                'pay_it_forward_tokens' => 'Pay It Forward COG$',
            ];

            foreach ($gpRows as $gpRow) {
                $pid   = (int)$gpRow['id'];
                $cents = (int)$gpRow['amount_cents'];
                $notes = (string)($gpRow['notes'] ?? '');

                // Parse "Member payment intent: N x Display Name. Reference: COGS-XXXX"
                $units         = 0;
                $frontendClass = '';
                $label         = '';
                if (preg_match('/(\d+)\s*x\s+(.+?)\.\s*Reference:/i', $notes, $nm)) {
                    $units         = (int)$nm[1];
                    $label         = trim($nm[2]);
                    $frontendClass = $labelToFrontend[strtolower($label)] ?? '';
                }

                // Skip if we cannot identify the class (admin manual adjustment etc.)
                if ($frontendClass === '' || $units < 1) continue;

                $giftPoolOutstanding += $cents / 100;
                $pathway = 'community_charity';
                $pathwayLabel = 'Community & Charity';
                $pathwayNoteMap = [
                    'donation_tokens'       => 'Community & Charity pathway — direct community-project support waiting for settlement.',
                    'pay_it_forward_tokens' => 'Community & Charity pathway — sponsors another Partner entry once settled.',
                ];
                $giftPoolUnpaidLines[] = [
                    'payment_id'  => $pid,
                    'reference'   => (string)($gpRow['external_reference'] ?? ''),
                    'token_class' => $frontendClass,
                    'label'       => $frontendToLabel[$frontendClass] ?? $label,
                    'units'       => $units,
                    'price'       => round($cents / $units / 100, 2),
                    'amount'      => round($cents / 100, 2),
                    'created_at'  => (string)($gpRow['created_at'] ?? ''),
                    'pathway'     => $pathway,
                    'pathway_label' => $pathwayLabel,
                    'pathway_note' => $pathwayNoteMap[$frontendClass] ?? 'Pending payment action.',
                ];
            }
            $giftPoolOutstanding = round($giftPoolOutstanding, 2);
        }
    } catch (Throwable $ignored) {}

    // ── Kids registrations ────────────────────────────────────────────────────
    // Frontend reads d.kids_registered to show the "Verify Kids ID" pill.
    // fetchRegisteredKids() reads member_applications (the authoritative source
    // for vault-submitted kids details). Do NOT query kids_token_registrations
    // here — that table is admin-managed and only populated after admin verification.
    $kidsRegistered = [];
    try {
        $memberId = (int)($member['id'] ?? 0);
        if ($memberId > 0) {
            $kidsRegistered = fetchRegisteredKids($db, $memberId);
        }
    } catch (Throwable $ignored) {}

    // ── KYC status ───────────────────────────────────────────────────────────────
    $kycStatus = 'none';
    try {
        $kycStmt = $db->prepare("SELECT kyc_status FROM snft_memberships WHERE id = ? LIMIT 1");
        $kycStmt->execute([(int)$principal['principal_id']]);
        $kycRow = $kycStmt->fetch();
        if ($kycRow && $kycRow['kyc_status']) {
            $kycStatus = (string)$kycRow['kyc_status'];
        }
    } catch (Throwable $ignored) {}

    // ── JVPA acceptance record ────────────────────────────────────────────────
    // Reads from partner_entry_records via partners → members.
    // Returns null fields gracefully if table missing or no record yet.
    // Do not expose accepted_ip or accepted_user_agent — legal retention only.
    $jvpaAcceptance = [
        'accepted_version'   => null,
        'jvpa_title'         => null,
        'accepted_at'        => null,
        'hash_display'       => null,
        'checkbox_confirmed' => false,
        'chain_anchored'     => false,
    ];
    try {
        $jvpaMemberId = (int)($member['id'] ?? 0);
        if ($jvpaMemberId > 0) {
            $jvpaStmt = $db->prepare(
                'SELECT per.accepted_version, per.jvpa_title, per.accepted_at,
                        per.agreement_hash, per.acceptance_record_hash, per.checkbox_confirmed,
                        eve.entry_type AS evidence_entry_type, eve.payload_hash AS evidence_payload_hash,
                        eve.chain_tx_hash
                 FROM   partner_entry_records per
                 INNER  JOIN partners p ON p.id = per.partner_id
                 LEFT   JOIN evidence_vault_entries eve ON eve.id = per.evidence_vault_id
                 WHERE  p.member_id = ?
                   AND  p.partner_kind = ?
                 ORDER BY per.accepted_at DESC, per.id DESC
                 LIMIT  1'
            );
            $jvpaStmt->execute([$jvpaMemberId, 'personal']);
            $jRow = $jvpaStmt->fetch(PDO::FETCH_ASSOC);
            if ($jRow) {
                $rawHash = trim((string)($jRow['acceptance_record_hash'] ?? ''));
                $agreementHash = trim((string)($jRow['agreement_hash'] ?? ''));
                $evidenceType = (string)($jRow['evidence_entry_type'] ?? '');
                $evidenceHash = trim((string)($jRow['evidence_payload_hash'] ?? ''));
                $checkboxConfirmed = (bool)($jRow['checkbox_confirmed'] ?? false);
                $hasAgreementHash = $agreementHash !== '' && $agreementHash !== 'REPLACE_WITH_SHA256_BEFORE_DEPLOY';
                $evidenceRecorded = ($evidenceType === 'jvpa_accepted');
                $hashMatchesEvidence = ($rawHash !== '' && $evidenceHash !== '' && hash_equals($rawHash, $evidenceHash));
                $verified = $checkboxConfirmed
                    && !empty($jRow['accepted_version'])
                    && !empty($jRow['jvpa_title'])
                    && !empty($jRow['accepted_at'])
                    && $hasAgreementHash
                    && $evidenceRecorded
                    && $hashMatchesEvidence;

                $jvpaAcceptance = [
                    'accepted_version'       => $jRow['accepted_version'],
                    'jvpa_title'             => $jRow['jvpa_title'],
                    'accepted_at'            => $jRow['accepted_at'],
                    'hash_display'           => $rawHash !== ''
                        ? substr($rawHash, 0, 16) . '…'
                        : null,
                    'checkbox_confirmed'     => $checkboxConfirmed,
                    'agreement_hash_present' => $hasAgreementHash,
                    'evidence_recorded'      => $evidenceRecorded,
                    'evidence_hash_matches'  => $hashMatchesEvidence,
                    'verified'               => $verified,
                    'chain_anchored'         => !empty($jRow['chain_tx_hash']),
                ];
            }
        }
    } catch (Throwable $ignored) {}

    // ── Partner invite code ───────────────────────────────────────────────────
    $inviteCode = null;
    try {
        $inviteMemberId = (int)($member['id'] ?? 0);
        if ($inviteMemberId > 0 && api_table_exists($db, 'partner_invite_codes') && api_table_exists($db, 'partners')) {
            $icStmt = $db->prepare(
                'SELECT pic.public_code, pic.use_count, pic.max_uses, pic.allowed_entry_type
                 FROM partner_invite_codes pic
                 INNER JOIN partners p ON p.id = pic.inviter_partner_id
                 WHERE p.member_id = ? AND p.partner_kind = ? AND pic.status = ?
                 ORDER BY pic.id ASC LIMIT 1'
            );
            $icStmt->execute([$inviteMemberId, 'personal', 'active']);
            $icRow = $icStmt->fetch(PDO::FETCH_ASSOC);
            if ($icRow) {
                $maxUses = isset($icRow['max_uses']) && $icRow['max_uses'] !== null ? (int)$icRow['max_uses'] : null;
                $useCount = (int)($icRow['use_count'] ?? 0);
                $inviteCode = [
                    'has_code' => true,
                    'public_code' => (string)$icRow['public_code'],
                    'code'        => (string)$icRow['public_code'],  // JS alias
                    'use_count' => $useCount,
                    'max_uses' => $maxUses,
                    'uses_remaining' => $maxUses !== null ? max(0, $maxUses - $useCount) : null,
                    'allowed_entry_type' => (string)($icRow['allowed_entry_type'] ?? 'both'),
                    'invite_link' => 'https://cogsaustralia.org/?invite=' . rawurlencode((string)$icRow['public_code']),
                ];
            }
        }
    } catch (Throwable $ignored) {}

    $pathwaySummary = [
        'class_s_family' => [
            'count' => (int)$breakdown['reserved_tokens'] + (int)$breakdown['kids_tokens'],
            'value' => round(((int)$breakdown['reserved_tokens'] * 4.00) + ((int)$breakdown['kids_tokens'] * 1.00), 2),
            'status' => $signupPaymentStatus === 'paid' ? 'secured' : 'payment_required',
        ],
        'reservations_only' => [
            'count' => (float)$breakdown['investment_tokens'] + (float)$breakdown['rwa_tokens'] + (float)$breakdown['landholder_tokens'] + (float)($breakdown['bus_prop_tokens'] ?? 0),
            'value' => round(((float)$breakdown['investment_tokens'] * 4.00) + ((float)$breakdown['rwa_tokens'] * 4.00) + ((float)($breakdown['bus_prop_tokens'] ?? 0) * 40.00), 4),
            'status' => 'locked_until_expansion_day',
        ],
        'community_charity' => [
            'count' => (int)$breakdown['donation_tokens'] + (int)$breakdown['pay_it_forward_tokens'],
            'value' => round(((int)$breakdown['donation_tokens'] * 4.00) + ((int)$breakdown['pay_it_forward_tokens'] * 4.00), 2),
            'status' => 'community_charity_pathway',
        ],
        'community_exchange' => [
            'count' => (int)($breakdown['community_tokens'] ?? 0),
            'value' => 0.0,
            'status' => 'transferable_now',
        ],
    ];

    $pathwayControls = [
        'class_s_family' => [
            'title' => 'Class S Unit & Family',
            'note' => 'Personal S-NFT remains fixed. Kids S-NFT is handled here through the legacy family pathway.',
            'editable_classes' => ['kids_tokens'],
            'transferable_classes' => [],
            'requires_kyc_for_children' => true,
        ],
        'reservations_only' => [
            'title' => 'Reservations — no obligation',
            'note' => 'ASX, RWA, Landholder, and Business Property reservations can be adjusted here. They are intentions only and remain locked until Expansion Day.',
            'editable_classes' => ['investment_tokens', 'rwa_tokens', 'landholder_tokens', 'bus_prop_tokens'],
            'transferable_classes' => [],
            'locked_classes' => ['investment_tokens', 'rwa_tokens', 'landholder_tokens', 'bus_prop_tokens'],
        ],
        'community_charity' => [
            'title' => 'Community & Charity',
            'note' => 'Donation and Pay It Forward are separate community pathways. They are not part of no-obligation reservations.',
            'editable_classes' => ['donation_tokens', 'pay_it_forward_tokens'],
            'transferable_classes' => [],
        ],
        'community_exchange' => [
            'title' => 'Community COG$ exchange',
            'note' => 'Community COG$ is the only live P2P transfer class in the wallet before Expansion Day.',
            'editable_classes' => [],
            'transferable_classes' => ['community_tokens'],
        ],
    ];

    $transferRules = [
        'community_tokens'      => 'transferable_now',
        'investment_tokens'     => 'locked_until_expansion_day',
        'rwa_tokens'            => 'locked_until_expansion_day',
        'landholder_tokens'     => 'locked_until_expansion_day',
        'bus_prop_tokens'       => 'locked_until_expansion_day',
        'kids_tokens'           => 'family_pathway_only',
        'donation_tokens'       => 'community_charity_only',
        'pay_it_forward_tokens' => 'community_charity_only',
        'lr_tokens'             => 'address_bound_non_transferable',
    ];

    $foundationAssets = function_exists('api_fetch_foundation_live_assets')
        ? api_fetch_foundation_live_assets($db)
        : ['asx_holdings' => [], 'rwa_holdings' => [], 'community_cogs_minted' => 0, 'community_cogs_circulation' => 0];

    // ── Trustee Counterpart Record — per JVPA cl.8.1A(b)(i) ──────────────────
    // Members are shown the founding TCR hashes alongside the JVPA so they can
    // verify the document has not been altered (cl.8.1A(b)(i)).
    // No personal fields from the TCR are exposed here — only the two hashes.
    $tcrJvpaSha256   = null;
    $tcrRecordSha256 = null;
    try {
        $tcrRow = TrusteeCounterpartService::getFoundingRecord($db);
        if ($tcrRow) {
            $tcrJvpaSha256   = $tcrRow['jvpa_sha256'];
            $tcrRecordSha256 = $tcrRow['record_sha256'];
        }
    } catch (Throwable $ignored) {}

    apiSuccess([
        'member_number' => $memberNumber,
        'support_code' => generateWalletSupportCode('snft', $memberNumber, $email),
        'support_code_display' => formatWalletSupportCode(generateWalletSupportCode('snft', $memberNumber, $email)),
        'support_reference' => 'SNFT support check',
        'full_name' => $fullName,
        'state' => $state,
        'tokens_total' => (int)$breakdown['total_tokens'],
        'reserved_tokens' => (int)$breakdown['reserved_tokens'],
        'investment_tokens' => (float)$breakdown['investment_tokens'],
        'donation_tokens' => (int)$breakdown['donation_tokens'],
        'pay_it_forward_tokens' => (int)$breakdown['pay_it_forward_tokens'],
        'community_tokens' => (float)($breakdown['community_tokens'] ?? 0),
        'kids_tokens' => (int)$breakdown['kids_tokens'],
        'landholder_hectares' => (float)$breakdown['landholder_hectares'],
        'landholder_tokens' => (float)$breakdown['landholder_tokens'],
        'rwa_tokens' => (float)$breakdown['rwa_tokens'],
        'bus_prop_tokens' => (float)($breakdown['bus_prop_tokens'] ?? 0),
        'lr_tokens' => (int)$breakdown['lr_tokens'],
        'token_breakdown' => $breakdown,
        'pathway_summary' => $pathwaySummary,
        'pathway_controls' => $pathwayControls,
        'transfer_rules' => $transferRules,
        'community_transfer_balance' => (int)($breakdown['community_tokens'] ?? 0),
        'transfer_help' => [
            'title' => 'Community COG$ exchange',
            'note' => 'Only Community COG$ can move Partner-to-Partner before Expansion Day.',
            'locked_note' => 'ASX COG$, RWA COG$, and Landholder COG$ remain locked until after Expansion Day.',
        ],
        'street' => $street,
        'suburb' => $suburb,
        'postcode' => $postcode,
        'email' => $email,
        'mobile' => $mobile,
        'beta_tokens_total' => calculateReservedClassTokenTotal((int)$breakdown['investment_tokens'], (int)$breakdown['donation_tokens'], (int)$breakdown['pay_it_forward_tokens'], (int)$breakdown['landholder_tokens']),
        'reservation_value' => round($reservationValue, 2),
        'approved_tokens_total' => $approvedTokensTotal,
        'approved_reservation_value' => round($approvedReservationValue, 2),
        'wallet_status' => $walletStatus,
        'payment_status' => $signupPaymentStatus,
        'amount_due' => $amountDue,
        'gift_pool_outstanding' => $giftPoolOutstanding,
        'gift_pool_unpaid_lines' => $giftPoolUnpaidLines,
        'kids_pending_orders'   => fetchKidsPendingOrders($db, (int)($member['id'] ?? 0), (int)($legacy['id'] ?? 0)),
        'kids_registered' => $kidsRegistered,
        'binding_status' => 'joining_fee_now_other_classes_reserved',
        'intent_status' => $intentStatus,
        'entitlement_status' => $entitlementStatus,
        'events' => $events,
        'reservation_history' => $history,
        'announcements' => $announcements,
        'announcement_unread' => count(array_filter($announcements, static fn(array $a): bool => !$a['is_read'])),
        'notices' => $notices,
        'notices_unread' => count(array_filter($notices, static fn(array $n): bool => !$n['is_read'])),
        // ── Foundation Holdings (ASX + RWA + Community totals) ──
        'asx_holdings' => $foundationAssets['asx_holdings'],
        'rwa_holdings' => $foundationAssets['rwa_holdings'],
        'community_cogs_minted' => $foundationAssets['community_cogs_minted'],
        'community_cogs_circulation' => $foundationAssets['community_cogs_circulation'],
        'legacy_consultations' => $votes,
        'proposals' => $votes,
        'open_proposals' => count(array_filter($votes, static fn(array $p): bool => $p['status'] === 'open')),
        'poll_initiations' => $partnersPollInitiations,
        'open_poll_initiations' => count(array_filter($partnersPollInitiations, static fn(array $p): bool => in_array((string)($p['status'] ?? ''), ['submitted','sponsored'], true))),
        'polls' => $walletPolls,
        'open_polls' => count(array_filter($walletPolls, static fn(array $p): bool => in_array((string)($p['status'] ?? ''), ['scheduled','open'], true))),
        'pending_transfers' => fetchPendingTransfers($db, 'snft_member', $memberNumber),
        'wallet_addresses' => getOrCreateWalletAddresses($db, $memberNumber, (int)($member['id'] ?? 0)),
        'kyc_status' => $kycStatus,
        'stewardship_status' => (string)($member['stewardship_status'] ?? ''),
        'zone_id' => isset($member['zone_id']) ? (int)$member['zone_id'] : null,
        'address_verified_at' => $member['address_verified_at'] ?? null,
        'jvpa_acceptance'           => (function() use ($jvpaAcceptance, $db): array {
            // Enrich with current version info so the UI can detect version mismatches
            $current = currentJvpaVersionRecord($db);
            $currentLabel = $current ? (string)$current['version_label'] : null;
            // Display title: use DB value but fix acronym — never auto-lowercase COGS
            $rawTitle = $current ? (string)$current['version_title'] : '';
            if ($rawTitle !== '') {
                // Convert ALL-CAPS DB title to mixed case, preserving COGS acronym
                $words = explode(' ', $rawTitle);
                $minor = ['of', 'the', 'and', 'in', 'on', 'at', 'to', 'a', 'an', 'for', 'nor', 'but', 'or', 'yet', 'so'];
                $displayTitle = implode(' ', array_map(function($word, $idx) use ($minor) {
                    $lower = strtolower($word);
                    // Always capitalise first and last word; keep minor words lowercase mid-title
                    if ($idx === 0 || !in_array($lower, $minor, true)) {
                        return ucfirst($lower);
                    }
                    return $lower;
                }, $words, array_keys($words)));
                // Restore COGS acronym (ucfirst gives 'Cogs' — fix back)
                $displayTitle = preg_replace('/\bCogs\b/', 'COG$', $displayTitle);
            } else {
                $displayTitle = 'COG$ of Australia Foundation Joint Venture Participation Agreement';
            }
            $acceptedVersion = $jvpaAcceptance['accepted_version'] ?? null;
            $isVerified = !empty($jvpaAcceptance['verified']);
            $needsSigning = !$isVerified
                || $acceptedVersion === null
                || ($currentLabel !== null && $acceptedVersion !== $currentLabel);
            return array_merge($jvpaAcceptance, [
                'current_version'       => $currentLabel,
                'current_version_title' => $displayTitle,
                'needs_signing'         => $needsSigning,
            ]);
        })(),
        'tcr_jvpa_sha256'           => $tcrJvpaSha256,
        'tcr_record_sha256'         => $tcrRecordSha256,
        'invite_code'               => $inviteCode,
        'participation_completed'   => !empty($member['participation_completed']) ? (bool)$member['participation_completed'] : false,
        'participation_areas'       => !empty($member['participation_answers'])
            ? (is_string($member['participation_answers'])
                ? (json_decode($member['participation_answers'], true) ?: [])
                : (array)$member['participation_answers'])
            : [],
        'op_thread_summary'  => fetchOpThreadSummary($db,
            (int)($member['id'] ?? 0),
            !empty($member['participation_answers'])
                ? (is_string($member['participation_answers'])
                    ? (json_decode($member['participation_answers'], true) ?: [])
                    : (array)$member['participation_answers'])
                : []),
        'refreshed_at' => nowUtc(),
    ]);
}

function acceptJvpa(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();

    // Require confirmation that the member has viewed the document
    $body = jsonBody();
    if (empty($body['viewed_document'])) {
        apiError('You must open and review the agreement before recording your acceptance.');
    }

    if (!api_table_exists($db, 'partners') || !api_table_exists($db, 'partner_entry_records')) {
        apiError('JVPA intake tables are not available on this environment.', 500);
    }

    $subjectRef = (string)($principal['subject_ref'] ?? '');
    $memberStmt = $db->prepare('SELECT id, member_number, full_name, email FROM members WHERE member_type = ? AND (member_number = ? OR id = ?) LIMIT 1');
    $memberStmt->execute(['personal', $subjectRef, (int)$principal['principal_id']]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        $legacyStmt = $db->prepare('SELECT member_number, full_name, email FROM snft_memberships WHERE member_number = ? OR id = ? LIMIT 1');
        $legacyStmt->execute([$subjectRef, (int)$principal['principal_id']]);
        $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$legacy || empty($legacy['member_number'])) {
            apiError('Member not found.', 404);
        }
        $memberStmt->execute(['personal', (string)$legacy['member_number'], 0]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) apiError('Member not found.', 404);
    }

    $partnerStmt = $db->prepare('SELECT id, partner_number FROM partners WHERE member_id = ? AND partner_kind = ? LIMIT 1');
    $partnerStmt->execute([(int)$member['id'], 'personal']);
    $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) apiError('Partner record not found.', 404);

    $version = currentJvpaVersionRecord($db);
    if (!$version) apiError('Current JVPA version is not configured.', 500);

    $acceptedAt = gmdate('Y-m-d H:i:s');
    $acceptedIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $acceptedUa = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $agreementHash = (string)$version['agreement_hash'];
    $acceptedVersion = (string)$version['version_label'];
    $jvpaTitle = (string)$version['version_title'];
    $acceptanceHash = hash('sha256', implode('|', [
        'partner',
        (string)$partner['id'],
        (string)$partner['partner_number'],
        $acceptedVersion,
        $agreementHash,
        $acceptedAt,
    ]));

    $db->beginTransaction();
    try {
        $entryId = null;
        $entryStmt = $db->prepare('SELECT id FROM partner_entry_records WHERE partner_id = ? ORDER BY id DESC LIMIT 1');
        $entryStmt->execute([(int)$partner['id']]);
        $entryId = (int)($entryStmt->fetchColumn() ?: 0);

        $entryCols = tableColumns($db, 'partner_entry_records');
        $has = static function(string $col) use ($entryCols): bool { return isset($entryCols[$col]); };

        if ($entryId > 0) {
            $sets = [];
            $vals = [];
            $maybe = [
                'entry_channel' => 'wallet_completion',
                'entry_label_public' => 'partnership contribution',
                'entry_label_internal' => 'membership fee',
                'accepted_version' => $acceptedVersion,
                'accepted_at' => $acceptedAt,
                'accepted_ip' => $acceptedIp,
                'accepted_user_agent' => $acceptedUa,
                'jvpa_title' => $jvpaTitle,
                'agreement_hash' => $agreementHash,
                'acceptance_record_hash' => $acceptanceHash,
                'checkbox_confirmed' => 1,
                'updated_at' => $acceptedAt,
            ];
            foreach ($maybe as $col => $val) {
                if ($has($col)) { $sets[] = "`{$col}` = ?"; $vals[] = $val; }
            }
            if ($sets) {
                $vals[] = $entryId;
                $db->prepare('UPDATE partner_entry_records SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            }
        } else {
            $cols = ['partner_id'];
            $place = ['?'];
            $vals = [(int)$partner['id']];
            $maybe = [
                'entry_channel' => 'wallet_completion',
                'entry_label_public' => 'partnership contribution',
                'entry_label_internal' => 'membership fee',
                'accepted_version' => $acceptedVersion,
                'accepted_at' => $acceptedAt,
                'accepted_ip' => $acceptedIp,
                'accepted_user_agent' => $acceptedUa,
                'jvpa_title' => $jvpaTitle,
                'agreement_hash' => $agreementHash,
                'acceptance_record_hash' => $acceptanceHash,
                'checkbox_confirmed' => 1,
                'created_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
            ];
            foreach ($maybe as $col => $val) {
                if ($has($col)) { $cols[] = $col; $place[] = '?'; $vals[] = $val; }
            }
            $db->prepare('INSERT INTO partner_entry_records (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $place) . ')')->execute($vals);
            $entryId = (int)$db->lastInsertId();
        }

        $evidenceId = null;
        if (api_table_exists($db, 'evidence_vault_entries')) {
            $eCols = tableColumns($db, 'evidence_vault_entries');
            $cols = ['entry_type', 'subject_type', 'subject_id', 'subject_ref', 'payload_hash'];
            $place = ['?', '?', '?', '?', '?'];
            $vals = ['jvpa_accepted', 'partner', (int)$partner['id'], (string)$partner['partner_number'], $acceptanceHash];
            $maybe = [
                'payload_summary' => 'JVPA acceptance recorded for ' . $acceptedVersion,
                'source_system' => 'vault_accept_jvpa',
                'created_by_type' => 'system',
                'created_at' => $acceptedAt,
            ];
            foreach ($maybe as $col => $val) {
                if (isset($eCols[$col])) { $cols[] = $col; $place[] = '?'; $vals[] = $val; }
            }
            $db->prepare('INSERT INTO evidence_vault_entries (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $place) . ')')->execute($vals);
            $evidenceId = (int)$db->lastInsertId();
        }

        if ($entryId > 0 && $evidenceId > 0 && $has('evidence_vault_id')) {
            $db->prepare('UPDATE partner_entry_records SET evidence_vault_id = ?, updated_at = ? WHERE id = ?')->execute([$evidenceId, $acceptedAt, $entryId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    apiSuccess([
        'recorded' => true,
        'accepted_version' => $acceptedVersion,
        'jvpa_title' => $jvpaTitle,
        'accepted_at' => $acceptedAt,
        'hash_display' => substr($acceptanceHash, 0, 16) . '…',
    ]);
}

function currentJvpaVersionRecord(PDO $db): ?array {
    if (api_table_exists($db, 'jvpa_versions')) {
        $cols = tableColumns($db, 'jvpa_versions');
        $select = [];
        foreach (['version_label','version_title','agreement_hash','is_current','id'] as $col) {
            if (isset($cols[$col])) $select[] = $col;
        }
        if ($select) {
            $whereCurrent = isset($cols['is_current']) ? 'WHERE is_current = 1' : '';
            $order = isset($cols['id']) ? 'ORDER BY id DESC' : '';
            $stmt = $db->query('SELECT ' . implode(',', $select) . ' FROM jvpa_versions ' . $whereCurrent . ' ' . $order . ' LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($row && !empty($row['agreement_hash']) && $row['agreement_hash'] !== 'REPLACE_WITH_SHA256_BEFORE_DEPLOY') {
                return [
                    'version_label' => (string)($row['version_label'] ?? 'v1.0'),
                    'version_title' => (string)($row['version_title'] ?? 'Joint Venture Partnership Agreement Version 1.0'),
                    'agreement_hash' => (string)$row['agreement_hash'],
                ];
            }
        }
    }
    return null;
}

function tableColumns(PDO $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cache[$table] = [];
    try {
        $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $cache[$table][(string)$row['Field']] = true;
        }
    } catch (Throwable $ignored) {}
    return $cache[$table];
}

function memberReservationUpdate(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $note = sanitize($body['note'] ?? '');

    // Look up token prices from the authoritative token_classes table (prices in cents).
    // This matches the join form calculation and is independent of the TOKEN_PRICE env var.
    $priceStmt = $db->query(
        "SELECT class_code, unit_price_cents FROM token_classes WHERE is_active = 1"
    );
    $classPrices = [];
    foreach ($priceStmt->fetchAll() as $pc) {
        $classPrices[(string)$pc['class_code']] = (float)$pc['unit_price_cents'] / 100;
    }
    $priceToken = $classPrices['ASX_INVESTMENT_COG']  ?? ($classPrices['PERSONAL_SNFT'] ?? 4.00);
    $priceSnft  = $classPrices['PERSONAL_SNFT']       ?? 4.00;
    $priceKids  = $classPrices['KIDS_SNFT']            ?? 1.00;
    $priceLh    = $classPrices['LANDHOLDER_COG']       ?? 4.00;
    $priceRwa   = $classPrices['RWA_COG']              ?? 4.00;
    $priceLr    = $classPrices['LR_COG']               ?? 0.00;  // LR tokens are free
    $pricePif   = $classPrices['PAY_IT_FORWARD_COG']   ?? 4.00;
    $priceDon   = $classPrices['DONATION_COG']         ?? 4.00;

    $stmt = $db->prepare('SELECT id, member_number, full_name, email, mobile, state_code, suburb, postcode, reserved_tokens, investment_tokens, donation_tokens, pay_it_forward_tokens, kids_tokens, landholder_hectares, landholder_tokens, rwa_tokens, lr_tokens, tokens_total, reservation_value, approved_tokens_total, approved_reservation_value, intent_status, entitlement_status FROM snft_memberships WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$principal['principal_id']]);
    $current = $stmt->fetch();
    if (!$current) {
        apiError('Member not found.', 404);
    }

    $reservationKeys = ['investment_tokens', 'rwa_tokens', 'landholder_tokens', 'landholder_hectares', 'hectares'];
    $forbiddenReservationKeys = ['reserved_tokens', 'donation_tokens', 'pay_it_forward_tokens', 'kids_tokens', 'lr_tokens', 'community_tokens'];
    $hasTokenFields = false;
    foreach (array_merge($reservationKeys, $forbiddenReservationKeys) as $checkKey) {
        if (array_key_exists($checkKey, $body)) {
            $hasTokenFields = true;
            break;
        }
    }
    foreach ($forbiddenReservationKeys as $forbiddenKey) {
        if (array_key_exists($forbiddenKey, $body)) {
            apiError('This wallet update route now accepts reservation-only changes for ASX COG$, RWA COG$, and Landholder COG$ only. Kids S-NFT uses the family pathway. Donation and Pay It Forward use the community charity pathway.');
        }
    }
    if ($hasTokenFields) {
        $reservedTokens = 1;
        $investmentTokens = normalizeTokenCount($body['investment_tokens'] ?? $current['investment_tokens'], 0, 1000000);
        $donationTokens = (int)($current['donation_tokens'] ?? 0);
        $payItForwardTokens = (int)($current['pay_it_forward_tokens'] ?? 0);
        $kidsTokens = (int)($current['kids_tokens'] ?? 0);
        $landholderHectares = max(0, (float)($body['landholder_hectares'] ?? $body['hectares'] ?? $current['landholder_hectares']));
        $landholderTokens = normalizeLandholderTokensForHectares($body['landholder_tokens'] ?? $current['landholder_tokens'] ?? calculateLandholderTokensFromHectares($landholderHectares), $landholderHectares);
        $rwaTokens = normalizeTokenCount($body['rwa_tokens'] ?? $current['rwa_tokens'], 0, 1000000);
        $lrTokens  = (int)($current['lr_tokens']  ?? 0);
    } else {
        $reservationValue = parseMoneyAmount($body['reservation_value'] ?? '', TOKEN_PRICE, TOKEN_PRICE, 1000000);
        $reservedTokens = calculateSnftUnitsFromValue($reservationValue);
        $investmentTokens = 0;
        $donationTokens = 0;
        $payItForwardTokens = 0;
        $kidsTokens = (int)($current['kids_tokens'] ?? 0);
        $landholderHectares = (float)($current['landholder_hectares'] ?? 0);
        $landholderTokens = (int)($current['landholder_tokens'] ?? calculateLandholderTokensFromHectares($landholderHectares));
        $rwaTokens = (int)($current['rwa_tokens'] ?? 0);
        $lrTokens  = (int)($current['lr_tokens']  ?? 0);
    }

    $newUnits = totalTokenUnits($reservedTokens, $investmentTokens, $donationTokens, $payItForwardTokens, $kidsTokens, $landholderTokens, $rwaTokens, $lrTokens);
    if ($newUnits < 1) {
        apiError('At least one beta COG$ must remain reserved.');
    }
    // Reservation value using authoritative prices from token_classes (cents → dollars).
    $reservationValue = round(
        ($reservedTokens     * $priceSnft) +
        ($investmentTokens   * $priceToken) +
        ($donationTokens     * $priceDon) +
        ($payItForwardTokens * $pricePif) +
        ($landholderTokens   * $priceLh) +
        ($rwaTokens          * $priceRwa) +
        ($lrTokens           * $priceLr) +
        ($kidsTokens         * $priceKids),
        2
    );
    $previousValue = (float)$current['reservation_value'];
    $previousUnits = (int)$current['tokens_total'];

    $beforeBreakdown = tokenBreakdownFromRow($current, 'snft');
    $breakdown = [
        'reserved_tokens'       => $reservedTokens,
        'investment_tokens'     => $investmentTokens,
        'donation_tokens'       => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'kids_tokens'           => $kidsTokens,
        'landholder_hectares'   => $landholderHectares,
        'landholder_tokens'     => $landholderTokens,
        'rwa_tokens'            => $rwaTokens,
        'lr_tokens'             => $lrTokens,
        'total_tokens'          => $newUnits,
    ];

    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE snft_memberships SET reserved_tokens = ?, investment_tokens = ?, donation_tokens = ?, pay_it_forward_tokens = ?, kids_tokens = ?, landholder_hectares = ?, landholder_tokens = ?, rwa_tokens = ?, lr_tokens = ?, tokens_total = ?, reservation_value = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([$reservedTokens, $investmentTokens, $donationTokens, $payItForwardTokens, $kidsTokens, $landholderHectares, $landholderTokens, $rwaTokens, $lrTokens, $newUnits, $reservationValue, (int)$current['id']]);

        recordReservationUpdate($db, 'snft_member', (string)$current['member_number'], $previousUnits, $newUnits, $previousValue, $reservationValue, $note ?: 'Member updated no-obligation reservation mix');
        try {
            recordReservationTransaction($db, 'snft_member', (int)$current['id'], (string)$current['member_number'], $beforeBreakdown, $breakdown, $previousValue, $reservationValue, $note ?: 'Member updated no-obligation reservation mix', 'wallet_update', 'wallet', 'member', (string)$current['member_number']);
        } catch (Throwable $e) {
            error_log('recordReservationTransaction (snft) failed: ' . $e->getMessage());
        }
        recordWalletEvent($db, 'snft_member', (string)$current['member_number'], 'reservation_updated', 'Member no-obligation reservation mix updated. ' . formatTokenBreakdownNote($breakdown) . '. Total value now $' . number_format($reservationValue, 2) . '.');

        queueCrmSync($db, 'snft_member', (int)$current['id'], [
            'member_number' => $current['member_number'],
            'full_name' => $current['full_name'],
            'email' => $current['email'],
            'mobile' => $current['mobile'],
            'state' => $current['state_code'],
            'suburb' => $current['suburb'],
            'postcode' => $current['postcode'],
            'reserved_tokens' => $reservedTokens,
            'investment_tokens' => $investmentTokens,
            'donation_tokens' => $donationTokens,
            'pay_it_forward_tokens' => $payItForwardTokens,
            'kids_tokens' => $kidsTokens,
            'landholder_hectares' => $landholderHectares,
            'landholder_tokens' => $landholderTokens,
            'tokens_total' => $newUnits,
            'reservation_value' => $reservationValue,
            'approved_tokens_total' => (int)($current['approved_tokens_total'] ?? 0),
            'approved_reservation_value' => (float)($current['approved_reservation_value'] ?? 0),
            'binding_status' => 'joining_fee_now_other_classes_reserved',
        'intent_status' => (string)($current['intent_status'] ?? 'proposed'),
        'entitlement_status' => (string)($current['entitlement_status'] ?? 'inactive'),
        ]);
        // ── Sync member_reservation_lines so memberVault reads correct values ──────
        // memberVault reads token counts from member_reservation_lines (joined with
        // token_classes). memberReservationUpdate previously only wrote to
        // snft_memberships — leaving reservation_lines stale. We upsert them here.
        try {
            $memberRow = $db->prepare(
                'SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1'
            );
            $memberRow->execute([(string)$current['member_number'], 'personal']);
            $mId = (int)($memberRow->fetchColumn() ?: 0);

            if ($mId > 0) {
                $tcStmt = $db->query(
                    "SELECT id, class_code FROM token_classes WHERE is_active = 1"
                );
                $tcMap = [];
                foreach ($tcStmt->fetchAll() as $tc) {
                    $tcMap[(string)$tc['class_code']] = (int)$tc['id'];
                }

                $classUnits = [
                    'PERSONAL_SNFT'       => $reservedTokens,
                    'KIDS_SNFT'           => $kidsTokens,
                    'ASX_INVESTMENT_COG'  => $investmentTokens,
                    'DONATION_COG'        => $donationTokens,
                    'PAY_IT_FORWARD_COG'  => $payItForwardTokens,
                    'LANDHOLDER_COG'      => $landholderTokens,
                    'RWA_COG'             => $rwaTokens,
                    'LR_COG'              => $lrTokens,
                ];

                $upsert = $db->prepare(
                    "INSERT INTO member_reservation_lines" .
                    " (member_id, token_class_id, requested_units, approved_units, paid_units," .
                    " approval_status, payment_status, created_at, updated_at)" .
                    " VALUES (?, ?, ?, 0, 0, 'pending', 'not_required', UTC_TIMESTAMP(), UTC_TIMESTAMP())" .
                    " ON DUPLICATE KEY UPDATE" .
                    " requested_units = VALUES(requested_units)," .
                    " updated_at = UTC_TIMESTAMP()"
                );

                foreach ($classUnits as $code => $units) {
                    $tcId = $tcMap[$code] ?? 0;
                    if ($tcId > 0) {
                        $upsert->execute([$mId, $tcId, $units]);
                    }
                }
            }
        } catch (Throwable $syncErr) {
            error_log('[vault] reservation_lines sync failed: ' . $syncErr->getMessage());
            // Non-fatal — snft_memberships is the source of truth for this update
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    processCrmQueue($db, 1);

    apiSuccess([
        'member_number' => $current['member_number'],
        'tokens_total' => $newUnits,
        'reserved_tokens' => $reservedTokens,
        'investment_tokens' => $investmentTokens,
        'donation_tokens' => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'kids_tokens' => $kidsTokens,
        'landholder_hectares' => $landholderHectares,
        'landholder_tokens'   => $landholderTokens,
        'rwa_tokens'          => $rwaTokens,
        'lr_tokens'           => $lrTokens,
        'reservation_value'   => $reservationValue,
        'approved_tokens_total' => (int)($current['approved_tokens_total'] ?? 0),
        'approved_reservation_value' => (float)($current['approved_reservation_value'] ?? 0),
        'beta_tokens_total' => calculateReservedClassTokenTotal($investmentTokens, $donationTokens, $payItForwardTokens, $landholderTokens),
        'binding_status' => 'joining_fee_now_other_classes_reserved',
        'intent_status' => (string)($current['intent_status'] ?? 'proposed'),
        'entitlement_status' => (string)($current['entitlement_status'] ?? 'inactive'),
        'updated_at' => nowUtc(),
    ]);
}

function businessVault(): void {
    requireMethod('GET');
    $db = getDB();
    $biz = null;
    $p = getAuthPrincipal();
    if (!$p) apiError('Authentication required.', 401);
    if (($p['user_type'] ?? '') === 'bnft') {
        $s = $db->prepare('SELECT * FROM bnft_memberships WHERE id = ? LIMIT 1');
        $s->execute([(int)$p['principal_id']]);
        $biz = $s->fetch(PDO::FETCH_ASSOC);
    } elseif (($p['user_type'] ?? '') === 'snft') {
        $s = $db->prepare('SELECT * FROM bnft_memberships WHERE responsible_member_id = ? LIMIT 1');
        $s->execute([(int)$p['principal_id']]);
        $biz = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$biz) apiError('Business not found.', 404);
    $principal = $p;
    $row = $biz;

    $breakdown = tokenBreakdownFromRow($row, 'bnft');
    $events = fetchWalletEvents($db, 'bnft_business', (string)$row['abn']);
    $history = fetchReservationTransactions($db, 'bnft_business', (string)$row['abn']);
    $announcements = fetchAnnouncementsForSubject($db, 'bnft', (string)$row['abn']);
    $notices = fetchWalletNotices($db, 'business', (int)($row['responsible_member_id'] ?? 0));
    $proposals = fetchProposalsForSubject($db, 'bnft', (string)$row['abn'], false);
    $polls     = fetchWalletPolls($db, 'bnft', (int)$row['id']);

    // ── Gift pool outstanding (pending D/PIF payment intents) ────────────
    $giftPoolOutstanding = 0.0;
    $giftPoolUnpaidLines = [];
    try {
        $bizId = (int)$row['id'];
        if ($bizId > 0) {
            $gpStmt = $db->prepare(
                "SELECT p.id, p.external_reference, p.amount_cents, p.notes, p.created_at
                   FROM payments p
                  WHERE p.member_id = ?
                    AND p.payment_type = 'adjustment'
                    AND p.payment_status = 'pending'
                    AND p.received_at IS NULL
                  ORDER BY p.id ASC"
            );
            $gpStmt->execute([$bizId]);
            $gpRows = $gpStmt->fetchAll();

            $labelToFrontend = [
                'donation cog$'          => 'donation_tokens',
                'donation'               => 'donation_tokens',
                'pay it forward cog$'    => 'pay_it_forward_tokens',
                'pay it forward'         => 'pay_it_forward_tokens',
            ];
            $frontendToLabel = [
                'donation_tokens'       => 'Donation COG$',
                'pay_it_forward_tokens' => 'Pay It Forward COG$',
            ];

            foreach ($gpRows as $gpRow) {
                $pid   = (int)$gpRow['id'];
                $cents = (int)$gpRow['amount_cents'];
                $notes = (string)($gpRow['notes'] ?? '');

                $units         = 0;
                $frontendClass = '';
                $label         = '';
                if (preg_match('/(\d+)\s*x\s+(.+?)\.\s*Reference:/i', $notes, $nm)) {
                    $units         = (int)$nm[1];
                    $label         = trim($nm[2]);
                    $frontendClass = $labelToFrontend[strtolower($label)] ?? '';
                }

                if ($frontendClass === '' || $units < 1) continue;

                $giftPoolOutstanding += $cents / 100;
                $pathway = 'community_charity';
                $pathwayLabel = 'Community & Charity';
                $pathwayNoteMap = [
                    'donation_tokens'       => 'Community & Charity pathway — direct community-project support waiting for settlement.',
                    'pay_it_forward_tokens' => 'Community & Charity pathway — sponsors another Partner entry once settled.',
                ];
                $giftPoolUnpaidLines[] = [
                    'payment_id'  => $pid,
                    'reference'   => (string)($gpRow['external_reference'] ?? ''),
                    'token_class' => $frontendClass,
                    'label'       => $frontendToLabel[$frontendClass] ?? $label,
                    'units'       => $units,
                    'price'       => round($cents / $units / 100, 2),
                    'amount'      => round($cents / 100, 2),
                    'created_at'  => (string)($gpRow['created_at'] ?? ''),
                    'pathway'     => $pathway,
                    'pathway_label' => $pathwayLabel,
                    'pathway_note' => $pathwayNoteMap[$frontendClass] ?? 'Pending payment action.',
                ];
            }
            $giftPoolOutstanding = round($giftPoolOutstanding, 2);
        }
    } catch (Throwable $ignored) {}

    $inviteCode = null;


    apiSuccess([
        'abn' => $row['abn'],
        'support_code' => generateWalletSupportCode('bnft', (string)$row['abn'], (string)$row['email']),
        'support_code_display' => formatWalletSupportCode(generateWalletSupportCode('bnft', (string)$row['abn'], (string)$row['email'])),
        'support_reference' => 'BNFT support check',
        'legal_name' => $row['legal_name'],
        'state' => $row['state'],
        'total_tokens' => $breakdown['total_tokens'],
        'reserved_tokens' => $breakdown['reserved_tokens'],
        'invest_tokens' => $breakdown['investment_tokens'],
        'investment_tokens' => $breakdown['investment_tokens'],
        'donation_tokens' => $breakdown['donation_tokens'],
        'pay_it_forward_tokens' => $breakdown['pay_it_forward_tokens'],
        'kids_tokens' => $breakdown['kids_tokens'],
        'landholder_hectares' => $breakdown['landholder_hectares'],
        'landholder_tokens' => (float)$breakdown['landholder_tokens'],
        'rwa_tokens' => (float)($row['rwa_tokens'] ?? 0),
        'bus_prop_tokens' => (float)($row['bus_prop_tokens'] ?? 0),
        'community_tokens' => (float)($breakdown['community_tokens'] ?? ($row['community_tokens'] ?? 0)),
        'token_breakdown' => $breakdown,
        // Reservation value: Reservation-class COGs only.
        // ASX, RWA, Landholder = $4/token. Business Property COG$ = $40/token.
        // Excludes: BNFT fixed fee, Donation, Pay It Forward.
        'reservation_value' => round(
            (
                (float)($row['invest_tokens']     ?? 0) +
                (float)($row['rwa_tokens']        ?? 0) +
                (float)($row['reserved_tokens']   ?? 0) +
                (float)($row['landholder_tokens'] ?? 0)
            ) * tokenPriceAsDollars()
            + ((float)($row['bus_prop_tokens'] ?? 0) * 40.00),
            4
        ),
        'fixed_bnft_fee' => BNFT_FIXED_FEE,
        'wallet_status' => $row['wallet_status'],
        'payment_status' => $row['signup_payment_status'],
        'binding_status' => 'joining_fee_now_other_classes_reserved',
        'intent_status' => 'proposed_only',
        'entitlement_status' => 'inactive',
        'events' => $events,
        'reservation_history' => $history,
        'announcements' => $announcements,
        'announcement_unread' => count(array_filter($announcements, static fn(array $a): bool => !$a['is_read'])),
        'notices' => $notices,
        'notices_unread' => count(array_filter($notices, static fn(array $n): bool => !$n['is_read'])),
        'legacy_consultations' => $proposals,
        'proposals' => $proposals,
        'open_proposals' => count(array_filter($proposals, static fn(array $p): bool => $p['status'] === 'open')),
        'poll_initiations' => [],
        'open_poll_initiations' => 0,
        'polls' => $polls,
        'open_polls' => count(array_filter($polls, static fn(array $p): bool => $p['status'] === 'open')),
        'wallet_addresses' => getOrCreateWalletAddresses($db, (string)$row['abn'], (int)$row['id']),
        'gift_pool_outstanding' => $giftPoolOutstanding,
        'gift_pool_unpaid_lines' => $giftPoolUnpaidLines,
        'refreshed_at' => nowUtc(),
    ]);
}

function businessReservationUpdate(): void {
    requireMethod('POST');
    $db = getDB();
    $p = getAuthPrincipal();
    if (!$p) apiError('Authentication required.', 401);
    $body = jsonBody();
    $note = sanitize($body['note'] ?? '');

    if (($p['user_type'] ?? '') === 'bnft') {
        $stmt = $db->prepare('SELECT id, abn, legal_name, trading_name, entity_type, contact_name, position_title, email, mobile, state_code, industry, website, use_case, reserved_tokens, invest_tokens, rwa_tokens, donation_tokens, pay_it_forward_tokens, landholder_hectares, landholder_tokens, bus_prop_tokens, community_tokens, reservation_value FROM bnft_memberships WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$p['principal_id']]);
    } else {
        $stmt = $db->prepare('SELECT id, abn, legal_name, trading_name, entity_type, contact_name, position_title, email, mobile, state_code, industry, website, use_case, reserved_tokens, invest_tokens, rwa_tokens, donation_tokens, pay_it_forward_tokens, landholder_hectares, landholder_tokens, bus_prop_tokens, community_tokens, reservation_value FROM bnft_memberships WHERE responsible_member_id = ? LIMIT 1');
        $stmt->execute([(int)$p['principal_id']]);
    }
    $current = $stmt->fetch();
    if (!$current) {
        apiError('Business not found.', 404);
    }

    // reserved_tokens: Business Property COG$ — editable 0–1000
    $reservedTokens    = normalizeTokenCount($body['reserved_tokens']    ?? $current['reserved_tokens'],    0, 1000);
    $investTokens      = normalizeTokenCount($body['invest_tokens']        ?? $current['invest_tokens'],        0, 100000);
    $rwaTokens         = normalizeTokenCount($body['rwa_tokens']           ?? $current['rwa_tokens'],           0, 100000);
    $donationTokens    = normalizeTokenCount($body['donation_tokens']      ?? $current['donation_tokens'],      0, 100000);
    $payItForwardTokens= normalizeTokenCount($body['pay_it_forward_tokens']?? $current['pay_it_forward_tokens'],0, 100000);
    $busPropTokens     = (float)($body['bus_prop_tokens']    ?? $current['bus_prop_tokens']    ?? 0);
    $communityTokens   = (float)($body['community_tokens']   ?? $current['community_tokens']   ?? 0);
    $landholderHectares= max(0, (float)($body['landholder_hectares'] ?? $current['landholder_hectares'] ?? 0));
    // For business members landholder_tokens may be admin-set and is not constrained
    // by landholder_hectares (which is 0 for most businesses). Use direct bounds check
    // so saves don't silently zero out an admin-assigned landholder allocation.
    $landholderTokens  = normalizeTokenCount($body['landholder_tokens'] ?? $current['landholder_tokens'] ?? 0, 0, 1000000);

    // Snapshot previous values for delta events
    $prevInvest   = (float)$current['invest_tokens'];
    $prevRwa      = (float)($current['rwa_tokens'] ?? 0);
    $prevDonation = (int)$current['donation_tokens'];
    $prevPif      = (int)$current['pay_it_forward_tokens'];
    $prevReserved = (int)$current['reserved_tokens'];
    $prevBusProp  = (float)($current['bus_prop_tokens'] ?? 0);

    $previousUnits = totalTokenUnits($prevReserved, $prevInvest, $prevRwa, $prevDonation, $prevPif, (int)($current['landholder_tokens'] ?? 0));
    $previousValue = (float)$current['reservation_value'];
    $newUnits      = totalTokenUnits($reservedTokens, $investTokens, $rwaTokens, $donationTokens, $payItForwardTokens, $landholderTokens);
    // Reservation value: Reservation-class COGs only.
    // ASX, RWA, Landholder = $4/token. Business Property COG$ = $40/token.
    // Excludes: BNFT fixed fee, Donation (D), Pay It Forward (P).
    $reservationValue = round(
        (($investTokens + $rwaTokens + $landholderTokens) * tokenPriceAsDollars())
        + ($busPropTokens * 40.00)
        + ($reservedTokens * tokenPriceAsDollars()),
        4
    );

    $beforeBreakdown = tokenBreakdownFromRow($current, 'bnft');
    $breakdown = [
        'reserved_tokens'       => $reservedTokens,
        'investment_tokens'     => $investTokens,
        'donation_tokens'       => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'landholder_hectares'   => $landholderHectares,
        'landholder_tokens'     => $landholderTokens,
        'rwa_tokens'            => $rwaTokens,
        'bus_prop_tokens'       => $busPropTokens,
        'community_tokens'      => $communityTokens,
        'total_tokens'          => $newUnits,
    ];

    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE bnft_memberships SET reserved_tokens = ?, invest_tokens = ?, rwa_tokens = ?, donation_tokens = ?, pay_it_forward_tokens = ?, landholder_hectares = ?, landholder_tokens = ?, bus_prop_tokens = ?, community_tokens = ?, reservation_value = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([$reservedTokens, $investTokens, $rwaTokens, $donationTokens, $payItForwardTokens, $landholderHectares, $landholderTokens, $busPropTokens, $communityTokens, $reservationValue, (int)$current['id']]);

        recordReservationUpdate($db, 'bnft_business', (string)$current['abn'], $previousUnits, $newUnits, $previousValue, $reservationValue, $note ?: 'Business updated beta reservation token mix');
        recordReservationTransaction($db, 'bnft_business', (int)$current['id'], (string)$current['abn'], $beforeBreakdown, $breakdown, $previousValue, $reservationValue, $note ?: 'Business updated beta reservation token mix', 'wallet_update', 'wallet', 'business', (string)$current['abn']);
        recordWalletEvent($db, 'bnft_business', (string)$current['abn'], 'reservation_updated', 'Business beta token mix updated. ' . formatTokenBreakdownNote($breakdown) . '. Fixed BNFT fee retained. Total value now $' . number_format($reservationValue, 2) . '.');

        // Per-class delta events so history shows exactly what was added or cancelled
        $classDeltas = [
            'ASX Investment COG$'    => $investTokens       - $prevInvest,
            'RWA COG$'               => $rwaTokens          - $prevRwa,
            'Donation COG$'          => $donationTokens     - $prevDonation,
            'Pay It Forward COG$'    => $payItForwardTokens - $prevPif,
            'Business Property COG$' => $busPropTokens      - $prevBusProp,
        ];
        foreach ($classDeltas as $className => $delta) {
            if (abs((float)$delta) < 0.00001) continue;
            if ($delta > 0) {
                recordWalletEvent($db, 'bnft_business', (string)$current['abn'], 'tokens_added',
                    'Added ' . $delta . ' × ' . $className . ($note ? ' — ' . $note : ''));
            } else {
                recordWalletEvent($db, 'bnft_business', (string)$current['abn'], 'tokens_cancelled',
                    'Cancelled ' . abs($delta) . ' × ' . $className . ($note ? ' — ' . $note : ''));
            }
        }

        queueCrmSync($db, 'bnft_business', (int)$current['id'], [
            'abn' => $current['abn'],
            'legal_name' => $current['legal_name'],
            'trading_name' => $current['trading_name'],
            'entity_type' => $current['entity_type'],
            'contact_name' => $current['contact_name'],
            'position_title' => $current['position_title'],
            'email' => $current['email'],
            'mobile' => $current['mobile'],
            'state' => $current['state_code'],
            'industry' => $current['industry'],
            'website' => $current['website'],
            'use_case' => $current['use_case'],
            'reserved_tokens' => $reservedTokens,
            'invest_tokens' => $investTokens,
            'donation_tokens' => $donationTokens,
            'pay_it_forward_tokens' => $payItForwardTokens,
            'landholder_hectares' => $landholderHectares,
            'landholder_tokens' => $landholderTokens,
            'reservation_value' => $reservationValue,
            'approved_tokens_total' => (int)($current['approved_tokens_total'] ?? 0),
            'approved_reservation_value' => (float)($current['approved_reservation_value'] ?? 0),
            'binding_status' => 'joining_fee_now_other_classes_reserved',
        'intent_status' => (string)($current['intent_status'] ?? 'proposed'),
        'entitlement_status' => (string)($current['entitlement_status'] ?? 'inactive'),
        ]);
        // ── Sync member_reservation_lines so memberVault reads correct values ──────
        // memberVault reads token counts from member_reservation_lines (joined with
        // token_classes). memberReservationUpdate previously only wrote to
        // snft_memberships — leaving reservation_lines stale. We upsert them here.
        try {
            $memberRow = $db->prepare(
                'SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1'
            );
            $memberRow->execute([(string)$current['member_number'], 'personal']);
            $mId = (int)($memberRow->fetchColumn() ?: 0);

            if ($mId > 0) {
                $tcStmt = $db->query(
                    "SELECT id, class_code FROM token_classes WHERE is_active = 1"
                );
                $tcMap = [];
                foreach ($tcStmt->fetchAll() as $tc) {
                    $tcMap[(string)$tc['class_code']] = (int)$tc['id'];
                }

                $classUnits = [
                    'PERSONAL_SNFT'       => $reservedTokens,
                    'KIDS_SNFT'           => $kidsTokens,
                    'ASX_INVESTMENT_COG'  => $investmentTokens,
                    'DONATION_COG'        => $donationTokens,
                    'PAY_IT_FORWARD_COG'  => $payItForwardTokens,
                    'LANDHOLDER_COG'      => $landholderTokens,
                    'RWA_COG'             => $rwaTokens,
                    'BUS_PROP_COG'        => $busPropTokens,
                    'COM_COG'             => $communityTokens,
                    'LR_COG'              => $lrTokens,
                ];

                $upsert = $db->prepare(
                    "INSERT INTO member_reservation_lines" .
                    " (member_id, token_class_id, requested_units, approved_units, paid_units," .
                    " approval_status, payment_status, created_at, updated_at)" .
                    " VALUES (?, ?, ?, 0, 0, 'pending', 'not_required', UTC_TIMESTAMP(), UTC_TIMESTAMP())" .
                    " ON DUPLICATE KEY UPDATE" .
                    " requested_units = VALUES(requested_units)," .
                    " updated_at = UTC_TIMESTAMP()"
                );

                foreach ($classUnits as $code => $units) {
                    $tcId = $tcMap[$code] ?? 0;
                    if ($tcId > 0) {
                        $upsert->execute([$mId, $tcId, $units]);
                    }
                }
            }
        } catch (Throwable $syncErr) {
            error_log('[vault] reservation_lines sync failed: ' . $syncErr->getMessage());
            // Non-fatal — snft_memberships is the source of truth for this update
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    processCrmQueue($db, 1);

    apiSuccess([
        'abn' => $current['abn'],
        'reserved_tokens' => $reservedTokens,
        'invest_tokens' => $investTokens,
        'investment_tokens' => $investTokens,
        'rwa_tokens' => $rwaTokens,
        'donation_tokens' => $donationTokens,
        'pay_it_forward_tokens' => $payItForwardTokens,
        'landholder_hectares' => $landholderHectares,
        'landholder_tokens' => $landholderTokens,
        'bus_prop_tokens' => $busPropTokens,
        'community_tokens' => $communityTokens,
        'total_tokens' => $newUnits,
        'reservation_value' => $reservationValue,
        'approved_tokens_total' => (int)($current['approved_tokens_total'] ?? 0),
        'approved_reservation_value' => (float)($current['approved_reservation_value'] ?? 0),
        'beta_tokens_total' => calculateReservedClassTokenTotal($investTokens, $donationTokens, $payItForwardTokens, $landholderTokens),
        'binding_status' => 'joining_fee_now_other_classes_reserved',
        'intent_status' => (string)($current['intent_status'] ?? 'proposed'),
        'entitlement_status' => (string)($current['entitlement_status'] ?? 'inactive'),
        'updated_at' => nowUtc(),
    ]);
}

function fetchWalletEvents(PDO $db, string $subjectType, string $subjectRef): array {
    $events = $db->prepare('SELECT event_type, description, created_at FROM wallet_events WHERE subject_type = ? AND subject_ref = ? ORDER BY id DESC LIMIT 20');
    $events->execute([$subjectType, $subjectRef]);
    return $events->fetchAll();
}

function fetchAnnouncementsForSubject(PDO $db, string $userType, string $subjectRef): array {
    $audiences = audienceForUserType($userType);
    $subjectType = subjectTypeForUserType($userType);
    $stmt = $db->prepare('
        SELECT a.id, a.audience, a.title, a.body, a.created_by, a.created_at,
               CASE WHEN ar.id IS NULL THEN 0 ELSE 1 END AS is_read
        FROM announcements a
        LEFT JOIN announcement_reads ar
          ON ar.announcement_id = a.id AND ar.subject_type = ? AND ar.subject_ref = ?
        WHERE a.audience IN (?, ?)
        ORDER BY a.id DESC
        LIMIT 20
    ');
    $stmt->execute([$subjectType, $subjectRef, $audiences[0], $audiences[1]]);
    return array_map(static function(array $row): array {
        $row['is_read'] = (bool)$row['is_read'];
        return $row;
    }, $stmt->fetchAll());
}

/**
 * fetchWalletNotices — reads the `wallet_messages` table (Partner Notices) filtered to
 * what's currently live for the given Partner. Used by both vault/member and vault/business
 * so the wallet and the hub's Important News block can render from the same source.
 *
 * Rules:
 *   - status must be 'sent' (not draft/scheduled/archived)
 *   - expires_at must be NULL or in the future
 *   - audience_scope must match the Partner's scope OR be 'all'
 *   - for 'custom' audience_scope, the member_id must match
 * Read-state is joined in via wallet_message_reads for the given member.
 */
function fetchWalletNotices(PDO $db, string $audienceScope, int $memberId): array {
    try {
        $stmt = $db->prepare('
            SELECT m.id, m.message_key, m.audience_scope, m.subject, m.summary, m.body,
                   m.message_type, m.priority, m.status, m.sent_at, m.expires_at,
                   CASE WHEN r.id IS NULL THEN 0 ELSE 1 END AS is_read
            FROM wallet_messages m
            LEFT JOIN wallet_message_reads r
              ON r.message_id = m.id AND r.member_id = ?
            WHERE m.status IN (\'sent\', \'open\')
              AND (m.expires_at IS NULL OR m.expires_at > NOW())
              AND (
                    m.audience_scope = ?
                 OR m.audience_scope = ?
                 OR (m.audience_scope = ? AND m.member_id = ?)
              )
            ORDER BY FIELD(m.priority, ?, ?, ?, ?), m.sent_at DESC, m.id DESC
            LIMIT 20
        ');
        $stmt->execute([
            $memberId,
            'all',            // always-visible scope
            $audienceScope,   // 'personal' | 'business' | 'landholder'
            'custom',
            $memberId,
            'critical','high','normal','low'  // priority ordering
        ]);
        $rows = $stmt->fetchAll();
        return array_map(static function(array $row): array {
            $row['is_read'] = (bool)$row['is_read'];
            // `title` alias so the frontend can use the same shape as announcements.
            $row['title'] = (string)($row['subject'] ?? '');
            // `body` for the card expand state — prefer summary for preview, fall back to body.
            if (empty($row['summary']) && !empty($row['body'])) {
                $row['summary'] = $row['body'];
            }
            return $row;
        }, $rows);
    } catch (Throwable $e) {
        // If the table schema drifts or the table is missing, fail safe with an empty list
        // so the vault endpoint doesn't 500. The error is logged server-side.
        error_log('[fetchWalletNotices] ' . $e->getMessage());
        return [];
    }
}

function phase1BridgeMemberId(PDO $db, string $userType, string $subjectRef): ?int {
    try {
        if ($userType === 'bnft') {
            $stmt = $db->prepare('SELECT id FROM members WHERE member_type = "business" AND (abn = ? OR member_number = ?) ORDER BY id DESC LIMIT 1');
            $stmt->execute([$subjectRef, $subjectRef]);
        } else {
            $stmt = $db->prepare('SELECT id FROM members WHERE member_type = "personal" AND member_number = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$subjectRef]);
        }
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    } catch (Throwable $e) {
        return null;
    }
}

function phase1BridgePartnerId(PDO $db, string $userType, string $subjectRef): ?int {
    try {
        if (!api_table_exists($db, 'partners')) return null;
        $memberId = phase1BridgeMemberId($db, $userType, $subjectRef);
        if (!$memberId) return null;
        $stmt = $db->prepare('SELECT id FROM partners WHERE member_id = ? LIMIT 1');
        $stmt->execute([$memberId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    } catch (Throwable $e) {
        return null;
    }
}

function fetchProposalsForSubject(PDO $db, string $userType, string $subjectRef, bool $eligibleToVote): array {
    // Wrapped in try-catch: the vote_proposals schema (column names, joined tables) may
    // differ between environments. Return empty rather than crashing the wallet load.
    try {
        $audiences   = audienceForUserType($userType);
        // vote_proposals.audience_scope uses 'personal'/'business', not 'snft'/'bnft'
        $propAudiences = $userType === 'snft' ? ['all', 'personal'] : ['all', 'business'];
        $subjectType = subjectTypeForUserType($userType);

        // Probe which columns actually exist on this DB's vote_proposals table.
        $cols = [];
        $colStmt = $db->query("SHOW COLUMNS FROM `vote_proposals`");
        foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }

        $audienceCol  = isset($cols['audience'])                   ? 'p.audience'                   : 'p.audience_scope';
        // Use correct values based on which column exists
        $useAudiences = isset($cols['audience']) ? $audiences : $propAudiences;
        $opensAtCol   = isset($cols['opens_at'])                   ? 'p.opens_at'                   : (isset($cols['starts_at']) ? 'p.starts_at' : 'NULL');
        $optionsCol   = isset($cols['options_json'])               ? 'p.options_json'               : 'NULL';
        $tallyStatCol = isset($cols['tally_status'])               ? 'p.tally_status'               : "'live'";
        $dispWinCol   = isset($cols['dispute_window_closes_at'])   ? 'p.dispute_window_closes_at'   : 'NULL';

        // vote_records join — only if that table exists.
        // v4: vote responses in vote_proposal_responses. my_vote resolved post-query by member_id.
        $voteJoin  = "";
        $myVoteCol = "NULL"; // resolved below via separate lookup

        $sql = "SELECT p.id, p.proposal_key, {$audienceCol} AS audience, p.title, p.summary, p.body,
                       {$optionsCol} AS options_json, p.status, {$tallyStatCol} AS tally_status,
                       p.created_by, {$opensAtCol} AS opens_at, p.closes_at,
                       {$dispWinCol} AS dispute_window_closes_at, p.created_at, p.updated_at,
                       {$myVoteCol} AS my_vote
                FROM vote_proposals p
                {$voteJoin}
                WHERE {$audienceCol} IN (:a0, :a1)
                ORDER BY p.id DESC LIMIT 20";

        $stmt   = $db->prepare($sql);
        // my_vote fetched separately to avoid needing member_id inside this function
        $params = [':a0' => $useAudiences[0], ':a1' => $useAudiences[1]];
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        if (!$items) { return []; }

        $ids = array_map(static fn(array $item): int => (int)$item['id'], $items);

        // Tally votes from vote_proposal_responses (v4).
        $tallies = [];
        try {
            $ph    = implode(',', array_fill(0, count($ids), '?'));
            $tStmt = $db->prepare("SELECT proposal_id, response_value AS choice_value, COUNT(*) AS votes FROM vote_proposal_responses WHERE proposal_id IN ({$ph}) GROUP BY proposal_id, response_value");
            $tStmt->execute($ids);
            foreach ($tStmt->fetchAll() as $row) {
                $tallies[(int)$row['proposal_id']][(string)$row['choice_value']] = (int)$row['votes'];
            }
        } catch (Throwable $e) {}

        // Fetch this member's own responses (for my_vote display).
        $myResponses = [];
        try {
            // Lookup differs by user type: personal members use snft_memberships,
            // business members use bnft_memberships keyed on ABN.
            if ($userType === 'snft') {
                $mStmt = $db->prepare('SELECT id FROM snft_memberships WHERE member_number = ? LIMIT 1');
            } else {
                $mStmt = $db->prepare('SELECT id FROM bnft_memberships WHERE abn = ? LIMIT 1');
            }
            $mStmt->execute([$subjectRef]);
            $mRow = $mStmt->fetch();
            if ($mRow) {
                $ph2   = implode(',', array_fill(0, count($ids), '?'));
                $rStmt = $db->prepare("SELECT proposal_id, response_value, response_note FROM vote_proposal_responses WHERE member_id = ? AND proposal_id IN ({$ph2})");
                $rStmt->execute(array_merge([(int)$mRow['id']], $ids));
                foreach ($rStmt->fetchAll() as $row) {
                    $myResponses[(int)$row['proposal_id']] = [
                        'value' => (string)$row['response_value'],
                        'note'  => (string)($row['response_note'] ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) {}

        // Disputes — only if table exists.
        $disputes = $myDisputes = [];
        $hasDisputes = false;
        try { $db->query("SELECT 1 FROM vote_disputes LIMIT 1"); $hasDisputes = true; } catch (Throwable $e) {}
        if ($hasDisputes) {
            $ph    = implode(',', array_fill(0, count($ids), '?'));
            $dStmt = $db->prepare("SELECT proposal_id, status, COUNT(*) AS total FROM vote_disputes WHERE proposal_id IN ({$ph}) GROUP BY proposal_id, status");
            $dStmt->execute($ids);
            foreach ($dStmt->fetchAll() as $row) {
                $disputes[(int)$row['proposal_id']][(string)$row['status']] = (int)$row['total'];
            }
            $mdStmt = $db->prepare("SELECT proposal_id, status FROM vote_disputes WHERE proposal_id IN ({$ph}) AND subject_type = ? AND subject_ref = ?");
            $mdStmt->execute(array_merge($ids, [$subjectType, $subjectRef]));
            foreach ($mdStmt->fetchAll() as $row) {
                $myDisputes[(int)$row['proposal_id']] = (string)$row['status'];
            }
        }

        $bridgeRows = [];
        if (api_table_exists($db, 'proposal_register')) {
            $keys = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['proposal_key'] ?? ''), $items)));
            if ($keys) {
                try {
                    $phBridge = implode(',', array_fill(0, count($keys), '?'));
                    $bStmt = $db->prepare("SELECT proposal_key, id, status, linked_poll_id FROM proposal_register WHERE proposal_key IN ({$phBridge})");
                    $bStmt->execute($keys);
                    foreach ($bStmt->fetchAll() as $row) {
                        $bridgeRows[(string)$row['proposal_key']] = $row;
                    }
                } catch (Throwable $e) {}
            }
        }

        // Comment counts — anonymous, just totals per proposal
        $commentCounts = [];
        try {
            if (api_table_exists($db, 'proposal_comments')) {
                $ph3 = implode(',', array_fill(0, count($ids), '?'));
                $cStmt = $db->prepare("SELECT proposal_id, COUNT(*) AS cnt FROM proposal_comments WHERE proposal_id IN ({$ph3}) GROUP BY proposal_id");
                $cStmt->execute($ids);
                foreach ($cStmt->fetchAll() as $row) {
                    $commentCounts[(int)$row['proposal_id']] = (int)$row['cnt'];
                }
            }
        } catch (Throwable $e) {}

        // Inject my_vote and tally. v4: fixed yes/maybe/no, no options_json.
        foreach ($items as &$item) {
            $raw = $myResponses[(int)$item['id']] ?? null;
            $item['my_vote']      = $raw ? $raw['value'] : null;
            $item['my_vote_note'] = $raw ? $raw['note']  : null;
            $pid     = (int)$item['id'];
            $options = ['yes', 'maybe', 'no'];
            $counts  = []; $total = 0;
            foreach ($options as $option) {
                $count    = (int)($tallies[$pid][$option] ?? 0);
                $counts[] = ['label' => $option, 'votes' => $count];
                $total   += $count;
            }
            $item['options']           = $options;
            $item['tally']             = $counts;
            $item['total_votes']       = $total;
            $item['eligible_to_vote']  = $eligibleToVote;
            $item['open_disputes']     = (int)($disputes[$pid]['open']     ?? 0);
            $item['my_dispute_status'] = $myDisputes[$pid] ?? null;
            $item['comment_count']     = (int)($commentCounts[$pid] ?? 0);
            $bridge = $bridgeRows[(string)($item['proposal_key'] ?? '')] ?? null;
            $item['bridge_status'] = $bridge['status'] ?? null;
            $item['bridge_id'] = isset($bridge['id']) ? (int)$bridge['id'] : null;
            $item['linked_poll_id'] = isset($bridge['linked_poll_id']) ? (int)$bridge['linked_poll_id'] : null;
        }
        unset($item);
        return $items;

    } catch (Throwable $e) {
        // Schema mismatch or missing tables — return empty proposals rather than crashing the wallet.
        return [];
    }
}




function countEligibleInitiatingPartners(PDO $db): int {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM members WHERE member_type = 'personal' AND is_active = 1 AND signup_payment_status = 'paid'");
        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

function partnersPollInitiationThreshold(int $eligibleCount): int {
    if ($eligibleCount <= 0) return 1;
    return max(1, min(10, (int)ceil($eligibleCount * 0.01)));
}

function hasPartnersPollInitiationSchema(PDO $db): bool {
    return api_table_exists($db, 'proposal_register') && api_table_exists($db, 'partners_poll_initiators');
}

function ensurePartnersPollInitiationOpen(PDO $db, int $proposalRegisterId): array {
    $stmt = $db->prepare('SELECT * FROM proposal_register WHERE id = ? LIMIT 1');
    $stmt->execute([$proposalRegisterId]);
    $proposal = $stmt->fetch();
    if (!$proposal) {
        apiError('Poll initiation not found.', 404);
    }
    $eligibleCount = countEligibleInitiatingPartners($db);
    $threshold = partnersPollInitiationThreshold($eligibleCount);
    $countStmt = $db->prepare('SELECT COUNT(*) FROM partners_poll_initiators WHERE proposal_register_id = ?');
    $countStmt->execute([$proposalRegisterId]);
    $initiatorCount = (int)$countStmt->fetchColumn();

    $linkedPollId = (int)($proposal['linked_poll_id'] ?? 0);
    $now = nowUtc();
    if ($linkedPollId < 1 && $initiatorCount >= $threshold && api_table_exists($db, 'community_polls')) {
        $pollKey = (string)($proposal['proposal_key'] ?? ('partners-poll-' . $proposalRegisterId));
        $summary = (string)($proposal['summary'] ?? '');
        $body = (string)($proposal['body'] ?? '');
        $title = (string)($proposal['title'] ?? 'Partners Poll');
        $originMemberId = (int)($proposal['origin_member_id'] ?? 0) ?: null;
        $delibOpen = gmdate('Y-m-d H:i:s');
        $delibClose = gmdate('Y-m-d H:i:s', strtotime('+7 days'));
        $voteOpen = $delibClose;
        $voteClose = gmdate('Y-m-d H:i:s', strtotime('+14 days'));

        $db->beginTransaction();
        try {
            $cpStmt = $db->prepare("INSERT INTO community_polls (poll_key, title, summary, body, resolution_type, eligibility_scope, proposed_by_partner_id, sponsored_partner_count, deliberation_opens_at, deliberation_closes_at, voting_opens_at, voting_closes_at, status, quorum_required_count, quorum_reached, created_at, updated_at) VALUES (?, ?, ?, ?, 'ordinary', 'personal', ?, ?, ?, ?, ?, ?, 'deliberation', ?, 0, ?, ?)");
            $cpStmt->execute([$pollKey, $title, $summary ?: null, $body ?: null, $originMemberId, $initiatorCount, $delibOpen, $delibClose, $voteOpen, $voteClose, $threshold, $now, $now]);
            $linkedPollId = (int)$db->lastInsertId();

            if (api_table_exists($db, 'wallet_polls')) {
                $wpStmt = $db->prepare("INSERT INTO wallet_polls (poll_key, community_poll_id, title, summary, body, poll_type, audience_scope, status, opens_at, closes_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'binding_resolution', 'personal', 'scheduled', ?, ?, ?, ?)");
                $wpStmt->execute([$pollKey, $linkedPollId, $title, $summary ?: null, $body ?: null, $voteOpen, $voteClose, $now, $now]);
            }

            $prStmt = $db->prepare("UPDATE proposal_register SET linked_poll_id = ?, status = 'open', initiation_threshold = ?, eligible_partner_count = ?, initiation_reached_at = ?, updated_at = ? WHERE id = ?");
            $prStmt->execute([$linkedPollId, $threshold, $eligibleCount, $now, $now, $proposalRegisterId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $stmt->execute([$proposalRegisterId]);
        $proposal = $stmt->fetch();
    } else {
        try {
            $db->prepare('UPDATE proposal_register SET initiation_threshold = ?, eligible_partner_count = ?, updated_at = ? WHERE id = ?')
               ->execute([$threshold, $eligibleCount, $now, $proposalRegisterId]);
        } catch (Throwable $e) {
        }
        $stmt->execute([$proposalRegisterId]);
        $proposal = $stmt->fetch();
    }

    $proposal['initiator_count'] = $initiatorCount;
    $proposal['initiation_threshold'] = (int)($proposal['initiation_threshold'] ?? $threshold);
    $proposal['eligible_partner_count'] = (int)($proposal['eligible_partner_count'] ?? $eligibleCount);
    return $proposal;
}

function fetchPollInitiationsForSubject(PDO $db, string $userType, string $subjectRef, int $memberId, bool $eligibleToVote): array {
    if ($userType !== 'snft' || !api_table_exists($db, 'proposal_register')) return [];
    try {
        $hasPpi = api_table_exists($db, 'partners_poll_initiators');
        if ($hasPpi) {
            $stmt = $db->prepare("SELECT pr.id, pr.proposal_key, pr.title, pr.summary, pr.body, pr.status, pr.created_at, pr.updated_at, pr.linked_poll_id, pr.initiation_threshold, pr.eligible_partner_count, pr.initiation_reached_at, pr.origin_member_id, m.full_name AS origin_name, COUNT(ppi.id) AS initiator_count, MAX(CASE WHEN ppi.member_id = ? THEN 1 ELSE 0 END) AS my_initiation_status FROM proposal_register pr LEFT JOIN partners_poll_initiators ppi ON ppi.proposal_register_id = pr.id LEFT JOIN members m ON m.id = pr.origin_member_id WHERE pr.proposal_type = 'governance' AND pr.origin_type = 'partner' AND pr.status IN ('submitted','sponsored','open') GROUP BY pr.id, pr.proposal_key, pr.title, pr.summary, pr.body, pr.status, pr.created_at, pr.updated_at, pr.linked_poll_id, pr.initiation_threshold, pr.eligible_partner_count, pr.initiation_reached_at, pr.origin_member_id, m.full_name ORDER BY pr.id DESC LIMIT 20");
            $stmt->execute([$memberId]);
        } else {
            // partners_poll_initiators not yet created — show proposals without initiator counts
            $stmt = $db->prepare("SELECT pr.id, pr.proposal_key, pr.title, pr.summary, pr.body, pr.status, pr.created_at, pr.updated_at, pr.linked_poll_id, pr.initiation_threshold, pr.eligible_partner_count, pr.initiation_reached_at, pr.origin_member_id, m.full_name AS origin_name, 0 AS initiator_count, (CASE WHEN pr.origin_member_id = ? THEN 1 ELSE 0 END) AS my_initiation_status FROM proposal_register pr LEFT JOIN members m ON m.id = pr.origin_member_id WHERE pr.proposal_type = 'governance' AND pr.origin_type = 'partner' AND pr.status IN ('submitted','sponsored','open') ORDER BY pr.id DESC LIMIT 20");
            $stmt->execute([$memberId]);
        }
        $items = $stmt->fetchAll();
        if (!$items) return [];
        foreach ($items as &$item) {
            $eligibleCount = (int)($item['eligible_partner_count'] ?? 0);
            if ($eligibleCount < 1) $eligibleCount = countEligibleInitiatingPartners($db);
            $threshold = (int)($item['initiation_threshold'] ?? 0);
            if ($threshold < 1) $threshold = partnersPollInitiationThreshold($eligibleCount);
            $item['initiator_count'] = (int)($item['initiator_count'] ?? 0);
            $item['initiation_threshold'] = $threshold;
            $item['eligible_partner_count'] = $eligibleCount;
            $item['my_initiation_status'] = !empty($item['my_initiation_status']) ? 'joined' : 'none';
            $item['is_threshold_met'] = $item['initiator_count'] >= $threshold;
            $item['status_label'] = match((string)($item['status'] ?? 'submitted')) {
                'submitted' => 'Initiation in progress',
                'sponsored' => 'Threshold met',
                'open' => 'Partners Poll opened',
                default => 'Initiation in progress',
            };
        }
        unset($item);
        return $items;
    } catch (Throwable $e) {
        error_log('[fetchPollInitiations] ' . $e->getMessage());
        // Surface error in _debug field so vault JS can show it
        return [['_error' => $e->getMessage(), 'status' => 'error', 'title' => 'Debug: ' . $e->getMessage()]];
    }
}
// ── Wallet Polls ─────────────────────────────────────────────────────────────────

function fetchWalletPolls(PDO $db, string $userType, int $memberId): array {
    try {
        $audiences = audienceForUserType($userType);
        $stmt = $db->prepare(
            "SELECT wp.id, wp.poll_key, wp.community_poll_id, wp.title, wp.summary, wp.body, wp.poll_type, wp.audience_scope,
                    wp.status, wp.opens_at, wp.closes_at, wp.certified_at, wp.result_summary, wp.audit_hash,
                    cp.deliberation_opens_at, cp.deliberation_closes_at, cp.voting_opens_at, cp.voting_closes_at, cp.sponsored_partner_count
             FROM wallet_polls wp
             LEFT JOIN community_polls cp ON cp.id = wp.community_poll_id
             WHERE wp.audience_scope IN (?,?)
               AND wp.status IN ('scheduled','open','closed','certified')
             ORDER BY wp.id DESC LIMIT 20"
        );
        $stmt->execute([$audiences[0], $audiences[1]]);
        $polls = $stmt->fetchAll();
        if (!$polls) return [];

        $ids = array_map(fn($p) => (int)$p['id'], $polls);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $tallies = [];
        try {
            $tStmt = $db->prepare("SELECT poll_id, choice_code, COUNT(*) AS votes, SUM(vote_weight) AS weight FROM wallet_poll_votes WHERE poll_id IN ({$ph}) GROUP BY poll_id, choice_code");
            $tStmt->execute($ids);
            foreach ($tStmt->fetchAll() as $row) {
                $tallies[(int)$row['poll_id']][(string)$row['choice_code']] = ['votes'=>(int)$row['votes'],'weight'=>(float)$row['weight']];
            }
        } catch (Throwable $e) {}

        $myVotes = [];
        try {
            $walletType = $userType === 'bnft' ? 'business' : 'personal';
            $mvStmt = $db->prepare("SELECT poll_id, choice_code, vote_receipt_hash FROM wallet_poll_votes WHERE member_id = ? AND wallet_type = ? AND poll_id IN ({$ph})");
            $mvStmt->execute(array_merge([$memberId, $walletType], $ids));
            foreach ($mvStmt->fetchAll() as $row) {
                $myVotes[(int)$row['poll_id']] = ['choice'=>(string)$row['choice_code'],'receipt'=>(string)($row['vote_receipt_hash']??'')];
            }
        } catch (Throwable $e) {}

        $phase1PollMap = [];
        if (api_table_exists($db, 'community_polls')) {
            $communityIds = array_values(array_filter(array_map(static fn(array $poll): int => (int)($poll['community_poll_id'] ?? 0), $polls)));
            if (!$communityIds) {
                $keys = array_values(array_filter(array_map(static fn(array $poll): string => (string)($poll['poll_key'] ?? ''), $polls)));
                if ($keys) {
                    try {
                        $phk = implode(',', array_fill(0, count($keys), '?'));
                        $cpStmt = $db->prepare("SELECT id, poll_key, status, audit_hash FROM community_polls WHERE poll_key IN ({$phk})");
                        $cpStmt->execute($keys);
                        foreach ($cpStmt->fetchAll() as $row) {
                            $phase1PollMap['key:' . (string)$row['poll_key']] = $row;
                        }
                    } catch (Throwable $e) {}
                }
            } else {
                try {
                    $phc = implode(',', array_fill(0, count($communityIds), '?'));
                    $cpStmt = $db->prepare("SELECT id, poll_key, status, audit_hash FROM community_polls WHERE id IN ({$phc})");
                    $cpStmt->execute($communityIds);
                    foreach ($cpStmt->fetchAll() as $row) {
                        $phase1PollMap[(string)$row['id']] = $row;
                    }
                } catch (Throwable $e) {}
            }
        }

        $phase1Tallies = [];
        $phase1MyVotes = [];
        if (api_table_exists($db, 'poll_votes')) {
            $communityIds = [];
            foreach ($polls as $poll) {
                $cpid = (int)($poll['community_poll_id'] ?? 0);
                if ($cpid > 0) $communityIds[] = $cpid;
            }
            $communityIds = array_values(array_unique($communityIds));
            if ($communityIds) {
                try {
                    $phpv = implode(',', array_fill(0, count($communityIds), '?'));
                    $t2 = $db->prepare("SELECT community_poll_id, option_code, COUNT(*) AS votes, SUM(vote_weight) AS weight FROM poll_votes WHERE community_poll_id IN ({$phpv}) GROUP BY community_poll_id, option_code");
                    $t2->execute($communityIds);
                    foreach ($t2->fetchAll() as $row) {
                        $phase1Tallies[(int)$row['community_poll_id']][(string)$row['option_code']] = ['votes'=>(int)$row['votes'],'weight'=>(float)$row['weight']];
                    }
                    $mv2 = $db->prepare("SELECT community_poll_id, option_code, vote_receipt_hash FROM poll_votes WHERE member_id = ? AND wallet_type = ? AND community_poll_id IN ({$phpv})");
                    $mv2->execute(array_merge([$memberId, $walletType], $communityIds));
                    foreach ($mv2->fetchAll() as $row) {
                        $phase1MyVotes[(int)$row['community_poll_id']] = ['choice'=>(string)$row['option_code'],'receipt'=>(string)($row['vote_receipt_hash'] ?? '')];
                    }
                } catch (Throwable $e) {}
            }
        }

        foreach ($polls as &$poll) {
            $pid = (int)$poll['id'];
            $cpid = (int)($poll['community_poll_id'] ?? 0);
            $poll['tally']       = $tallies[$pid] ?? ($cpid > 0 ? ($phase1Tallies[$cpid] ?? []) : []);
            $poll['my_vote']     = $myVotes[$pid] ?? ($cpid > 0 ? ($phase1MyVotes[$cpid] ?? null) : null);
            $poll['total_votes'] = array_sum(array_column($poll['tally'] ?? [], 'votes'));
            if (!empty($poll['result_summary'])) { $poll['result_summary'] = json_decode((string)$poll['result_summary'], true) ?? []; }
            $bridge = $cpid > 0 ? ($phase1PollMap[(string)$cpid] ?? null) : ($phase1PollMap['key:' . (string)($poll['poll_key'] ?? '')] ?? null);
            $poll['bridge_status'] = $bridge['status'] ?? null;
            $poll['bridge_poll_id'] = isset($bridge['id']) ? (int)$bridge['id'] : $cpid;
            $poll['bridge_audit_hash'] = $bridge['audit_hash'] ?? null;
            $poll['poll_display_status'] = ((string)($poll['status'] ?? '') === 'scheduled') ? 'deliberation' : (string)($poll['status'] ?? '');
        }
        unset($poll);
        return $polls;
    } catch (Throwable $e) { return []; }
}

// ── P2P Transfer ──────────────────────────────────────────────────────────────

/**
 * Delta-update member_reservation_lines for a P2P transfer.
 * memberVault reads token counts from member_reservation_lines, not snft_memberships,
 * so transfers must update both tables to keep the wallet display accurate.
 * $delta > 0 = credit (receiving), $delta < 0 = debit (sending).
 */
function syncTransferReservationLines(PDO $db, string $memberNumber, string $tokenKey, float $delta): void {
    if (abs($delta) < 0.00001) return;
    $classMap = [
        'investment_tokens' => 'ASX_INVESTMENT_COG',
        'rwa_tokens'        => 'RWA_COG',
        'landholder_tokens' => 'LANDHOLDER_COG',
        'bus_prop_tokens'   => 'BUS_PROP_COG',
        'community_tokens'  => 'COM_COG',
    ];
    $classCode = $classMap[$tokenKey] ?? null;
    if (!$classCode) return;
    try {
        $mId = (int)($db->prepare('SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1')
            ->execute([$memberNumber, 'personal']) ? $db->prepare('SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1') : null);
        // Re-query cleanly
        $mStmt = $db->prepare('SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1');
        $mStmt->execute([$memberNumber, 'personal']);
        $mId = (int)($mStmt->fetchColumn() ?: 0);
        if ($mId < 1) return;

        $tcStmt = $db->prepare('SELECT id FROM token_classes WHERE class_code = ? AND is_active = 1 LIMIT 1');
        $tcStmt->execute([$classCode]);
        $tcId = (int)($tcStmt->fetchColumn() ?: 0);
        if ($tcId < 1) return;

        if ($delta > 0) {
            $db->prepare(
                "INSERT INTO member_reservation_lines
                    (member_id, token_class_id, requested_units, approved_units, paid_units,
                     approval_status, payment_status, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 0, 'pending', 'not_required', UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    requested_units = requested_units + ?,
                    updated_at = UTC_TIMESTAMP()"
            )->execute([$mId, $tcId, $delta, $delta]);
        } else {
            $db->prepare(
                "UPDATE member_reservation_lines
                    SET requested_units = GREATEST(0, requested_units - ?),
                        updated_at = UTC_TIMESTAMP()
                  WHERE member_id = ? AND token_class_id = ?"
            )->execute([abs($delta), $mId, $tcId]);
        }
        // Sync the denormalised membership column for applicable decimal classes
        $membershipColMap = [
            'community_tokens'  => 'community_tokens',
            'investment_tokens' => 'investment_tokens',
            'rwa_tokens'        => 'rwa_tokens',
            'landholder_tokens' => 'landholder_tokens',
            'bus_prop_tokens'   => 'bus_prop_tokens',
        ];
        $membershipCol = $membershipColMap[$tokenKey] ?? null;
        if ($membershipCol && api_column_exists($db, 'snft_memberships', $membershipCol)) {
            $db->prepare(
                'UPDATE snft_memberships SET ' . $membershipCol . ' = GREATEST(0, COALESCE(' . $membershipCol . ', 0) + ?), updated_at = UTC_TIMESTAMP() WHERE member_number = ?'
            )->execute([$delta, $memberNumber]);
        }
    } catch (Throwable $e) {
        error_log('[vault] transfer reservation_lines sync failed: ' . $e->getMessage());
    }
}

function memberP2PTransfer(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    // Accept either a hash address (COGS-CC-...) or a member number
    $recipientInput = sanitize(preg_replace('/\s+/', '', (string)($body['to_wallet_address'] ?? $body['recipient_member_number'] ?? $body['to_member_number'] ?? '')));
    $tokenKey       = sanitize((string)($body['token_key'] ?? $body['token_class'] ?? 'community_tokens'));
    $units          = max(0.0001, (float)($body['units'] ?? 0));
    $note           = sanitize((string)($body['note'] ?? ''));

    if ($recipientInput === '') apiError('Recipient wallet address or Partner number is required.');
    if (in_array($tokenKey, ['investment_tokens', 'rwa_tokens', 'landholder_tokens', 'bus_prop_tokens'], true)) {
        apiError('ASX COG$, RWA COG$, Landholder COG$, and Business Property COG$ are locked until after Expansion Day. Only Community COG$ can transfer right now.');
    }
    if ($tokenKey !== 'community_tokens') apiError('Only Community COG$ can be transferred right now.');
    if ($units < 0.0001) apiError('Must transfer at least 0.0001 Community COG$.');

    $senderRef = (string)$principal['subject_ref'];

    // Resolve recipient — hash address takes priority over member number
    $recipientRef = $recipientInput;
    if (str_starts_with(strtoupper($recipientInput), 'COGS-')) {
        $resolved = resolveWalletAddress($db, $recipientInput);
        if (!$resolved) apiError('Wallet address not found. Check the address and try again.', 404);
        $resolvedClass = (string)($resolved['token_class'] ?? '');
        if ($resolvedClass !== 'community_tokens') apiError('That address is for ' . $resolvedClass . ', not Community COG$. Use a COGS-CC-... address.');
        $recipientRef = (string)$resolved['member_number'];
    }

    if ($recipientRef === $senderRef) apiError('Cannot transfer to yourself.');

    // Generate hash addresses for sender and recipient for ledger recording
    $senderAddr    = generateWalletAddress($senderRef, 'community_tokens');
    $recipientAddr = generateWalletAddress($recipientRef, 'community_tokens');

    $senderMemberStmt = $db->prepare('SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1');
    $senderMemberStmt->execute([$senderRef, 'personal']);
    $senderMemberId = (int)($senderMemberStmt->fetchColumn() ?: 0);
    if ($senderMemberId < 1) apiError('Sender record not found.', 404);

    $recipientLegacyStmt = $db->prepare('SELECT id, member_number, full_name FROM snft_memberships WHERE member_number = ? LIMIT 1');
    $recipientLegacyStmt->execute([$recipientRef]);
    $recipientLegacy = $recipientLegacyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$recipientLegacy) apiError('Recipient Partner not found.', 404);

    $recipientMemberStmt = $db->prepare('SELECT id FROM members WHERE member_number = ? AND member_type = ? LIMIT 1');
    $recipientMemberStmt->execute([$recipientRef, 'personal']);
    $recipientMemberId = (int)($recipientMemberStmt->fetchColumn() ?: 0);
    if ($recipientMemberId < 1) apiError('Recipient Partner record not found.', 404);

    $tcStmt = $db->prepare("SELECT id FROM token_classes WHERE class_code = 'COM_COG' AND is_active = 1 LIMIT 1");
    $tcStmt->execute();
    $tcId = (int)($tcStmt->fetchColumn() ?: 0);
    if ($tcId < 1) apiError('Community COG$ class is not configured.', 500);

    $balStmt = $db->prepare('SELECT requested_units FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1');
    $balStmt->execute([$senderMemberId, $tcId]);
    $senderBalance = (float)($balStmt->fetchColumn() ?: 0);
    if ($senderBalance < $units) apiError('Insufficient Community COG$ balance.');

    $db->beginTransaction();
    try {
        syncTransferReservationLines($db, $senderRef, 'community_tokens', -$units);
        syncTransferReservationLines($db, $recipientRef, 'community_tokens', $units);

        $senderIdRow = $db->prepare('SELECT id, full_name FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $senderIdRow->execute([$senderRef]);
        $sRow = $senderIdRow->fetch();
        $db->prepare(
            'INSERT INTO wallet_beta_exchanges
                (sender_subject_type, sender_subject_id, sender_subject_ref, sender_display_name,
                 recipient_subject_type, recipient_subject_id, recipient_subject_ref, recipient_display_name,
                 token_key, units, note, created_at)
             VALUES (?,?,?,?, ?,?,?,?, ?,?,?,UTC_TIMESTAMP())'
        )->execute([
            'snft_member', $sRow ? (int)$sRow['id'] : 0, $senderRef, $sRow ? (string)$sRow['full_name'] : '',
            'snft_member', (int)$recipientLegacy['id'], $recipientRef, (string)$recipientLegacy['full_name'],
            $tokenKey, $units, $note ?: null,
        ]);

        $unitsFmt = number_format($units, 4);
        recordWalletEvent($db, 'snft_member', $senderRef,    'p2p_transfer_sent',     'Sent '     . $unitsFmt . ' × Community COG$ → ' . $recipientAddr . ($note ? ' (' . $note . ')' : ''));
        recordWalletEvent($db, 'snft_member', $recipientRef, 'p2p_transfer_received', 'Received ' . $unitsFmt . ' × Community COG$ ← ' . $senderAddr    . ($note ? ' (' . $note . ')' : ''));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    apiSuccess([
        'transferred'      => true,
        'token_key'        => $tokenKey,
        'units'            => $units,
        'sender_address'   => $senderAddr,
        'recipient_address'=> $recipientAddr,
        'recorded_at'      => nowUtc(),
    ]);
}

function businessP2PTransfer(): void {
    requireMethod('POST');
    $db = getDB();
    $p = getAuthPrincipal();
    if (!$p) apiError('Authentication required.', 401);
    $body = jsonBody();

    // Resolve business identity for both SNFT and BNFT sessions
    if (($p['user_type'] ?? '') === 'bnft') {
        $bizStmt = $db->prepare('SELECT id, abn FROM bnft_memberships WHERE id = ? LIMIT 1');
        $bizStmt->execute([(int)$p['principal_id']]);
    } else {
        $bizStmt = $db->prepare('SELECT id, abn FROM bnft_memberships WHERE responsible_member_id = ? LIMIT 1');
        $bizStmt->execute([(int)$p['principal_id']]);
    }
    $biz = $bizStmt->fetch();
    if (!$biz) apiError('Business not found.', 404);
    $bizId    = (int)$biz['id'];
    $senderRef = (string)$biz['abn'];

    $recipientAddr = sanitize(preg_replace('/\s+/', '', (string)($body['to_ref'] ?? $body['recipient_address'] ?? '')));
    $tokenKey      = sanitize((string)($body['token_class'] ?? $body['token_key'] ?? ''));
    $units         = max(0.0001, (float)($body['units'] ?? 0));
    $note          = sanitize((string)($body['note'] ?? ''));

    // Normalise token key (bnft uses invest_tokens; wallet address uses investment_tokens)
    if ($tokenKey === 'investment_tokens') $tokenKey = 'invest_tokens';
    $allowed = ['invest_tokens', 'rwa_tokens', 'landholder_tokens', 'bus_prop_tokens'];
    if ($recipientAddr === '') apiError('Recipient wallet address is required.');
    if (!in_array($tokenKey, $allowed, true)) apiError('Invalid token class.');

    // ── Resolve wallet address → recipient identity ──────────────────────────
    $addrClassMap = [
        'invest_tokens'     => 'investment_tokens',
        'rwa_tokens'        => 'rwa_tokens',
        'landholder_tokens' => 'landholder_tokens',
        'bus_prop_tokens'   => 'bus_prop_tokens',
    ];
    $addrClass = $addrClassMap[$tokenKey] ?? $tokenKey;
    $resolved  = resolveWalletAddress($db, $recipientAddr);
    if (!$resolved) apiError('Recipient wallet address not found. Check the address and try again.', 404);

    $recipientRef  = (string)$resolved['member_number']; // member_number or ABN
    $resolvedClass = (string)($resolved['token_class'] ?? $addrClass);

    if ($resolvedClass !== $addrClass) {
        apiError('Wallet address is for ' . $resolvedClass . ' but you selected ' . $tokenKey . '. Use the matching address.');
    }
    if ($recipientRef === $senderRef) apiError('Cannot transfer to yourself.');

    // Determine recipient type: snft (member) or bnft (business)
    $snftStmt = $db->prepare('SELECT id, member_number, full_name FROM snft_memberships WHERE member_number = ? LIMIT 1');
    $snftStmt->execute([$recipientRef]);
    $snftRecip = $snftStmt->fetch();

    $bnftRecip = null;
    if (!$snftRecip) {
        $bnftStmt = $db->prepare('SELECT id, abn, legal_name FROM bnft_memberships WHERE abn = ? LIMIT 1');
        $bnftStmt->execute([$recipientRef]);
        $bnftRecip = $bnftStmt->fetch();
    }
    if (!$snftRecip && !$bnftRecip) apiError('Recipient not found.', 404);

    // Verify sender balance
    $balStmt = $db->prepare('SELECT ' . $tokenKey . ' AS bal FROM bnft_memberships WHERE id = ? LIMIT 1');
    $balStmt->execute([$bizId]);
    $balRow = $balStmt->fetch();
    if (!$balRow || (float)$balRow['bal'] < $units) apiError('Insufficient balance.');

    $db->beginTransaction();
    try {
        // Debit sender (always bnft)
        $db->prepare('UPDATE bnft_memberships SET ' . $tokenKey . ' = ' . $tokenKey . ' - ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
           ->execute([$units, $bizId]);

        // Credit recipient — snft uses investment_tokens, bnft uses invest_tokens
        if ($snftRecip) {
            // bnft uses invest_tokens; snft uses investment_tokens; others match
            $snftKeyMap = [
                'invest_tokens'     => 'investment_tokens',
                'rwa_tokens'        => 'rwa_tokens',
                'landholder_tokens' => 'landholder_tokens',
                'bus_prop_tokens'   => 'bus_prop_tokens',
            ];
            $snftKey = $snftKeyMap[$tokenKey] ?? $tokenKey;
            $db->prepare('UPDATE snft_memberships SET ' . $snftKey . ' = ' . $snftKey . ' + ?, updated_at = UTC_TIMESTAMP() WHERE member_number = ?')
               ->execute([$units, $recipientRef]);
            $db->prepare(
                'UPDATE snft_memberships
                    SET tokens_total      = GREATEST(0, tokens_total + ?),
                        reservation_value = GREATEST(0, reservation_value + ?),
                        updated_at        = UTC_TIMESTAMP()
                  WHERE member_number = ?'
            )->execute([$units, round($units * tokenPriceAsDollars(), 2), $recipientRef]);
            // memberVault reads from member_reservation_lines — must delta-update it too
            syncTransferReservationLines($db, $recipientRef, $snftKey, $units);
            $recipSubjectType = 'snft_member';
            $recipId          = (int)$snftRecip['id'];
            $recipName        = (string)$snftRecip['full_name'];
        } else {
            $db->prepare('UPDATE bnft_memberships SET ' . $tokenKey . ' = ' . $tokenKey . ' + ?, updated_at = UTC_TIMESTAMP() WHERE abn = ?')
               ->execute([$units, $recipientRef]);
            $recipSubjectType = 'bnft_business';
            $recipId          = (int)$bnftRecip['id'];
            $recipName        = (string)$bnftRecip['legal_name'];
        }

        // Ledger entries
        $db->prepare(
            'INSERT INTO wallet_beta_exchanges
                (sender_subject_type, sender_subject_id, sender_subject_ref, sender_display_name,
                 recipient_subject_type, recipient_subject_id, recipient_subject_ref, recipient_display_name,
                 token_key, units, note, created_at)
             VALUES (?,?,?,?, ?,?,?,?, ?,?,?,UTC_TIMESTAMP())'
        )->execute([
            'bnft_business', $bizId, $senderRef, (string)($biz['legal_name'] ?? $senderRef),
            $recipSubjectType, $recipId, $recipientRef, $recipName,
            $tokenKey, $units, $note ?: null,
        ]);
        recordWalletEvent($db, 'bnft_business',   $senderRef,   'p2p_transfer_sent',     'Sent '     . $units . ' × ' . $tokenKey . ' → ' . $recipientAddr . ($note ? ' (' . $note . ')' : ''));
        recordWalletEvent($db, $recipSubjectType, $recipientRef, 'p2p_transfer_received', 'Received ' . $units . ' × ' . $tokenKey . ' ← ' . $senderRef     . ($note ? ' (' . $note . ')' : ''));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }

    apiSuccess(['transferred' => true, 'token_key' => $tokenKey, 'units' => $units, 'recipient' => $recipientAddr, 'recorded_at' => nowUtc()]);
}

function markAnnouncementReadVault(): void {
    requireMethod('POST');
    $principal = getAuthPrincipal();
    if (!$principal) apiError('Authentication required', 401);
    $db = getDB();
    $body = jsonBody();

    $memberId = (int)$principal['principal_id'];

    // ── Wallet message / notice ──────────────────────────────────────────────
    // If notice_id is provided, write to wallet_message_reads instead.
    $noticeId = (int)($body['notice_id'] ?? 0);
    if ($noticeId > 0) {
        try {
            $db->prepare(
                'INSERT INTO wallet_message_reads (message_id, member_id, read_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE read_at = NOW()'
            )->execute([$noticeId, $memberId]);
        } catch (Throwable $e) {
            // Table may not exist — fail silently so vault doesn't break
            error_log('[mark-read notice] ' . $e->getMessage());
        }
        apiSuccess(['notice_id' => $noticeId, 'read' => true]);
    }

    // ── Announcement ─────────────────────────────────────────────────────────
    $announcementId = (int)($body['announcement_id'] ?? 0);
    if ($announcementId < 1) apiError('Announcement ID or notice ID required.');

    // If for_business flag set, use business identity so read status matches fetchAnnouncementsForSubject
    $forBusiness = !empty($body['for_business']);
    if ($forBusiness && ($principal['user_type'] ?? '') === 'snft') {
        $s = $db->prepare('SELECT abn FROM bnft_memberships WHERE responsible_member_id = ? LIMIT 1');
        $s->execute([(int)$principal['principal_id']]);
        $biz = $s->fetch();
        if ($biz) {
            $subjectType = 'bnft_business';
            $subjectRef  = (string)$biz['abn'];
        } else {
            $subjectType = subjectTypeForUserType((string)$principal['user_type']);
            $subjectRef  = (string)$principal['subject_ref'];
        }
    } else {
        $subjectType = subjectTypeForUserType((string)$principal['user_type']);
        $subjectRef  = (string)$principal['subject_ref'];
    }

    $db->prepare('INSERT INTO announcement_reads (announcement_id, subject_type, subject_ref, read_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE read_at = NOW()')
       ->execute([$announcementId, $subjectType, $subjectRef]);
    apiSuccess(['announcement_id' => $announcementId, 'read' => true]);
}

function castPollVote(): void {
    requireMethod('POST');
    $principal = requireAnyUserType(['snft', 'bnft']);
    $db = getDB();
    $body = jsonBody();

    $pollId     = (int)($body['poll_id'] ?? 0);
    $choice     = strtolower(sanitize((string)($body['choice'] ?? '')));
    $memberId   = (int)$principal['principal_id'];
    $walletType = ($principal['user_type'] ?? '') === 'bnft' ? 'business' : 'personal';

    if ($pollId < 1 || !in_array($choice, ['yes', 'no'], true)) {
        apiError('Poll ID and valid choice (yes/no) are required.');
    }
    $stmt = $db->prepare('SELECT id, poll_key, community_poll_id, status, opens_at, closes_at, audience_scope FROM wallet_polls WHERE id = ? LIMIT 1');
    $stmt->execute([$pollId]);
    $poll = $stmt->fetch();
    if (!$poll) apiError('Poll not found.', 404);
    if (!in_array((string)$poll['status'], ['open', 'scheduled'], true)) apiError('This poll is not open for voting.');
    if ($poll['opens_at'] && strtotime((string)$poll['opens_at']) > time()) apiError('This poll has not opened yet.');
    if ($poll['closes_at'] && strtotime((string)$poll['closes_at']) < time()) apiError('This poll has closed.');

    // Verify the poll is scoped to this member's audience
    $audience = (string)($poll['audience_scope'] ?? 'all');
    if ($walletType === 'personal' && !in_array($audience, ['all', 'personal'], true)) {
        apiError('This poll is not open to personal members.', 403);
    }
    if ($walletType === 'business' && !in_array($audience, ['all', 'business'], true)) {
        apiError('This poll is not open to business members.', 403);
    }

    $receiptHash = hash('sha256', $pollId . ':' . $memberId . ':' . $walletType . ':' . $choice . ':' . (string)env('APP_SECRET', 'cogs_vault'));
    $db->prepare(
        "INSERT INTO wallet_poll_votes (poll_id, member_id, wallet_type, choice_code, vote_weight, cast_at, vote_receipt_hash)
         VALUES (?,?,?,?,1.000000,UTC_TIMESTAMP(),?)
         ON DUPLICATE KEY UPDATE choice_code=VALUES(choice_code), vote_receipt_hash=VALUES(vote_receipt_hash), cast_at=UTC_TIMESTAMP()"
    )->execute([$pollId, $memberId, $walletType, $choice, $receiptHash]);

    if (api_table_exists($db, 'poll_votes')) {
        $communityPollId = (int)($poll['community_poll_id'] ?? 0);
        if ($communityPollId < 1 && !empty($poll['poll_key']) && api_table_exists($db, 'community_polls')) {
            try {
                $cpStmt = $db->prepare('SELECT id FROM community_polls WHERE poll_key = ? LIMIT 1');
                $cpStmt->execute([(string)$poll['poll_key']]);
                $communityPollId = (int)($cpStmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {}
        }
        if ($communityPollId > 0) {
            try {
                $db->prepare(
                    "INSERT INTO poll_votes (community_poll_id, member_id, wallet_type, option_code, vote_weight, cast_at, vote_receipt_hash)
                     VALUES (?,?,?,?,1.000000,UTC_TIMESTAMP(),?)
                     ON DUPLICATE KEY UPDATE option_code=VALUES(option_code), vote_receipt_hash=VALUES(vote_receipt_hash), cast_at=UTC_TIMESTAMP()"
                )->execute([$communityPollId, $memberId, $walletType, $choice, $receiptHash]);
            } catch (Throwable $e) {
                error_log('[vault/cast-poll] phase1 poll_votes bridge failed: ' . $e->getMessage());
            }
        }
    }

    $subjectType = $walletType === 'business' ? 'bnft_business' : 'snft_member';
    recordWalletEvent($db, $subjectType, (string)$principal['subject_ref'], 'poll_vote_cast',
        'Binding poll vote for poll #' . $pollId . '. Receipt: ' . substr($receiptHash, 0, 12) . '…');

    apiSuccess(['poll_id' => $pollId, 'choice' => $choice, 'receipt' => $receiptHash, 'recorded_at' => nowUtc()]);
}

function createPaymentIntent(): void {
    requireMethod('POST');
    $principal = requireAnyUserType(['snft', 'bnft']);
    $db = getDB();
    $body = jsonBody();

    $isBusiness  = ($principal['user_type'] ?? '') === 'bnft';
    $tokenClass  = sanitize((string)($body['token_class'] ?? ''));
    $units       = max(1, (int)($body['units'] ?? 0));
    $agreed      = (bool)($body['tax_disclaimer_agreed'] ?? false);

    // Both business and personal members can buy Donation and PIF via this endpoint.
    // Kids S-NFT uses vault/kids-order — its own dedicated pathway.
    $allowedPay  = ['donation_tokens', 'pay_it_forward_tokens'];

    if (!in_array($tokenClass, $allowedPay, true)) apiError('Donation and Pay It Forward tokens only. Kids S-NFT uses vault/kids-order.');
    if ($units < 1)  apiError('At least 1 unit is required.');
    if (!$agreed)    apiError('You must agree to the tax disclaimer before proceeding.');

    // Look up pricing from token_classes
    $classCodeMap = ['donation_tokens' => 'DONATION_COG', 'pay_it_forward_tokens' => 'PAY_IT_FORWARD_COG'];
    $classCode    = $classCodeMap[$tokenClass];
    $priceStmt    = $db->prepare('SELECT unit_price_cents, display_name FROM token_classes WHERE class_code = ? AND is_active = 1 LIMIT 1');
    $priceStmt->execute([$classCode]);
    $classRow     = $priceStmt->fetch();
    if (!$classRow) apiError('Token class not found.');

    $amountCents   = (int)$classRow['unit_price_cents'] * $units;
    $amountDollars = $amountCents / 100;
    $className     = (string)$classRow['display_name'];

    if ($isBusiness) {
        // ── Business member ─────────────────────────────────────────────
        $mStmt = $db->prepare('SELECT id, abn, legal_name, email FROM bnft_memberships WHERE id = ? LIMIT 1');
        $mStmt->execute([(int)$principal['principal_id']]);
        $member = $mStmt->fetch();
        if (!$member) apiError('Business not found.');

        $ref       = 'COGS-' . strtoupper(substr(hash('sha256', $member['abn'] . $tokenClass . $units . time()), 0, 8));
        $memberRef = (string)$member['abn'];
        $subjectType = 'bnft_business';

        try {
            $db->prepare(
                "INSERT INTO payments (member_id, payment_type, amount_cents, currency_code, payment_status, external_reference, notes, created_at, updated_at)
                 VALUES (?,'adjustment',?,'AUD','pending',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
            )->execute([
                (int)$member['id'],
                $amountCents,
                $ref,
                "Member payment intent: {$units} x {$className}. Reference: {$ref}",
            ]);
        } catch (Throwable $e) {
            error_log('createPaymentIntent (business) payment record failed: ' . $e->getMessage());
        }

        recordWalletEvent($db, $subjectType, $memberRef, 'payment_intent_created',
            "Payment intent: {$units} × {$className} = \${$amountDollars}. Ref: {$ref}");

        apiSuccess([
            'reference'      => $ref,
            'amount'         => $amountDollars,
            'token_class'    => $className,
            'units'          => $units,
            'recorded_at'    => nowUtc(),
        ]);
        return;
    }

    // ── Personal member (original flow) ─────────────────────────────────
    $mStmt = $db->prepare('SELECT id, member_number, full_name, email FROM snft_memberships WHERE id = ? LIMIT 1');
    $mStmt->execute([(int)$principal['principal_id']]);
    $member = $mStmt->fetch();
    if (!$member) apiError('Member not found.');

    // Generate payment reference
    $ref = 'COGS-' . strtoupper(substr(hash('sha256', $member['member_number'] . $tokenClass . $units . time()), 0, 8));

    // Record in payments table as pending
    try {
        $db->prepare(
"INSERT INTO payments (member_id, payment_type, amount_cents, currency_code, payment_status, external_reference, notes, created_at, updated_at)
             VALUES (?,'adjustment',?,'AUD','pending',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        )->execute([
            (int)$member['id'],
            $amountCents,
            $ref,
            "Member payment intent: {$units} x {$className}. Reference: {$ref}",
        ]);
    } catch (Throwable $e) {
        error_log('createPaymentIntent payment record failed: ' . $e->getMessage());
    }

    // Queue thank-you / payment-instructions email to member
    $bankName    = (string)env('BANK_NAME',    'COG$ Foundation Account');
    $bankBSB     = (string)env('BANK_BSB',     'BSB on request');
    $bankAccount = (string)env('BANK_ACCOUNT', 'Account on request');
    $payId       = (string)env('BANK_PAYID',   'members@cogsaustralia.org');

    $payload = [
        'full_name'      => $member['full_name'],
        'email'          => $member['email'],
        'member_number'  => $member['member_number'],
        'token_class'    => $className,
        'units'          => $units,
        'amount'         => number_format($amountDollars, 2),
        'reference'      => $ref,
        'bank_name'      => $bankName,
        'bank_bsb'       => $bankBSB,
        'bank_account'   => $bankAccount,
        'pay_id'         => $payId,
        'is_donation'    => $tokenClass === 'donation_tokens',
    ];
    try {
        queueEmail($db, 'snft_member', (int)$member['id'],
            $member['email'],
            'payment_intent_member',
            "COG\$ Payment Instructions — {$ref}",
            $payload
        );
        $adminEmail = MAIL_ADMIN_EMAIL ?: 'members@cogsaustralia.org';
        queueEmail($db, 'snft_member', (int)$member['id'],
            $adminEmail,
            'payment_intent_admin',
            "Payment intent received — {$ref} — {$member['full_name']}",
            $payload
        );
    } catch (Throwable $e) {
        error_log('createPaymentIntent email queue failed: ' . $e->getMessage());
    }

    recordWalletEvent($db, 'snft_member', (string)$member['member_number'], 'payment_intent_created',
        "Payment intent created: {$units} x {$className} = \${$amountDollars}. Ref: {$ref}");

    apiSuccess([
        'reference'   => $ref,
        'amount'      => $amountDollars,
        'units'       => $units,
        'token_class' => $className,
        'pay_id'      => $payId,
        'bank_name'   => $bankName,
        'bank_bsb'    => $bankBSB,
        'bank_account'=> $bankAccount,
        'email_queued'=> true,
    ]);
}

function fetchPendingTransfers(PDO $db, string $subjectType, string $subjectRef): array {
    // v4: reads from wallet_beta_exchanges which includes display names.
    try {
        $st = $subjectType; // 'snft_member' or 'bnft_business'
        $stmt = $db->prepare(
            'SELECT id, token_key, sender_subject_ref, recipient_subject_ref,
                    sender_display_name, recipient_display_name, units, note, created_at
             FROM wallet_beta_exchanges
             WHERE (sender_subject_ref = ? AND sender_subject_type = ?)
                OR (recipient_subject_ref = ? AND recipient_subject_type = ?)
             ORDER BY id DESC LIMIT 40'
        );
        $stmt->execute([$subjectRef, $st, $subjectRef, $st]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function memberBusinessCheck(): void {
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db = getDB();
    $s = $db->prepare('SELECT id, abn, legal_name, trading_name, entity_type, wallet_status FROM bnft_memberships WHERE responsible_member_id = ? LIMIT 1');
    $s->execute([(int)$principal['principal_id']]);
    $b = $s->fetch(PDO::FETCH_ASSOC);
    if (!$b) { apiSuccess(['has_business' => false]); return; }
    apiSuccess(['has_business' => true, 'business_id' => (int)$b['id'], 'abn' => $b['abn'], 'legal_name' => $b['legal_name'], 'trading_name' => $b['trading_name'], 'entity_type' => $b['entity_type'], 'wallet_status' => $b['wallet_status']]);
}

// ── Additional endpoints added from development vault ──

function createStripeCheckout(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $site      = rtrim((string)(defined('SITE_URL') && SITE_URL ? SITE_URL : 'https://cogsaustralia.org'), '/');
    $secretKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
    if ($secretKey === '') {
        error_log('[vault/create-checkout] STRIPE_SECRET_KEY is not set in .env — card payment cannot proceed.');
        apiError('Card payment is currently unavailable. Please use bank transfer or PayID.', 503);
    }
    define('COGS_STRIPE_DEBUG', true); // TEMP — remove after diagnosis
    // Validate key format — Stripe live keys start sk_live_, test keys sk_test_
    if (!str_starts_with($secretKey, 'sk_')) {
        error_log('[vault/create-checkout] STRIPE_SECRET_KEY does not appear to be a valid Stripe key (missing sk_ prefix).');
        apiError('Card payment is currently unavailable. Please use bank transfer or PayID.', 503);
    }

    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (empty($items)) apiError('No items provided.');

    // Kids S-NFT uses vault/cancel-kids-order — its own dedicated cancel pathway.
    $allowedPay = ['donation_tokens', 'pay_it_forward_tokens'];
    $classCodeMap = [
        'donation_tokens'       => 'DONATION_COG',
        'pay_it_forward_tokens' => 'PAY_IT_FORWARD_COG',
    ];

    // Fetch member
    $mStmt = $db->prepare('SELECT id, member_number, full_name, email FROM snft_memberships WHERE id = ? LIMIT 1');
    $mStmt->execute([(int)$principal['principal_id']]);
    $member = $mStmt->fetch();
    if (!$member) apiError('Member not found.');

    // ── KYC gate — Kids S-NFT server-side enforcement ────────────────────────
    // The wallet UI disables the button, but we also enforce here so the API
    // cannot be bypassed directly.
    $hasKidsItems = false;
    foreach ($items as $item) {
        if (sanitize((string)($item['token_class'] ?? '')) === 'kids_tokens') {
            $hasKidsItems = true;
            break;
        }
    }
    if ($hasKidsItems) {
        $kycChk = $db->prepare('SELECT kyc_status FROM snft_memberships WHERE id = ? LIMIT 1');
        $kycChk->execute([(int)$principal['principal_id']]);
        $kycChkRow = $kycChk->fetch();
        if (!$kycChkRow || (string)($kycChkRow['kyc_status'] ?? '') !== 'verified') {
            apiError(
                'Identity verification is required before purchasing Kids S-NFT tokens. ' .
                'Please complete Medicare card verification in your Identity section first.',
                403
            );
        }
    }

    // Product images and descriptions for Stripe Checkout display
    $productMeta = [
        'DONATION_COG' => [
            'image' => $site . '/assets/cogs_garden.webp',
            'desc'  => 'Community project contribution — funds Sub-Trust C initiatives that benefit all Australians.',
        ],
        'PAY_IT_FORWARD_COG' => [
            'image' => $site . '/assets/coin_shake.webp',
            'desc'  => 'Fund a future membership for someone who cannot afford to join — stewardship in action.',
        ],
        'KIDS_SNFT' => [
            'image' => $site . '/assets/kids_cogs.webp',
            'desc'  => 'Child membership — held in trust until age 18, then theirs for life. $1 per child.',
        ],
    ];

    // Build Stripe line_items
    $lineItems = [];
    $totalCents = 0;
    foreach ($items as $item) {
        $cls   = sanitize((string)($item['token_class'] ?? ''));
        $units = max(1, (int)($item['units'] ?? 0));
        if (!in_array($cls, $allowedPay, true)) continue;
        $code = $classCodeMap[$cls];

        // Try token_classes first; fall back to static prices if row not yet seeded.
        // DONATION_COG and PAY_IT_FORWARD_COG are $4.00 per unit — entrenched by
        // the JVPA and identical to the SNFT contribution amount.
        $staticFallback = [
            'DONATION_COG'       => ['unit_price_cents' => 400, 'display_name' => 'Donation COG$'],
            'PAY_IT_FORWARD_COG' => ['unit_price_cents' => 400, 'display_name' => 'Pay It Forward COG$'],
        ];
        $tcStmt = $db->prepare('SELECT unit_price_cents, display_name FROM token_classes WHERE class_code = ? AND is_active = 1 LIMIT 1');
        $tcStmt->execute([$code]);
        $tc = $tcStmt->fetch();
        if (!$tc) {
            if (isset($staticFallback[$code])) {
                $tc = $staticFallback[$code];
            } else {
                continue; // unknown class — skip
            }
        }

        $priceCents = (int)$tc['unit_price_cents'];
        $totalCents += $priceCents * $units;
        $meta = $productMeta[$code] ?? [];
        $productData = [
            'name'        => (string)$tc['display_name'],
            'description' => $meta['desc'] ?? ($units . ' × ' . (string)$tc['display_name']),
        ];
        if (!empty($meta['image'])) {
            $productData['images'] = [$meta['image']];
        }
        $lineItems[] = [
            'price_data' => [
                'currency'     => 'aud',
                'unit_amount'  => $priceCents,
                'product_data' => $productData,
            ],
            'quantity' => $units,
        ];
    }

    if (empty($lineItems)) apiError('No valid items to checkout.');

    // Add $0.40 Stripe processing fee as a separate line item
    $lineItems[] = [
        'price_data' => [
            'currency'     => 'aud',
            'unit_amount'  => 40,
            'product_data' => [
                'name'        => 'Stripe processing fee',
                'description' => 'Card processing fee charged directly by Stripe.',
            ],
        ],
        'quantity' => 1,
    ];
    $totalCents += 40;

    // Build metadata for webhook to process
    $metaItems = [];
    foreach ($items as $item) {
        $cls   = sanitize((string)($item['token_class'] ?? ''));
        $units = max(1, (int)($item['units'] ?? 0));
        if (in_array($cls, $allowedPay, true) && $units > 0) {
            $metaItems[] = $cls . ':' . $units;
        }
    }

    // Create Stripe Checkout Session via cURL
    $postData = [
        'mode'                => 'payment',
        'success_url'         => $site . '/wallets/member.html?payment=success',
        'cancel_url'          => $site . '/wallets/member.html?payment=cancelled',
        'client_reference_id' => (string)$member['member_number'],
        'customer_email'      => (string)$member['email'],
        'metadata[member_number]' => (string)$member['member_number'],
        'metadata[member_id]'     => (string)$member['id'],
        'metadata[purchase_type]' => 'gift_pool',
        'metadata[items]'         => implode(',', $metaItems),
    ];

    // Add line items (including images)
    foreach ($lineItems as $i => $li) {
        $postData["line_items[{$i}][price_data][currency]"]                 = $li['price_data']['currency'];
        $postData["line_items[{$i}][price_data][unit_amount]"]              = $li['price_data']['unit_amount'];
        $postData["line_items[{$i}][price_data][product_data][name]"]       = $li['price_data']['product_data']['name'];
        $postData["line_items[{$i}][price_data][product_data][description]"]= $li['price_data']['product_data']['description'];
        if (!empty($li['price_data']['product_data']['images'])) {
            foreach ($li['price_data']['product_data']['images'] as $j => $img) {
                $postData["line_items[{$i}][price_data][product_data][images][{$j}]"] = $img;
            }
        }
        $postData["line_items[{$i}][quantity]"]                             = $li['quantity'];
    }

    // Custom text and branding on the checkout page
    $postData['custom_text[submit][message]'] = 'Your contribution goes directly to the COG$ community trust. Thank you for building something that lasts.';
    $postData['payment_intent_data[description]'] = 'COG$ Community Gift Pool — ' . (string)$member['full_name'] . ' (' . (string)$member['member_number'] . ')';
    $postData['payment_intent_data[statement_descriptor]'] = 'COGS AUSTRALIA';

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('[vault/create-checkout] cURL error: ' . $curlErr);
        apiError('Could not connect to payment processor. Please use bank transfer or PayID.', 502);
    }

    $result = json_decode((string)$response, true);
    if ($httpCode !== 200 || empty($result['url'])) {
        $stripeErr  = (string)($result['error']['message'] ?? 'Unknown Stripe error');
        $stripeCode = (string)($result['error']['code']    ?? '');
        $stripeType = (string)($result['error']['type']    ?? '');
        error_log('[vault/create-checkout] Stripe error HTTP=' . $httpCode . ' type=' . $stripeType . ' code=' . $stripeCode . ' msg=' . $stripeErr);
        error_log('[vault/create-checkout] Full Stripe response: ' . substr((string)$response, 0, 500));
        apiError('Card payment is temporarily unavailable. Please use bank transfer or PayID — both are fee-free.', 503);
    }

    apiSuccess([
        'checkout_url' => (string)$result['url'],
        'session_id'   => (string)($result['id'] ?? ''),
        'total_cents'  => $totalCents,
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/kids-details
   Saves child Name + DOB for each Kids S-NFT purchased.
   Expects JSON: { children: [ {name, dob}, ... ] }
═══════════════════════════════════════════════════════════════ */

function saveKidsDetails(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $children = is_array($body['children'] ?? null) ? $body['children'] : [];
    if (empty($children)) apiError('At least one child is required.');
    if (count($children) > 99) apiError('Maximum 99 children per submission.');

    $gStmt = $db->prepare('SELECT id, member_number, full_name, email FROM snft_memberships WHERE id = ? LIMIT 1');
    $gStmt->execute([(int)$principal['principal_id']]);
    $guardian = $gStmt->fetch();
    if (!$guardian) apiError('Member not found.');

    $mStmt = $db->prepare('SELECT id FROM members WHERE member_number = ? LIMIT 1');
    $mStmt->execute([(string)$guardian['member_number']]);
    $membersId = (int)($mStmt->fetchColumn() ?: $principal['principal_id']);

    $now = nowUtc();
    $saved = 0;
    $registrationIds = [];

    foreach ($children as $child) {
        $name = trim(sanitize((string)($child['name'] ?? '')));
        $dob  = trim(sanitize((string)($child['dob'] ?? '')));
        if ($name === '') continue;

        $dobDate = null;
        if ($dob !== '') {
            $ts = strtotime($dob);
            if ($ts === false) continue;
            $dobDate = date('Y-m-d', $ts);
            $age = (int)((time() - $ts) / (365.25 * 86400));
            if ($age >= 18) continue;
        }

        try {
            $db->prepare(
                "INSERT INTO member_applications
                 (application_type, member_id, guardian_member_id, guardian_full_name, guardian_email,
                  child_full_name, child_dob, guardian_authority_confirmed,
                  full_name, email, application_status, source_page, created_at, updated_at)
                 VALUES ('kids_snft', ?, ?, ?, ?, ?, ?, 1, ?, ?, 'submitted', 'vault_kids_form', ?, ?)"
            )->execute([
                $membersId, $membersId,
                (string)$guardian['full_name'], (string)$guardian['email'],
                $name, $dobDate, $name, (string)$guardian['email'],
                $now, $now,
            ]);
            $registrationIds[] = (int)$db->lastInsertId();
            $saved++;
        } catch (Throwable $e) {
            error_log('[vault/kids-details] insert failed: ' . $e->getMessage());
        }
    }

    if ($saved === 0) apiError('No valid children could be saved. Check the details and try again.');

    recordWalletEvent($db, 'snft_member', (string)$guardian['member_number'], 'kids_details_submitted',
        $saved . ' child record(s) submitted for Kids S-NFT registration.');

    // ── Notify admin that kids ID verification is needed ──────────────────────
    try {
        $adminEmail = MAIL_ADMIN_EMAIL ?: 'members@cogsaustralia.org';
        if ($adminEmail !== '' && strtolower($adminEmail) !== strtolower(MAIL_FROM_EMAIL ?? '')) {
            $childNames = array_filter(array_map(fn($c) => trim(sanitize((string)($c['name'] ?? ''))), $children));
            queueEmail($db, 'snft_member', (int)$guardian['id'], $adminEmail, 'kids_submitted_admin',
                "Kids S-NFT ID verification needed — " . (string)$guardian['full_name'],
                [
                    'full_name'      => (string)$guardian['full_name'],
                    'member_number'  => (string)$guardian['member_number'],
                    'email'          => (string)$guardian['email'],
                    'children_count' => $saved,
                    'children_names' => implode(', ', $childNames),
                ]
            );
        }
    } catch (Throwable $e) {
        error_log('[vault/kids-details] admin email queue failed: ' . $e->getMessage());
    }

    apiSuccess([
        'saved'            => $saved,
        'registration_ids' => $registrationIds,
        'vote_weight'      => calculateVoteWeight($db, $membersId),
        'kids'             => fetchRegisteredKids($db, $membersId),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/cancel-gift-order
   Cancels unpaid D/P/KSNFT reservation lines.
   Accepts: { token_class: "donation_tokens" } or { cancel_all: true }
   Only cancels lines where paid_units = 0 (fully unpaid).
═══════════════════════════════════════════════════════════════ */

function cancelGiftOrder(): void {
    requireMethod('POST');
    $principal = requireAnyUserType(['snft', 'bnft']);
    $db = getDB();
    $body = jsonBody();

    $isBusiness = ($principal['user_type'] ?? '') === 'bnft';
    $cancelAll  = (bool)($body['cancel_all'] ?? false);
    $tokenClass = sanitize((string)($body['token_class'] ?? ''));
    // Kids S-NFT uses vault/cancel-kids-order — its own dedicated cancel pathway.
    $allowedPay = ['donation_tokens', 'pay_it_forward_tokens'];
    $classCodeMap = [
        'donation_tokens'       => 'DONATION_COG',
        'pay_it_forward_tokens' => 'PAY_IT_FORWARD_COG',
    ];

    if (!$cancelAll && !in_array($tokenClass, $allowedPay, true)) {
        apiError('Specify a valid token_class or cancel_all: true.');
    }

    // Look up the member — must resolve BOTH members.id and snft_memberships.member_number
    // because payments may be keyed on either depending on when they were created.
    if ($isBusiness) {
        $mStmt = $db->prepare('SELECT id, abn AS member_number, legal_name AS full_name, email FROM bnft_memberships WHERE id = ? LIMIT 1');
        $mStmt->execute([(int)$principal['principal_id']]);
    } else {
        $mStmt = $db->prepare('SELECT id, member_number, full_name, email FROM members WHERE member_number = ? LIMIT 1');
        $mStmt->execute([(string)$principal['subject_ref']]);
    }
    $member = $mStmt->fetch();
    if (!$member) apiError('Member not found.');
    $membersId    = (int)$member['id'];
    $memberNumber = (string)$member['member_number'];
    $memberName   = (string)($member['full_name'] ?? '');
    $memberEmail  = strtolower(trim((string)($member['email'] ?? '')));

    // Also get snft_memberships.id for payment rows created by createPaymentIntent
    $snftId = $membersId; // default — same for most members
    if (!$isBusiness) {
        $snftStmt = $db->prepare('SELECT id FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $snftStmt->execute([$memberNumber]);
        $snftRow = $snftStmt->fetch();
        if ($snftRow) $snftId = (int)$snftRow['id'];
    }

    $codes = $cancelAll
        ? array_values($classCodeMap)
        : [$classCodeMap[$tokenClass]];

    $cancelled = [];
    $db->beginTransaction();

    try {
        foreach ($codes as $code) {
            $tcStmt = $db->prepare("SELECT id, unit_price_cents FROM token_classes WHERE class_code = ? LIMIT 1");
            $tcStmt->execute([$code]);
            $tc = $tcStmt->fetch();
            $unitCents = (int)($tc['unit_price_cents'] ?? 400);
            $tcId = (int)($tc['id'] ?? 0);

            $label = ['DONATION_COG' => 'Donation COG$', 'PAY_IT_FORWARD_COG' => 'Pay It Forward COG$', 'KIDS_SNFT' => 'Kids S-NFT COG$'][$code] ?? $code;
            $legacyCol = array_search($code, $classCodeMap);

            // ── Path A: reservation_line exists ──────────────────────────────
            $stmt = $db->prepare("
                SELECT mrl.id, mrl.requested_units, mrl.paid_units
                FROM member_reservation_lines mrl
                INNER JOIN token_classes tc ON tc.id = mrl.token_class_id
                WHERE mrl.member_id = ? AND tc.class_code = ? AND mrl.paid_units = 0
                LIMIT 1
            ");
            $stmt->execute([$membersId, $code]);
            $line = $stmt->fetch();

            if ($line) {
                $units = (int)$line['requested_units'];
                $valueDecrement = round(($units * $unitCents) / 100, 2);

                $db->prepare('DELETE FROM member_reservation_lines WHERE id = ?')
                   ->execute([(int)$line['id']]);

                try {
                    $db->prepare("UPDATE payments SET payment_status = 'cancelled', notes = CONCAT(COALESCE(notes,''), ' [Cancelled by member]'), updated_at = UTC_TIMESTAMP() WHERE member_id IN (?,?) AND payment_status = 'pending' AND notes LIKE ? ORDER BY id DESC LIMIT 1")
                       ->execute([$membersId, $snftId, '%' . $label . '%']);
                } catch (Throwable $e) { /* non-fatal */ }

                $cancelled[] = ['class_code' => $code, 'units_cancelled' => $units];
                // Correction applied below after loop
                continue;
            }

            // ── Path B: no reservation_line — cancel ALL pending payments for this
            // class. Use both member IDs to catch rows created by either code path.
            try {
                $payStmt = $db->prepare(
                    "SELECT id, amount_cents, notes FROM payments
                      WHERE member_id IN (?,?) AND payment_status = 'pending'
                        AND notes LIKE ? AND received_at IS NULL
                      ORDER BY id ASC"
                );
                $payStmt->execute([$membersId, $snftId, '%' . $label . '%']);
                $allPays = $payStmt->fetchAll();

                if (!empty($allPays)) {
                    $totalUnitsB  = 0;
                    $totalValueB  = 0.0;
                    $cancelledIds = [];

                    foreach ($allPays as $pay) {
                        $u = 0;
                        if (preg_match('/(\d+)\s*x\s+/i', (string)$pay['notes'], $nm)) {
                            $u = (int)$nm[1];
                        }
                        if ($u < 1 && $unitCents > 0) {
                            $u = (int)round((int)$pay['amount_cents'] / $unitCents);
                        }
                        $totalUnitsB  += max(1, $u);
                        $totalValueB  += round((int)$pay['amount_cents'] / 100, 2);
                        $cancelledIds[] = (int)$pay['id'];
                    }

                    $idList = implode(',', $cancelledIds);
                    $db->exec(
                        "UPDATE payments SET payment_status = 'cancelled',
                                notes = CONCAT(COALESCE(notes,''), ' [Cancelled by member]'),
                                updated_at = UTC_TIMESTAMP()
                          WHERE id IN ({$idList})"
                    );

                    $cancelled[] = ['class_code' => $code, 'units_cancelled' => $totalUnitsB];
                }
                // Whether or not there were pending rows, always correct snft_memberships
                // below to account for any stale increments from prior cancelled intents.
            } catch (Throwable $e) {
                error_log('[cancel-gift-order] path-b payment cancel failed: ' . $e->getMessage());
            }
        }

        // ── Correction pass: recalculate each token column in snft_memberships
        // directly from the payments table rather than trusting delta arithmetic.
        // This handles cases where stale intents were manually cancelled without
        // decrementing snft_memberships, leaving orphaned counts.
        if (!$isBusiness) {
            foreach ($codes as $code) {
                $legacyCol = array_search($code, $classCodeMap);
                if ($legacyCol === false) continue;

                $label = ['DONATION_COG' => 'Donation COG$', 'PAY_IT_FORWARD_COG' => 'Pay It Forward COG$', 'KIDS_SNFT' => 'Kids S-NFT COG$'][$code] ?? $code;
                $tcStmt = $db->prepare("SELECT unit_price_cents FROM token_classes WHERE class_code = ? LIMIT 1");
                $tcStmt->execute([$code]);
                $tc2 = $tcStmt->fetch();
                $unitCents2 = (int)($tc2['unit_price_cents'] ?? 400);

                // Count units still genuinely pending after this cancellation
                $pendingStmt = $db->prepare(
                    "SELECT amount_cents, notes FROM payments
                      WHERE member_id IN (?,?) AND payment_status = 'pending'
                        AND notes LIKE ? AND received_at IS NULL"
                );
                $pendingStmt->execute([$membersId, $snftId, '%' . $label . '%']);
                $pendingRows = $pendingStmt->fetchAll();

                $correctUnits = 0;
                foreach ($pendingRows as $pr) {
                    $u = 0;
                    if (preg_match('/(\d+)\s*x\s+/i', (string)$pr['notes'], $nm)) {
                        $u = (int)$nm[1];
                    }
                    if ($u < 1 && $unitCents2 > 0) {
                        $u = (int)round((int)$pr['amount_cents'] / $unitCents2);
                    }
                    $correctUnits += max(1, $u);
                }

                // Fetch current value and compute delta to fix tokens_total as well
                $curStmt = $db->prepare("SELECT {$legacyCol} FROM snft_memberships WHERE member_number = ? LIMIT 1");
                $curStmt->execute([$memberNumber]);
                $curRow = $curStmt->fetch();
                $currentVal = (int)($curRow[$legacyCol] ?? 0);
                $delta = $currentVal - $correctUnits; // amount to subtract from tokens_total

                if ($delta !== 0 || $currentVal !== $correctUnits) {
                    try {
                        $db->prepare("UPDATE snft_memberships
                                         SET {$legacyCol}   = ?,
                                             tokens_total   = GREATEST(0, tokens_total - ?),
                                             updated_at     = UTC_TIMESTAMP()
                                       WHERE member_number  = ?")
                           ->execute([$correctUnits, max(0, $delta), $memberNumber]);
                    } catch (Throwable $e) {
                        error_log('[cancel-gift-order] correction pass failed for ' . $legacyCol . ': ' . $e->getMessage());
                    }
                }
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Cancellation failed: ' . $e->getMessage(), 500);
    }

    if (empty($cancelled)) {
        apiError('No unpaid orders found to cancel. If you have outstanding orders, try refreshing your vault first.');
    }

    $totalUnits  = array_sum(array_column($cancelled, 'units_cancelled'));
    $subjectType = $isBusiness ? 'bnft_business' : 'snft_member';
    recordWalletEvent($db, $subjectType, $memberNumber, 'gift_order_cancelled',
        'Cancelled ' . $totalUnits . ' unpaid gift pool token(s): ' . implode(', ', array_column($cancelled, 'class_code')));

    try {
        $adminEmail = MAIL_ADMIN_EMAIL ?: 'members@cogsaustralia.org';
        if ($adminEmail !== '' && strtolower($adminEmail) !== strtolower(MAIL_FROM_EMAIL ?? '')) {
            $cancelledSummary = implode(', ', array_map(
                fn($c) => $c['units_cancelled'] . ' × ' . $c['class_code'],
                $cancelled
            ));
            queueEmail($db, 'snft_member', (int)$principal['principal_id'], $adminEmail, 'gift_order_cancelled_admin',
                "Gift order cancelled — " . ($memberName ?: $memberNumber),
                [
                    'full_name'       => $memberName,
                    'member_number'   => $memberNumber,
                    'email'           => $memberEmail,
                    'cancelled_items' => $cancelledSummary,
                    'total_units'     => $totalUnits,
                ]
            );
        }
    } catch (Throwable $e) {
        error_log('[vault/cancel-gift-order] admin email queue failed: ' . $e->getMessage());
    }

    apiSuccess([
        'cancelled'       => $cancelled,
        'total_cancelled' => $totalUnits,
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/update-email
   Member can update their own email address directly.
═══════════════════════════════════════════════════════════════ */

function saveVaultStewardshipAnswers(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $answers     = is_array($body['answers'] ?? null) ? $body['answers'] : [];
    $completedAt = sanitize((string)($body['completed_at'] ?? ''));
    $completedAt = $completedAt !== '' ? gmdate('Y-m-d H:i:s', strtotime($completedAt) ?: time()) : nowUtc();

    $memberId   = (int)$principal['principal_id'];
    $memberRef  = (string)($principal['subject_ref'] ?? '');

    // ── Sanitise answer values ─────────────────────────────────────
    $allowed = [
        'q_intent'        => ['stewardship','community','curious','support'],
        'q_participation' => ['active','moderate','light'],
        'q_risk'          => ['clear','learning'],
    ];
    $clean = [];
    foreach ($allowed as $key => $valid) {
        $val = sanitize((string)($answers[$key] ?? ''));
        if ($val !== '' && in_array($val, $valid, true)) {
            $clean[$key] = $val;
        }
    }

    // ── Update members.meta_json ───────────────────────────────────
    $stmt = $db->prepare('SELECT id, meta_json FROM members WHERE id = ? OR member_number = ? LIMIT 1');
    $stmt->execute([$memberId, $memberRef]);
    $row = $stmt->fetch();

    // ── Update members.meta_json (try/catch so one failure doesn't kill both writes) ──
    try {
        if ($row) {
            $realId = (int)$row['id'];
            $meta   = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
            $meta['vault_stewardship_completed'] = true;
            $meta['vault_stewardship_answers']   = $clean;
            $meta['vault_stewardship_at']        = $completedAt;
            $db->prepare('UPDATE members SET meta_json = ?, updated_at = NOW() WHERE id = ?')
               ->execute([json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $realId]);
        }
    } catch (Throwable $e) {
        error_log('[vault/stewardship-answers] members.meta_json update failed: ' . $e->getMessage());
    }

    // ── Update snft_memberships — primary reliable flag ───────────────────────
    // This is the definitive check used by vault/member stewardship_completed.
    // Uses member_number (subject_ref from session) — always matches.
    try {
        $db->prepare('UPDATE snft_memberships SET vault_stewardship_completed = 1, updated_at = UTC_TIMESTAMP() WHERE member_number = ?')
           ->execute([$memberRef]);
    } catch (Throwable $e) {
        error_log('[vault/stewardship-answers] snft_memberships flag update failed: ' . $e->getMessage());
    }

    // ── Fallback: also set the column on members directly ────────────────────
    try {
        $db->prepare('UPDATE members SET vault_stewardship_completed = 1, updated_at = UTC_TIMESTAMP() WHERE member_number = ?')
           ->execute([$memberRef]);
    } catch (Throwable $e) {
        error_log('[vault/stewardship-answers] members column flag update failed: ' . $e->getMessage());
    }

    // ── Verify at least one write stuck before returning success ─────────────
    $verified = false;
    try {
        $vStmt = $db->prepare('SELECT vault_stewardship_completed FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $vStmt->execute([$memberRef]);
        $vRow = $vStmt->fetch();
        if ($vRow && (int)($vRow['vault_stewardship_completed'] ?? 0) === 1) {
            $verified = true;
        }
    } catch (Throwable $ignored) {}
    if (!$verified) {
        try {
            $vStmt2 = $db->prepare('SELECT meta_json FROM members WHERE member_number = ? LIMIT 1');
            $vStmt2->execute([$memberRef]);
            $vRow2 = $vStmt2->fetch();
            if ($vRow2) {
                $vMeta = json_decode((string)($vRow2['meta_json'] ?? '{}'), true) ?: [];
                if (!empty($vMeta['vault_stewardship_completed'])) {
                    $verified = true;
                }
            }
        } catch (Throwable $ignored) {}
    }
    if (!$verified) {
        error_log('[vault/stewardship-answers] CRITICAL: stewardship flag could not be verified for member_number=' . $memberRef);
        apiError('Stewardship responses could not be saved. Please try again or contact support.', 500);
    }

    // ── Also log to stewardship_attestations if the table exists ──
    // (same table used by the join form — this records the vault-side assessment)
    try {
        // Save individual answers to queryable member_stewardship_responses table
        $db->prepare('DELETE FROM member_stewardship_responses WHERE member_id = ?')->execute([$memberId]);
        foreach ($clean as $qKey => $qVal) {
            $db->prepare(
                'INSERT INTO member_stewardship_responses (member_id, member_number, question_key, answer_value, completed_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$memberId, $memberRef, $qKey, $qVal, $completedAt]);
        }
    } catch (Throwable $e) {
        // Table may not exist yet — non-fatal
        error_log('[vault/stewardship-answers] member_stewardship_responses write: ' . $e->getMessage());
    }

    try {
        $db->prepare(
            'INSERT INTO stewardship_attestations
             (subject_type, subject_id, wallet_ref, module_name, score, total_questions, answers_json, attestation_hash, completed_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            'snft_member',
            $memberId,
            $memberRef,
            'vault_stewardship_v1',
            count($clean),
            count($allowed),
            json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            hash('sha256', json_encode(['ref'=>$memberRef,'answers'=>$clean,'at'=>$completedAt])),
            $completedAt,
        ]);
    } catch (Throwable $e) {
        // Table may not exist yet — non-fatal, flag already verified
        error_log('[vault/stewardship-answers] attestation log skipped: ' . $e->getMessage());
    }

    apiSuccess(['ok' => true, 'stewardship_completed' => true]);
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/create-checkout
   Creates a Stripe Checkout Session for gift pool / KSNFT purchases.
   Expects JSON body: { items: [ {token_class, units}, ... ] }
   Returns: { checkout_url: "https://checkout.stripe.com/..." }
   Requires STRIPE_SECRET_KEY in .env (sk_live_... or sk_test_...)
═══════════════════════════════════════════════════════════════ */

function updateMemberEmail(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $newEmail = trim(sanitize((string)($body['email'] ?? '')));
    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        apiError('Please provide a valid email address.');
    }

    $memberNumber = (string)$principal['subject_ref'];
    $memberId = (int)$principal['principal_id'];

    // Update in snft_memberships
    $db->prepare('UPDATE snft_memberships SET email = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
       ->execute([$newEmail, $memberId]);

    // Also update in members table if it exists
    try {
        $db->prepare('UPDATE members SET email = ?, updated_at = UTC_TIMESTAMP() WHERE member_number = ?')
           ->execute([$newEmail, $memberNumber]);
    } catch (Throwable $e) {}

    recordWalletEvent($db, 'snft_member', $memberNumber, 'email_updated',
        'Email address updated to ' . $newEmail);

    apiSuccess(['email' => $newEmail]);
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/change-request
   Member requests a change to mobile or address.
   Stored as a wallet event for admin review.
   Accepts: { field: "mobile"|"address", new_value: "..." }
═══════════════════════════════════════════════════════════════ */

function submitChangeRequest(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $field = sanitize((string)($body['field'] ?? ''));
    $newValue = trim(sanitize((string)($body['new_value'] ?? '')));

    if (!in_array($field, ['mobile', 'address'], true)) {
        apiError('Invalid field. Only mobile and address changes can be requested.');
    }
    if ($newValue === '') {
        apiError('Please provide the new ' . $field . ' details.');
    }

    $memberNumber = (string)$principal['subject_ref'];

    // Record as a wallet event (admin can see in audit log)
    recordWalletEvent($db, 'snft_member', $memberNumber, 'change_request',
        'Change request for ' . $field . ': ' . $newValue);

    // Also try to insert into wallet_messages as a notice for admin
    try {
        $db->prepare(
            "INSERT INTO wallet_messages
             (audience, message_key, audience_scope, subject, summary, body, message_type, priority, status, sent_at, created_at, updated_at)
             VALUES ('all', ?, 'all', ?, ?, ?, 'support', 'high', 'sent', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            'change_req_' . $memberNumber . '_' . $field,
            'Member Change Request — ' . ucfirst($field),
            'Member ' . $memberNumber . ' requested ' . $field . ' change',
            'Member: ' . $memberNumber . "\nField: " . $field . "\nNew value: " . $newValue,
        ]);
    } catch (Throwable $e) {
        // Non-fatal — event log is the primary record
    }

    apiSuccess(['field' => $field, 'status' => 'submitted']);

}

/* ═══════════════════════════════════════════════════════════════
   POST vault/propose-poll
   Partners initiate a formal Partners Poll through the System.
   Requires: { title: string, body: string }
   The initiation threshold is the lesser of 10 active paid Partners or 1% of all active paid Partners.
   There is no separate seconding stage.
═══════════════════════════════════════════════════════════════ */


function proposePoll(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db   = getDB();
    $body = jsonBody();

    if (!hasPartnersPollInitiationSchema($db)) {
        apiError('The Partners Poll initiation schema is not available on this environment.', 503);
    }

    $title = trim(sanitize((string)($body['title'] ?? '')));
    $desc  = trim(sanitize((string)($body['body']  ?? $body['description'] ?? '')));

    if (strlen($title) < 5)   apiError('Please enter a poll title of at least 5 characters.');
    if (strlen($title) > 255) apiError('Poll title must be 255 characters or fewer.');
    if (strlen($desc)  < 10)  apiError('Please enter a description of at least 10 characters.');

    $subjectRef = (string)($principal['subject_ref'] ?? '');
    $memberStmt = $db->prepare('SELECT id, member_number, full_name, signup_payment_status, is_active FROM members WHERE member_type = ? AND (member_number = ? OR id = ?) LIMIT 1');
    $memberStmt->execute(['personal', $subjectRef, (int)$principal['principal_id']]);
    $member = $memberStmt->fetch();
    if (!$member) apiError('Partner record not found.', 404);
    if ((string)($member['signup_payment_status'] ?? 'pending') !== 'paid' || (int)($member['is_active'] ?? 0) !== 1) {
        apiError('Only active paid Partners can initiate a Partners Poll.', 403);
    }

    $memberId = (int)$member['id'];
    $memberNumber = (string)$member['member_number'];
    $now = nowUtc();
    $proposalKey = 'partners-poll-' . gmdate('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
    $eligibleCount = countEligibleInitiatingPartners($db);
    $threshold = partnersPollInitiationThreshold($eligibleCount);

    $activeStmt = $db->prepare("SELECT COUNT(*) FROM proposal_register WHERE origin_member_id = ? AND proposal_type = 'governance' AND status IN ('submitted','sponsored','open')");
    $activeStmt->execute([$memberId]);
    if ((int)$activeStmt->fetchColumn() >= 3) {
        apiError('You already have 3 active poll initiations. Wait for one to progress before submitting another.');
    }

    $db->beginTransaction();
    try {
        $insert = $db->prepare("INSERT INTO proposal_register (proposal_key, title, proposal_type, summary, body, origin_type, origin_member_id, linked_poll_id, status, initiation_threshold, eligible_partner_count, initiation_reached_at, created_at, updated_at) VALUES (?, ?, 'governance', ?, ?, 'partner', ?, NULL, 'submitted', ?, ?, NULL, ?, ?)");
        $insert->execute([$proposalKey, $title, mb_substr($desc, 0, 280), $desc, $memberId, $threshold, $eligibleCount, $now, $now]);
        $proposalId = (int)$db->lastInsertId();

        $join = $db->prepare('INSERT INTO partners_poll_initiators (proposal_register_id, member_id, confirmed_at, created_at) VALUES (?, ?, ?, ?)');
        $join->execute([$proposalId, $memberId, $now, $now]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[vault/propose-poll] insert failed: ' . $e->getMessage());
        apiError('Could not save your Partners Poll initiation. Please try again.');
    }

    $proposal = ensurePartnersPollInitiationOpen($db, $proposalId);
    recordWalletEvent($db, 'snft_member', $memberNumber, 'partners_poll_initiated',
        'Partners Poll initiated: "' . mb_substr($title, 0, 80) . '" — ' . (int)$proposal['initiator_count'] . ' of ' . (int)$proposal['initiation_threshold'] . ' initiators recorded.');

    apiSuccess([
        'proposal_register_id' => $proposalId,
        'status' => (string)($proposal['status'] ?? 'submitted'),
        'initiator_count' => (int)($proposal['initiator_count'] ?? 1),
        'initiation_threshold' => (int)($proposal['initiation_threshold'] ?? $threshold),
        'message' => ((int)($proposal['linked_poll_id'] ?? 0) > 0)
            ? 'The initiation threshold has been met. The Partners Poll has been opened in the system.'
            : 'Your initiation draft has been recorded. Other Partners can now join the initiation group until the threshold is met.',
    ]);
}

function joinPollInitiation(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    if (!hasPartnersPollInitiationSchema($db)) {
        apiError('The Partners Poll initiation schema is not available on this environment.', 503);
    }

    $proposalId = (int)($body['proposal_register_id'] ?? 0);
    if ($proposalId < 1) apiError('A poll initiation record is required.');

    $subjectRef = (string)($principal['subject_ref'] ?? '');
    $memberStmt = $db->prepare('SELECT id, member_number, signup_payment_status, is_active FROM members WHERE member_type = ? AND (member_number = ? OR id = ?) LIMIT 1');
    $memberStmt->execute(['personal', $subjectRef, (int)$principal['principal_id']]);
    $member = $memberStmt->fetch();
    if (!$member) apiError('Partner record not found.', 404);
    if ((string)($member['signup_payment_status'] ?? 'pending') !== 'paid' || (int)($member['is_active'] ?? 0) !== 1) {
        apiError('Only active paid Partners can join a Partners Poll initiation group.', 403);
    }

    $proposalStmt = $db->prepare("SELECT id, title, status, linked_poll_id FROM proposal_register WHERE id = ? AND proposal_type = 'governance' LIMIT 1");
    $proposalStmt->execute([$proposalId]);
    $proposalRow = $proposalStmt->fetch();
    if (!$proposalRow) apiError('Poll initiation not found.', 404);
    if ((int)($proposalRow['linked_poll_id'] ?? 0) > 0 || (string)($proposalRow['status'] ?? '') === 'open') {
        apiError('This Partners Poll has already been opened.');
    }
    if (!in_array((string)($proposalRow['status'] ?? ''), ['submitted','sponsored'], true)) {
        apiError('This initiation record is not available for joining.');
    }

    $now = nowUtc();
    try {
        $db->prepare('INSERT INTO partners_poll_initiators (proposal_register_id, member_id, confirmed_at, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$proposalId, (int)$member['id'], $now, $now]);
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            apiError('You have already joined this initiation group.');
        }
        throw $e;
    }

    $proposal = ensurePartnersPollInitiationOpen($db, $proposalId);
    recordWalletEvent($db, 'snft_member', (string)$member['member_number'], 'partners_poll_initiation_joined',
        'Joined Partners Poll initiation: "' . mb_substr((string)($proposalRow['title'] ?? ''), 0, 80) . '"');

    apiSuccess([
        'proposal_register_id' => $proposalId,
        'status' => (string)($proposal['status'] ?? 'submitted'),
        'initiator_count' => (int)($proposal['initiator_count'] ?? 0),
        'initiation_threshold' => (int)($proposal['initiation_threshold'] ?? 1),
        'linked_poll_id' => (int)($proposal['linked_poll_id'] ?? 0),
        'message' => ((int)($proposal['linked_poll_id'] ?? 0) > 0)
            ? 'Threshold met. The Partners Poll is now open in the system.'
            : 'You have joined the initiation group for this Partners Poll.',
    ]);
}

function withdrawPollInitiation(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    if (!hasPartnersPollInitiationSchema($db)) {
        apiError('The Partners Poll initiation schema is not available on this environment.', 503);
    }

    $proposalId = (int)($body['proposal_register_id'] ?? 0);
    if ($proposalId < 1) apiError('A poll initiation record is required.');

    $subjectRef = (string)($principal['subject_ref'] ?? '');
    $memberStmt = $db->prepare('SELECT id, member_number FROM members WHERE member_type = ? AND (member_number = ? OR id = ?) LIMIT 1');
    $memberStmt->execute(['personal', $subjectRef, (int)$principal['principal_id']]);
    $member = $memberStmt->fetch();
    if (!$member) apiError('Partner record not found.', 404);

    $proposalStmt = $db->prepare("SELECT id, title, status, linked_poll_id FROM proposal_register WHERE id = ? AND proposal_type = 'governance' LIMIT 1");
    $proposalStmt->execute([$proposalId]);
    $proposalRow = $proposalStmt->fetch();
    if (!$proposalRow) apiError('Poll initiation not found.', 404);
    if ((int)($proposalRow['linked_poll_id'] ?? 0) > 0 || (string)($proposalRow['status'] ?? '') === 'open') {
        apiError('This Partners Poll has already opened and can no longer be withdrawn from initiation.');
    }

    $db->prepare('DELETE FROM partners_poll_initiators WHERE proposal_register_id = ? AND member_id = ?')
       ->execute([$proposalId, (int)$member['id']]);
    ensurePartnersPollInitiationOpen($db, $proposalId);
    recordWalletEvent($db, 'snft_member', (string)$member['member_number'], 'partners_poll_initiation_withdrawn',
        'Withdrew from Partners Poll initiation: "' . mb_substr((string)($proposalRow['title'] ?? ''), 0, 80) . '"');

    apiSuccess([
        'proposal_register_id' => $proposalId,
        'status' => 'submitted',
        'message' => 'You have withdrawn from this Partners Poll initiation group.',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: participation
// GET  vault/participation — return current participation selections
// POST vault/participation — save participation areas (mandatory first-entry)
// ═════════════════════════════════════════════════════════════════════════════
function vaultParticipation(): void
{
    $principal = requireAuth('snft');
    $db        = getDB();

    $memberId  = (int)$principal['principal_id'];
    $subjectRef = (string)($principal['subject_ref'] ?? '');

    // Resolve member
    $stmt = $db->prepare(
        'SELECT id, member_number, participation_completed, participation_answers
         FROM members
         WHERE member_type = ? AND (member_number = ? OR id = ?)
         LIMIT 1'
    );
    $stmt->execute(['personal', $subjectRef, $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) apiError('Partner record not found.', 404);

    $resolvedId = (int)$member['id'];
    $mn         = (string)($member['member_number'] ?? '');

    // ── GET ───────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $areas = [];
        if (!empty($member['participation_answers'])) {
            $decoded = is_string($member['participation_answers'])
                ? json_decode($member['participation_answers'], true)
                : $member['participation_answers'];
            $areas = is_array($decoded) ? $decoded : [];
        }
        apiSuccess([
            'participation_completed' => (bool)$member['participation_completed'],
            'participation_areas'     => $areas,
        ]);
        return;
    }

    // ── POST ──────────────────────────────────────────────────────────────────
    requireMethod('POST');
    $body = jsonBody();

    // Validate areas
    $validAreas = [
        'operations_oversight',
        'governance_polls',
        'esg_proxy_voting',
        'first_nations',
        'community_projects',
        'technology_blockchain',
        'financial_oversight',
        'place_based_decisions',
        'education_outreach',
    ];
    $submitted = $body['areas'] ?? [];
    if (!is_array($submitted) || count($submitted) === 0) {
        apiError('At least one participation area must be selected.');
    }
    $areas = array_values(array_filter($submitted, fn($a) => in_array($a, $validAreas, true)));
    if (count($areas) === 0) {
        apiError('No valid participation areas submitted.');
    }
    $acknowledged = !empty($body['operator_acknowledged']);
    if (!$acknowledged) {
        apiError('Operator acknowledgement is required.');
    }

    $now           = gmdate('Y-m-d H:i:s');
    $answersJson   = json_encode($areas, JSON_UNESCAPED_UNICODE);
    $alreadyDone   = (bool)$member['participation_completed'];

    try {
        $upd = $db->prepare(
            'UPDATE members
             SET participation_answers      = ?,
                 participation_completed    = 1,
                 participation_completed_at = COALESCE(participation_completed_at, ?),
                 updated_at                 = ?
             WHERE id = ?'
        );
        $upd->execute([$answersJson, $now, $now, $resolvedId]);
    } catch (Throwable $e) {
        error_log('[vault/participation] save failed: ' . $e->getMessage());
        apiError('Could not save your participation record. Please try again.', 500);
    }

    if (!$alreadyDone) {
        recordWalletEvent($db, 'snft_member', $mn, 'participation_recorded',
            'Partner participation areas recorded: ' . implode(', ', $areas));
    }

    apiSuccess([
        'ok'                      => true,
        'participation_completed' => true,
        'participation_areas'     => $areas,
        'first_submission'        => !$alreadyDone,
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   KIDS S-NFT ORDER PATHWAY — dedicated functions
   Separate from the Donation/PIF gift pool pathway.
   payment_type = 'kids_snft' (distinct from 'adjustment').
   Kids tokens are incremented on order, decremented on cancel.
═══════════════════════════════════════════════════════════════ */

/**
 * Fetch pending Kids S-NFT orders for a personal member.
 * Reads payment_type = 'kids_snft' rows, keyed on both members.id
 * and snft_memberships.id to handle dual-ID writes.
 */
function fetchKidsPendingOrders(PDO $db, int $membersId, int $snftId): array {
    $out = [];
    try {
        $ids = array_unique(array_filter([$membersId, $snftId]));
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id, external_reference, amount_cents, notes, created_at
               FROM payments
              WHERE member_id IN ({$placeholders})
                AND payment_type = 'kids_snft'
                AND payment_status = 'pending'
                AND received_at IS NULL
              ORDER BY id ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $cents = (int)$row['amount_cents'];
            $units = 0;
            if (preg_match('/(\d+)\s*x\s+/i', (string)$row['notes'], $nm)) {
                $units = (int)$nm[1];
            }
            if ($units < 1 && $cents > 0) $units = (int)round($cents / 100); // $1 each
            $out[] = [
                'payment_id' => (int)$row['id'],
                'reference'  => (string)($row['external_reference'] ?? ''),
                'units'      => $units,
                'amount'     => round($cents / 100, 2),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('[fetchKidsPendingOrders] ' . $e->getMessage());
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/kids-order
   Creates a pending Kids S-NFT payment record.
   Increments kids_tokens in snft_memberships immediately (on order,
   not on payment) so the vault shows the pending count.
   KYC verified status enforced server-side.
═══════════════════════════════════════════════════════════════ */
function createKidsOrder(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();
    $body = jsonBody();

    $units  = max(1, (int)($body['units'] ?? 0));
    $agreed = (bool)($body['tax_disclaimer_agreed'] ?? false);

    if ($units < 1)  apiError('At least 1 Kids S-NFT is required.');
    if (!$agreed)    apiError('You must agree to the tax disclaimer before proceeding.');

    // KYC gate — must be verified before ordering
    $kycStmt = $db->prepare('SELECT id, member_number, full_name, email, kyc_status FROM snft_memberships WHERE id = ? LIMIT 1');
    $kycStmt->execute([(int)$principal['principal_id']]);
    $member = $kycStmt->fetch();
    if (!$member) apiError('Member not found.');
    if ((string)($member['kyc_status'] ?? '') !== 'verified') {
        apiError('Identity verification is required before ordering Kids S-NFTs. Please complete Medicare card verification first.', 403);
    }

    // Look up price ($1 per kS-NFT)
    $tcStmt = $db->prepare("SELECT unit_price_cents, display_name FROM token_classes WHERE class_code = 'KIDS_SNFT' AND is_active = 1 LIMIT 1");
    $tcStmt->execute();
    $tc = $tcStmt->fetch();
    if (!$tc) apiError('Kids S-NFT token class not found or inactive.');

    $unitCents   = (int)$tc['unit_price_cents'];
    $totalCents  = $unitCents * $units;
    $totalAmount = round($totalCents / 100, 2);
    $className   = (string)$tc['display_name'];

    $ref = 'KIDS-' . strtoupper(substr(hash('sha256', $member['member_number'] . $units . time()), 0, 8));

    $db->beginTransaction();
    try {
        // Insert pending payment with distinct type 'kids_snft'
        $db->prepare(
            "INSERT INTO payments (member_id, payment_type, amount_cents, currency_code, payment_status, external_reference, notes, created_at, updated_at)
             VALUES (?, 'kids_snft', ?, 'AUD', 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            (int)$member['id'],
            $totalCents,
            $ref,
            "Kids S-NFT order: {$units} x {$className}. Reference: {$ref}",
        ]);

        // Increment kids_tokens immediately so vault shows pending count
        $db->prepare(
            'UPDATE snft_memberships SET kids_tokens = kids_tokens + ?, tokens_total = tokens_total + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$units, $units, (int)$member['id']]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[kids-order] ' . $e->getMessage());
        apiError('Could not record order. Please try again.', 500);
    }

    // Queue payment instructions email
    $payId       = (string)env('BANK_PAYID',    'members@cogsaustralia.org');
    $bankName    = (string)env('BANK_NAME',     'COG$ Foundation Account');
    $bankBSB     = (string)env('BANK_BSB',      'BSB on request');
    $bankAccount = (string)env('BANK_ACCOUNT',  'Account on request');
    try {
        queueEmail($db, 'snft_member', (int)$member['id'], $member['email'],
            'payment_intent_member',
            "Kids S-NFT Order — {$ref}",
            [
                'full_name'     => $member['full_name'],
                'email'         => $member['email'],
                'member_number' => $member['member_number'],
                'token_class'   => $className,
                'units'         => $units,
                'amount'        => number_format($totalAmount, 2),
                'reference'     => $ref,
                'pay_id'        => $payId,
                'bank_name'     => $bankName,
                'bank_bsb'      => $bankBSB,
                'bank_account'  => $bankAccount,
                'is_donation'   => false,
            ]
        );
        $adminEmail = MAIL_ADMIN_EMAIL ?: 'members@cogsaustralia.org';
        queueEmail($db, 'snft_member', (int)$member['id'], $adminEmail,
            'payment_intent_admin',
            "Kids S-NFT order — {$ref} — {$member['full_name']}",
            [
                'full_name'     => $member['full_name'],
                'email'         => $member['email'],
                'member_number' => $member['member_number'],
                'token_class'   => $className,
                'units'         => $units,
                'amount'        => number_format($totalAmount, 2),
                'reference'     => $ref,
                'pay_id'        => $payId,
                'bank_name'     => $bankName,
                'bank_bsb'      => $bankBSB,
                'bank_account'  => $bankAccount,
            ]
        );
    } catch (Throwable $e) {
        error_log('[kids-order] email queue failed: ' . $e->getMessage());
    }

    recordWalletEvent($db, 'snft_member', (string)$member['member_number'], 'kids_order_created',
        "Kids S-NFT order: {$units} x {$className} = \${$totalAmount}. Ref: {$ref}");

    apiSuccess([
        'reference'  => $ref,
        'units'      => $units,
        'amount'     => $totalAmount,
        'pay_id'     => $payId,
        'recorded_at'=> nowUtc(),
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   POST vault/cancel-kids-order
   Cancels pending Kids S-NFT orders (payment_type = 'kids_snft').
   Recalculates kids_tokens from the payments table after cancel
   so orphaned counts from any prior stale rows are also corrected.
═══════════════════════════════════════════════════════════════ */
function cancelKidsOrder(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db = getDB();

    // Resolve both member IDs
    $mStmt = $db->prepare('SELECT id, member_number, full_name FROM members WHERE member_number = ? LIMIT 1');
    $mStmt->execute([(string)$principal['subject_ref']]);
    $member = $mStmt->fetch();
    if (!$member) apiError('Member not found.');
    $membersId    = (int)$member['id'];
    $memberNumber = (string)$member['member_number'];

    $snftId = $membersId;
    $snftStmt = $db->prepare('SELECT id FROM snft_memberships WHERE member_number = ? LIMIT 1');
    $snftStmt->execute([$memberNumber]);
    $snftRow = $snftStmt->fetch();
    if ($snftRow) $snftId = (int)$snftRow['id'];

    $db->beginTransaction();
    try {
        // Find all pending kids_snft rows for this member
        $payStmt = $db->prepare(
            "SELECT id, amount_cents, notes FROM payments
              WHERE member_id IN (?,?) AND payment_type = 'kids_snft'
                AND payment_status = 'pending' AND received_at IS NULL
              ORDER BY id ASC"
        );
        $payStmt->execute([$membersId, $snftId]);
        $rows = $payStmt->fetchAll();

        if (empty($rows)) {
            $db->rollBack();
            apiError('No pending Kids S-NFT orders found to cancel.');
        }

        $totalUnits = 0;
        $cancelIds  = [];
        foreach ($rows as $r) {
            $u = 0;
            if (preg_match('/(\d+)\s*x\s+/i', (string)$r['notes'], $nm)) $u = (int)$nm[1];
            if ($u < 1) $u = (int)round((int)$r['amount_cents'] / 100);
            $totalUnits += max(1, $u);
            $cancelIds[] = (int)$r['id'];
        }

        $idList = implode(',', $cancelIds);
        $db->exec(
            "UPDATE payments SET payment_status = 'cancelled',
                    notes = CONCAT(COALESCE(notes,''), ' [Cancelled by member]'),
                    updated_at = UTC_TIMESTAMP()
              WHERE id IN ({$idList})"
        );

        // Correction pass: set kids_tokens to exact count of still-pending orders
        // (handles any orphaned increments from prior stale rows)
        $stillPendingStmt = $db->prepare(
            "SELECT amount_cents, notes FROM payments
              WHERE member_id IN (?,?) AND payment_type = 'kids_snft'
                AND payment_status = 'pending' AND received_at IS NULL"
        );
        $stillPendingStmt->execute([$membersId, $snftId]);
        $correctUnits = 0;
        foreach ($stillPendingStmt->fetchAll() as $pr) {
            $u = 0;
            if (preg_match('/(\d+)\s*x\s+/i', (string)$pr['notes'], $nm)) $u = (int)$nm[1];
            if ($u < 1) $u = (int)round((int)$pr['amount_cents'] / 100);
            $correctUnits += max(1, $u);
        }

        $curStmt = $db->prepare('SELECT kids_tokens FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $curStmt->execute([$memberNumber]);
        $curRow = $curStmt->fetch();
        $currentKids = (int)($curRow['kids_tokens'] ?? 0);
        $delta = $currentKids - $correctUnits;

        $db->prepare(
            'UPDATE snft_memberships SET kids_tokens = ?, tokens_total = GREATEST(0, tokens_total - ?), updated_at = UTC_TIMESTAMP() WHERE member_number = ?'
        )->execute([$correctUnits, max(0, $delta), $memberNumber]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[cancel-kids-order] ' . $e->getMessage());
        apiError('Cancellation failed: ' . $e->getMessage(), 500);
    }

    recordWalletEvent($db, 'snft_member', $memberNumber, 'kids_order_cancelled',
        "Cancelled {$totalUnits} pending Kids S-NFT order(s).");

    apiSuccess([
        'units_cancelled' => $totalUnits,
        'kids_tokens_now' => $correctUnits,
    ]);
}

/* ── PROPOSAL VOTE ──────────────────────────────────────────────────────────── */
function voteOnProposal(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db   = getDB();
    $body = jsonBody();

    $proposalId = (int)($body['proposal_id'] ?? 0);
    $choice     = strtolower(trim((string)($body['choice'] ?? '')));
    $note       = trim((string)($body['note'] ?? ''));

    if ($proposalId < 1) apiError('proposal_id required.');
    if (!in_array($choice, ['yes', 'no', 'maybe'], true)) apiError('choice must be yes, no, or maybe.');

    // Resolve member legacy id
    $subjectRef = (string)$principal['subject_ref'];
    $mStmt = $db->prepare('SELECT id FROM snft_memberships WHERE member_number = ? LIMIT 1');
    $mStmt->execute([$subjectRef]);
    $memberId = (int)($mStmt->fetchColumn() ?: 0);
    if ($memberId < 1) apiError('Member record not found.', 404);

    // Verify proposal is open
    $pStmt = $db->prepare("SELECT id, status FROM vote_proposals WHERE id = ? LIMIT 1");
    $pStmt->execute([$proposalId]);
    $proposal = $pStmt->fetch();
    if (!$proposal) apiError('Proposal not found.', 404);
    if ((string)$proposal['status'] !== 'open') apiError('This proposal is not currently open for responses.');

    // Upsert response
    $db->prepare("INSERT INTO vote_proposal_responses (proposal_id, member_id, response_value, response_note, submitted_at)
                  VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                  ON DUPLICATE KEY UPDATE response_value = VALUES(response_value), response_note = VALUES(response_note), submitted_at = UTC_TIMESTAMP()")
       ->execute([$proposalId, $memberId, $choice, $note ?: null]);

    // Return fresh tally
    $tStmt = $db->prepare("SELECT response_value, COUNT(*) AS votes FROM vote_proposal_responses WHERE proposal_id = ? GROUP BY response_value");
    $tStmt->execute([$proposalId]);
    $tally = []; $total = 0;
    foreach ($tStmt->fetchAll() as $row) {
        $tally[(string)$row['response_value']] = (int)$row['votes'];
        $total += (int)$row['votes'];
    }

    apiSuccess(['voted' => true, 'choice' => $choice, 'tally' => $tally, 'total_votes' => $total]);
}

/* ── PARTNER OPERATIONS — AREA FEED ─────────────────────────────────────────
   Shared area noticeboard: enrolled Partners see each other's posts.
   Admin broadcasts also appear. Author names shown only to admin.
   Partners see each other as "A Partner" — anonymous peer display.
────────────────────────────────────────────────────────────────────────────── */

function resolveOpMember(PDO $db, array $principal): array {
    $mStmt = $db->prepare(
        'SELECT id, participation_answers FROM members
         WHERE member_type = ? AND (member_number = ? OR id = ?) LIMIT 1'
    );
    $mStmt->execute(['personal', (string)$principal['subject_ref'], (int)$principal['principal_id']]);
    $member = $mStmt->fetch();
    if (!$member) apiError('Member not found.', 404);
    $areas = [];
    if (!empty($member['participation_answers'])) {
        $dec = json_decode((string)$member['participation_answers'], true);
        if (is_array($dec)) $areas = $dec;
    }
    return ['id' => (int)$member['id'], 'areas' => $areas];
}

function handlePartnerOpThreads(): void {
    $principal = requireAuth('snft');
    $db        = getDB();
    $method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $info      = resolveOpMember($db, $principal);
    $memberId  = $info['id'];
    $areas     = $info['areas'];

    if ($method === 'POST') {
        $body    = jsonBody();
        $areaKey = trim((string)($body['area_key'] ?? ''));
        $subject = trim((string)($body['subject']  ?? ''));
        $msg     = trim((string)($body['body']     ?? ''));

        if (!$areaKey)                              apiError('area_key required.');
        if (!$subject)                              apiError('Subject is required.');
        if (!$msg)                                  apiError('Message body is required.');
        if (strlen($msg) > 4000)                    apiError('Message too long (max 4000 characters).');
        if (!in_array($areaKey, $areas, true))      apiError('You are not enrolled in this area.', 403);

        // Insert the thread
        $db->prepare(
            "INSERT INTO partner_op_threads
                (area_key, direction, subject, body, status, initiated_by_member_id, created_at, updated_at)
             VALUES (?, 'inbound', ?, ?, 'open', ?, NOW(), NOW())"
        )->execute([$areaKey, $subject, $msg, $memberId]);
        $threadId = (int)$db->lastInsertId();

        // Seed read-receipt rows for ALL enrolled Partners in this area EXCEPT author
        // Uses JSON_CONTAINS on participation_answers
        $enrolled = [];
        try {
            $eStmt = $db->prepare(
                "SELECT id FROM members
                 WHERE participation_completed = 1
                   AND is_active = 1
                   AND id != ?
                   AND JSON_CONTAINS(participation_answers, JSON_QUOTE(?), '$')"
            );
            $eStmt->execute([$memberId, $areaKey]);
            $enrolled = $eStmt->fetchAll();
        } catch (Throwable) {}

        if ($enrolled) {
            $ins = $db->prepare(
                'INSERT IGNORE INTO partner_op_broadcast_reads
                    (thread_id, member_id, delivered_at)
                 VALUES (?, ?, NOW())'
            );
            foreach ($enrolled as $e) {
                $ins->execute([$threadId, (int)$e['id']]);
            }
        }

        apiSuccess(['created' => true, 'thread_id' => $threadId]);
    }

    // GET — return area feed grouped by area
    if (!$areas) { apiSuccess(['areas' => []]); }

    $ph = implode(',', array_fill(0, count($areas), '?'));

    // All inbound (partner-posted) threads in enrolled areas
    $thStmt = $db->prepare(
        "SELECT t.id, t.area_key, t.subject, t.body, t.status,
                t.reply_count, t.last_reply_at, t.created_at,
                t.initiated_by_member_id,
                (t.initiated_by_member_id = ?) AS is_mine
         FROM partner_op_threads t
         WHERE t.direction = 'inbound'
           AND t.area_key IN ({$ph})
           AND t.status != 'archived'
         ORDER BY t.created_at DESC
         LIMIT 100"
    );
    $thStmt->execute(array_merge([$memberId], $areas));
    $allThreads = $thStmt->fetchAll();

    // Admin broadcasts delivered to this member
    $bcStmt = $db->prepare(
        "SELECT t.id, t.area_key, t.subject, t.body, t.created_at,
                br.read_at
         FROM partner_op_threads t
         INNER JOIN partner_op_broadcast_reads br
                 ON br.thread_id = t.id AND br.member_id = ?
         WHERE t.direction = 'broadcast'
           AND t.area_key IN ({$ph})
         ORDER BY t.created_at DESC
         LIMIT 50"
    );
    $bcStmt->execute(array_merge([$memberId], $areas));
    $broadcasts = $bcStmt->fetchAll();

    // Read state for partner threads (via broadcast_reads — seeded on post)
    $readMap = [];
    if ($allThreads) {
        $tIds = array_map(fn($r) => (int)$r['id'], $allThreads);
        $rph  = implode(',', array_fill(0, count($tIds), '?'));
        try {
            $rdStmt = $db->prepare(
                "SELECT thread_id, read_at FROM partner_op_broadcast_reads
                 WHERE thread_id IN ({$rph}) AND member_id = ?"
            );
            $rdStmt->execute(array_merge($tIds, [$memberId]));
            foreach ($rdStmt->fetchAll() as $r) {
                $readMap[(int)$r['thread_id']] = $r['read_at'];
            }
        } catch (Throwable) {}
    }

    // Replies for all threads
    $replyMap = [];
    if ($allThreads) {
        $tIds = array_map(fn($r) => (int)$r['id'], $allThreads);
        $rph  = implode(',', array_fill(0, count($tIds), '?'));
        $rpStmt = $db->prepare(
            "SELECT r.thread_id, r.body, r.direction,
                    r.from_member_id, r.created_at,
                    (r.from_member_id = ?) AS is_mine
             FROM partner_op_replies r
             WHERE r.thread_id IN ({$rph})
             ORDER BY r.created_at ASC"
        );
        $rpStmt->execute(array_merge([$memberId], $tIds));
        foreach ($rpStmt->fetchAll() as $r) {
            $replyMap[(int)$r['thread_id']][] = [
                'body'       => $r['body'],
                'direction'  => $r['direction'],
                'is_mine'    => (bool)$r['is_mine'],
                'created_at' => $r['created_at'],
            ];
        }
        // Mark outbound (admin) replies as read for this member's own threads
        $myIds = array_values(array_filter($tIds, function($id) use ($allThreads, $memberId) {
            foreach ($allThreads as $th) {
                if ((int)$th['id'] === $id && (int)$th['initiated_by_member_id'] === $memberId) return true;
            }
            return false;
        }));
        if ($myIds) {
            $mrph = implode(',', array_fill(0, count($myIds), '?'));
            try {
                $db->prepare(
                    "UPDATE partner_op_replies SET read_at = NOW()
                     WHERE thread_id IN ({$mrph}) AND direction = 'outbound' AND read_at IS NULL"
                )->execute($myIds);
            } catch (Throwable) {}
        }
    }

    // Group by area
    $byArea = [];
    foreach ($areas as $ak) { $byArea[$ak] = ['broadcasts' => [], 'threads' => []]; }

    foreach ($broadcasts as $bc) {
        $ak = (string)$bc['area_key'];
        if (isset($byArea[$ak])) $byArea[$ak]['broadcasts'][] = $bc;
    }

    foreach ($allThreads as $th) {
        $ak      = (string)$th['area_key'];
        $tid     = (int)$th['id'];
        $is_mine = (bool)$th['is_mine'];
        // Author display: own posts show "You", others show "A Partner"
        $entry = [
            'id'           => $tid,
            'area_key'     => $ak,
            'subject'      => $th['subject'],
            'body'         => $th['body'],
            'status'       => $th['status'],
            'reply_count'  => (int)$th['reply_count'],
            'last_reply_at'=> $th['last_reply_at'],
            'created_at'   => $th['created_at'],
            'is_mine'      => $is_mine,
            'author'       => $is_mine ? 'You' : 'A Partner',
            'read_at'      => $is_mine ? null : ($readMap[$tid] ?? null),
            'replies'      => $replyMap[$tid] ?? [],
        ];
        if (isset($byArea[$ak])) $byArea[$ak]['threads'][] = $entry;
    }

    $out = [];
    foreach ($byArea as $ak => $data) { $out[] = ['area_key' => $ak] + $data; }
    apiSuccess(['areas' => $out]);
}

function handlePartnerOpReply(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();
    $info      = resolveOpMember($db, $principal);
    $memberId  = $info['id'];
    $areas     = $info['areas'];

    $threadId = (int)($body['thread_id'] ?? 0);
    $msg      = trim((string)($body['body'] ?? ''));
    if ($threadId < 1)       apiError('thread_id required.');
    if (!$msg)               apiError('Reply body is required.');
    if (strlen($msg) > 4000) apiError('Reply too long.');

    // Verify thread is in an area this member is enrolled in
    $tStmt = $db->prepare(
        "SELECT id, area_key, status FROM partner_op_threads WHERE id = ? LIMIT 1"
    );
    $tStmt->execute([$threadId]);
    $thread = $tStmt->fetch();
    if (!$thread)                                          apiError('Thread not found.', 404);
    if (!in_array((string)$thread['area_key'], $areas, true)) apiError('Not enrolled in this area.', 403);
    if ((string)$thread['status'] === 'closed')            apiError('This thread has been closed.');

    $db->prepare(
        "INSERT INTO partner_op_replies
            (thread_id, body, direction, from_member_id, created_at)
         VALUES (?, ?, 'inbound', ?, NOW())"
    )->execute([$threadId, $msg, $memberId]);

    $db->prepare(
        "UPDATE partner_op_threads
         SET reply_count = reply_count + 1, last_reply_at = NOW(),
             status = 'open', updated_at = NOW()
         WHERE id = ?"
    )->execute([$threadId]);

    // Mark thread as unread for all other enrolled members who have a read receipt
    // (so they see the new reply)
    try {
        $db->prepare(
            "UPDATE partner_op_broadcast_reads
             SET read_at = NULL
             WHERE thread_id = ? AND member_id != ?"
        )->execute([$threadId, $memberId]);
    } catch (Throwable) {}

    apiSuccess(['replied' => true]);
}

function handlePartnerOpRead(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();
    $info      = resolveOpMember($db, $principal);
    $memberId  = $info['id'];

    $threadId = (int)($body['thread_id'] ?? 0);
    if ($threadId < 1) apiError('thread_id required.');

    // Works for both broadcast reads and partner-post reads
    $db->prepare(
        "UPDATE partner_op_broadcast_reads
         SET read_at = NOW()
         WHERE thread_id = ? AND member_id = ? AND read_at IS NULL"
    )->execute([$threadId, $memberId]);

    // If no row exists yet (own-thread case), insert one
    $db->prepare(
        "INSERT IGNORE INTO partner_op_broadcast_reads (thread_id, member_id, delivered_at, read_at)
         VALUES (?, ?, NOW(), NOW())"
    )->execute([$threadId, $memberId]);

    apiSuccess(['read' => true]);
}

function fetchOpThreadSummary(PDO $db, int $memberId, array $areas): array {
    if (!$memberId || !$areas) return [];
    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($areas), '?'));

        // Unread partner posts: seeded in broadcast_reads when posted, read_at IS NULL
        // Excludes own posts (no read receipt row seeded for author)
        $ppStmt = $db->prepare(
            "SELECT t.area_key, COUNT(*) AS unread
             FROM partner_op_threads t
             INNER JOIN partner_op_broadcast_reads br
                     ON br.thread_id = t.id AND br.member_id = ? AND br.read_at IS NULL
             WHERE t.direction = 'inbound' AND t.area_key IN ({$ph})
             GROUP BY t.area_key"
        );
        $ppStmt->execute(array_merge([$memberId], $areas));
        $unreadPosts = [];
        foreach ($ppStmt->fetchAll() as $r) { $unreadPosts[$r['area_key']] = (int)$r['unread']; }

        // Unread admin broadcasts
        $bcStmt = $db->prepare(
            "SELECT t.area_key, COUNT(*) AS unread
             FROM partner_op_threads t
             INNER JOIN partner_op_broadcast_reads br
                     ON br.thread_id = t.id AND br.member_id = ? AND br.read_at IS NULL
             WHERE t.direction = 'broadcast' AND t.area_key IN ({$ph})
             GROUP BY t.area_key"
        );
        $bcStmt->execute(array_merge([$memberId], $areas));
        $unreadBc = [];
        foreach ($bcStmt->fetchAll() as $r) { $unreadBc[$r['area_key']] = (int)$r['unread']; }

        // Unread admin replies on member's own threads
        $rpStmt = $db->prepare(
            "SELECT t.area_key, COUNT(*) AS unread
             FROM partner_op_replies r
             INNER JOIN partner_op_threads t ON t.id = r.thread_id
             WHERE t.initiated_by_member_id = ?
               AND r.direction = 'outbound'
               AND r.read_at IS NULL
               AND t.area_key IN ({$ph})
             GROUP BY t.area_key"
        );
        $rpStmt->execute(array_merge([$memberId], $areas));
        $unreadReplies = [];
        foreach ($rpStmt->fetchAll() as $r) { $unreadReplies[$r['area_key']] = (int)$r['unread']; }

        foreach ($areas as $ak) {
            $out[] = [
                'area_key'       => $ak,
                'unread_bc'      => (int)($unreadBc[$ak]      ?? 0),
                'unread_posts'   => (int)($unreadPosts[$ak]   ?? 0),
                'unread_replies' => (int)($unreadReplies[$ak] ?? 0),
            ];
        }
    } catch (Throwable) {}
    return $out;
}


/* ── PROPOSAL TALLIES (live feed) ───────────────────────────────────────────── */
function handleProposalTallies(): void {
    requireMethod('GET');
    requireAuth('snft'); // auth check only — tally is anonymous
    $db = getDB();

    try {
        // Fetch all open proposals
        $stmt = $db->prepare("SELECT id FROM vote_proposals WHERE status = 'open' ORDER BY id DESC LIMIT 20");
        $stmt->execute();
        $ids = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());

        if (!$ids) { apiSuccess(['tallies' => []]); }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $tStmt = $db->prepare("SELECT proposal_id, response_value, COUNT(*) AS votes
                                FROM vote_proposal_responses
                                WHERE proposal_id IN ({$ph})
                                GROUP BY proposal_id, response_value");
        $tStmt->execute($ids);

        $raw = [];
        foreach ($tStmt->fetchAll() as $row) {
            $pid = (int)$row['proposal_id'];
            $raw[$pid][(string)$row['response_value']] = (int)$row['votes'];
        }

        // Also fetch comment counts
        $commentCounts = [];
        if (api_table_exists($db, 'proposal_comments')) {
            $cStmt = $db->prepare("SELECT proposal_id, COUNT(*) AS cnt FROM proposal_comments WHERE proposal_id IN ({$ph}) GROUP BY proposal_id");
            $cStmt->execute($ids);
            foreach ($cStmt->fetchAll() as $row) {
                $commentCounts[(int)$row['proposal_id']] = (int)$row['cnt'];
            }
        }

        $tallies = [];
        foreach ($ids as $pid) {
            $counts = $raw[$pid] ?? [];
            $total  = array_sum($counts);
            $tallies[] = [
                'proposal_id'   => $pid,
                'tally'         => [
                    ['label' => 'yes',   'votes' => (int)($counts['yes']   ?? 0)],
                    ['label' => 'maybe', 'votes' => (int)($counts['maybe'] ?? 0)],
                    ['label' => 'no',    'votes' => (int)($counts['no']    ?? 0)],
                ],
                'total_votes'   => $total,
                'comment_count' => (int)($commentCounts[$pid] ?? 0),
            ];
        }

        apiSuccess(['tallies' => $tallies]);

    } catch (Throwable $e) {
        apiSuccess(['tallies' => []]);
    }
}


/* ── PROPOSAL COMMENTS ─────────────────────────────────────────────────────── */
function handleProposalComments(): void {
    $principal = requireAuth('snft');
    $db   = getDB();

    // Ensure table exists
    if (!api_table_exists($db, 'proposal_comments')) {
        $db->exec("CREATE TABLE IF NOT EXISTS `proposal_comments` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `proposal_id` bigint(20) UNSIGNED NOT NULL,
            `comment_text` text NOT NULL,
            `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_pc_proposal` (`proposal_id`),
            KEY `idx_pc_submitted` (`submitted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Anonymous comments on vote_proposals. No member_id stored — deliberately anonymous.'");
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body       = jsonBody();
        $proposalId = (int)($body['proposal_id'] ?? 0);
        $text       = trim((string)($body['comment_text'] ?? ''));

        if ($proposalId < 1)   apiError('proposal_id required.');
        if (strlen($text) < 3) apiError('Comment too short (minimum 3 characters).');
        if (strlen($text) > 1000) apiError('Comment too long (maximum 1000 characters).');

        // Verify proposal is open
        $pStmt = $db->prepare("SELECT status FROM vote_proposals WHERE id = ? LIMIT 1");
        $pStmt->execute([$proposalId]);
        $p = $pStmt->fetch();
        if (!$p) apiError('Proposal not found.', 404);
        if ((string)$p['status'] !== 'open') apiError('This proposal is not open for comments.');

        $db->prepare("INSERT INTO proposal_comments (proposal_id, comment_text, submitted_at) VALUES (?, ?, UTC_TIMESTAMP())")
           ->execute([$proposalId, $text]);

        apiSuccess(['commented' => true]);

    } else {
        // GET — proposal_id from path segment (vault/proposal-comments/4) or query string fallback
        // $subId is set in the file/include scope — must declare global to access inside this function
        global $subId;
        $proposalId = (int)($subId ?? $_GET['proposal_id'] ?? 0);
        if ($proposalId < 1) apiError('proposal_id required.');

        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $cStmt = $db->prepare("SELECT id, comment_text, submitted_at FROM proposal_comments WHERE proposal_id = ? ORDER BY submitted_at DESC LIMIT {$limit} OFFSET {$offset}");
        $cStmt->execute([$proposalId]);
        $comments = $cStmt->fetchAll();

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM proposal_comments WHERE proposal_id = ?");
        $cntStmt->execute([$proposalId]);
        $total = (int)$cntStmt->fetchColumn();

        apiSuccess(['comments' => $comments, 'total' => $total]);
    }
}

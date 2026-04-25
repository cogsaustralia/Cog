<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once dirname(__DIR__) . '/_app/api/config/bootstrap.php';
require_once dirname(__DIR__) . '/_app/api/integrations/mailer.php';

ops_require_admin();
$pdo = ops_db();

function ucv_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

// ── Resolve certificate ───────────────────────────────────────────────────────
$certRef    = trim((string)($_GET['cert_ref']    ?? ''));
$issuanceId = (int)($_GET['issuance_id']         ?? 0);

$row = null;
if ($certRef !== '') {
    $st = $pdo->prepare("
        SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
               uc.email_sent_to, uc.email_sent_at,
               uir.register_ref, uir.unit_class_name, uir.sha256_hash, uir.consideration_cents,
               uir.issue_trigger, uir.gate, uir.issued_by_admin_id,
               m.full_name, m.first_name, m.email, m.member_number, m.member_type
        FROM unitholder_certificates uc
        INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
        INNER JOIN members m ON m.id = uc.member_id
        WHERE uc.cert_ref = ? LIMIT 1");
    $st->execute([$certRef]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($issuanceId > 0) {
    $st = $pdo->prepare("
        SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
               uc.email_sent_to, uc.email_sent_at,
               uir.register_ref, uir.unit_class_name, uir.sha256_hash, uir.consideration_cents,
               uir.issue_trigger, uir.gate, uir.issued_by_admin_id,
               m.full_name, m.first_name, m.email, m.member_number, m.member_type
        FROM unitholder_certificates uc
        INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
        INNER JOIN members m ON m.id = uc.member_id
        WHERE uir.id = ? LIMIT 1");
    $st->execute([$issuanceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$row) {
    http_response_code(404);
    echo '<!doctype html><html><head><title>Certificate not found</title>'
       . '<style>body{font-family:sans-serif;padding:60px;background:#f8f8f6;color:#333}</style></head><body>'
       . '<h2>Certificate not found</h2>'
       . '<p><a href="' . ucv_h(admin_url('unit_issuance.php')) . '?tab=certs">← Back to certificates</a></p>'
       . '</body></html>';
    exit;
}

// ── Build display values ──────────────────────────────────────────────────────
$classCode   = (string)($row['unit_class_code'] ?? '');
$className   = (string)($row['unit_class_name'] ?? '');
$certType    = (string)($row['cert_type']        ?? 'financial');
$memberType  = (string)($row['member_type']      ?? 'personal');
$gate        = (int)($row['gate']                ?? 1);
$unitsRaw    = (float)($row['units']             ?? 0);
$units       = $unitsRaw == floor($unitsRaw) ? number_format((int)$unitsRaw) : number_format($unitsRaw, 4);
$gateLabel   = $gate === 1 ? 'Gate 1 — Declaration Executed'
             : ($gate === 2 ? 'Gate 2 — Governance Foundation Day'
             : 'Gate 3 — Expansion Day');

// Format consideration
$consCents   = (int)($row['consideration_cents'] ?? 0);
$consDisplay = $consCents > 0 ? '$' . number_format($consCents / 100, 2) . ' AUD' : 'Nil — standing allocation';

// Email status
$emailSentAt = !empty($row['email_sent_at']) ? ucv_h(date('j M Y', strtotime($row['email_sent_at']))) : null;

// Class accent colour
$accentHex = match($classCode) {
    'S','kS'         => '#b8860b',
    'B'              => '#1a5f8a',
    'C'              => '#2e7d52',
    'P'              => '#7b4f8a',
    'D'              => '#8a4a2e',
    'Lr'             => '#4a6b8a',
    default          => '#8b6914',
};

// ── Rights data per class — document format ───────────────────────────────────
// Source: JVPA cls.1.2, 1.5, 2.1; Declaration Sch.9 Part A; Sub-Trust A cls.6; Sub-Trust B Sch.4
// Columns: [label, value, authority/note]
function ucv_rights(string $code, string $memberType): array {
    $rows = match($code) {

        'S' => [
            ['Token type',
             'Non-Fungible Token (NFT) — Personal S-NFT COG$. Soulbound — non-transferable during lifetime under any circumstances. Passes to nominated heir on death only; cancelled if no heir nominated or election not exercised within 12 months.',
             'JVPA cl.1.5(f)(i); Declaration cls.21.3, 35(i) — entrenched'],
            ['Governance right',
             '1 national vote on all Foundation matters. One vote per natural person — equal weight to every other Member.',
             'JVPA cl.35(e) — entrenched; Declaration cl.21.1'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit — proportional share of the Beneficiary Distribution Stream. 50% of Members Asset Pool dividends distributed via Sub-Trust B. Member may elect to receive as AUD bonus or as new ASX COG$ tokens.',
             'Declaration cls.21.1A, 35(q) — entrenched; Sub-Trust B Sch.4 cl.S4.2'],
            ['Distribution right',
             'Proportional share of Sub-Trust B distributions, mandatory within 60 days of each dividend period under the Trustee Act 1936 (SA). Failure to distribute is a breach of trust enforceable in the Supreme Court of South Australia.',
             'Declaration cl.35(g) — entrenched; Sub-Trust B Sch.4 cl.S4.4'],
            ['Inheritance',
             'Heir nomination through Members Vault at any time. On death, heir invited to accept, undergo KYC, and activate within 12 months. Irrevocable on acceptance.',
             'Declaration cl.21.4'],
            ['Consideration',
             '$4.00 AUD — permanently fixed. $3.00 applied to Administration; $1.00 applied to Sub-Trust A investment component.',
             'JVPA cl.35(x) — entrenched; Declaration cl.21.1; Sub-Trust A cl.6.2'],
            ['Smart contract attributes',
             "Soulbound transfer lock — no transfer during lifetime\nOne-per-person cap enforcement (natural persons aged 18+ only)\n3-of-Board multisignature required for token minting\nAnti-capture cap check (1,000,000 combined units across all classes)\nSub-Trust B income unit activation on lawful issue\nHeir nomination record and inheritance flow on death\nToken minting may not precede or be simultaneous with unit issue",
             'Declaration Sch.9 Part A; Sub-Trust A cl.7.1'],
        ],

        'B' => [
            ['Token type',
             'Non-Fungible Token (NFT) — Business B-NFT COG$ (BNFT). Entity-bound — non-transferable; cancelled automatically on entity dissolution, deregistration, or ABN cancellation.',
             'JVPA cl.1.5(f)(iii); Declaration cls.26A.1, 35(v)'],
            ['Governance right',
             '1 limited vote — exercisable only on Trustee appointment, removal, or replacement. Exercised by the named KYC-verified authorised representative only. No national governance vote on other Foundation matters.',
             'Declaration cl.26A.2(a)'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit — proportional share on identical per-unit terms as Class S. Full Beneficiary Distribution Stream participation.',
             'Declaration cl.26A.2(b); Sub-Trust B Deed cl.6.2; Sub-Trust B Sch.4 cl.S4.2'],
            ['Distribution right',
             'Proportional share of Sub-Trust B distributions on same terms as Class S. Mandatory within 60 days under Trustee Act 1936 (SA).',
             'Declaration cl.35(g) — entrenched; Sub-Trust B Sch.4 cl.S4.4'],
            ['BNFT compliance stack',
             'Active ABN/ACN required. Named KYC-verified authorised representative nominated. All compliance conditions must be maintained for the lifetime of the token.',
             'Declaration cls.26A.3, 35(v); Sub-Trust A cls.6.4, 7.3'],
            ['Consideration',
             '$40.00 AUD — may never be reduced below the Class S fee of $4.00 AUD. $30.00 applied to Administration and member set-up; $10.00 applied to Sub-Trust A investment component.',
             'JVPA cl.35(y) — entrenched; Declaration cl.26A.1; Sub-Trust A cl.6.4'],
            ['Smart contract attributes',
             "Entity-bound transfer lock — no transfer during entity lifetime\nBNFT compliance stack flag (ABN verified, authorised rep KYC-verified)\n3-of-Board multisignature required for token minting\nAnti-capture cap check (1,000,000 combined units)\nSub-Trust B income unit activation on lawful issue\nEntity dissolution / ABN cancellation triggers automatic token cancellation\nOne per legal entity with active ABN — enforced before issue",
             'Declaration Sch.9 Part A; Sub-Trust A cls.6.4, 7.1, 7.3'],
        ],

        'kS' => [
            ['Token type',
             'Non-Fungible Token (NFT) — Kids S-NFT COG$ (kS-NFT). Soulbound — non-transferable in all circumstances. Auto-converts to Class S on the holder\'s 18th birthday; irrevocable; no further consideration payable on conversion.',
             'JVPA cl.1.5(f)(ii); Declaration cls.25.1, 25.4'],
            ['Governance right',
             '1 national vote exercised by proxy (parent/guardian) until age 18. Auto-activates in the holder\'s own name on 18th birthday.',
             'Declaration cl.25.3'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit — held in trust until age 18. Income accumulated in trust; distributions commence on 18th birthday auto-conversion to Class S.',
             'Declaration cls.25.1, 25.4; Sub-Trust B Sch.4 cl.S4.2'],
            ['Distribution right',
             'Distributions held in trust during minority. Released to Member on auto-conversion to Class S at age 18.',
             'Declaration cls.25.1, 25.4'],
            ['Consideration',
             '$1.00 AUD. Acquiring party must be a verified existing Class S holder acting on behalf of the child or grandchild.',
             'Declaration Sch.9 Part A; Sub-Trust A cl.6.3'],
            ['Smart contract attributes',
             "Soulbound transfer lock — permanent, no exceptions\nProxy governance flag (parent/guardian exercises vote until conversion)\nDate-triggered auto-conversion to Class S at age 18\nSub-Trust B income unit held in trust until conversion\n3-of-Board multisignature required for token minting\nNo further consideration payable on conversion",
             'Declaration Sch.9 Part A; Sub-Trust A cls.6.3, 7.1'],
        ],

        'C' => [
            ['Token type',
             'Fungible Token — Community COG$ (Class C). Not a non-fungible token. Fungible barter and exchange record within the Foundation ecosystem. No Beneficial Unit. No yield. Not a financial investment.',
             'JVPA cl.1.5(f)(iv); Declaration cls.23D.1, 23D.2'],
            ['Governance right',
             'None by Class C alone — no national governance vote, no local weighted vote.',
             'Declaration cl.23D.1; Schedule 9 Part A'],
            ['Beneficial Unit (Sub-Trust B)',
             'None — Class C carries no Sub-Trust B income unit, no dividend entitlement, and no yield of any kind.',
             'Declaration cl.23D.2'],
            ['Function',
             'Immutable record of barter transactions for goods and services between Members. Allocated as consideration for approved contributed efforts, services, and stewardship activities. P2P exchange between Members where class rules expressly permit. No fiat sale under any circumstances.',
             'Declaration cls.23D.1, 23D.2, 35(w) — entrenched'],
            ['Allocation basis',
             'Initial allocation by standing Members Poll direction under Declaration cl.23D.3. ' . ($memberType === 'business' ? '10,000 units for Business Members.' : '1,000 units for Individual Members.') . ' No purchase fee applies.',
             'Declaration cl.23D.3; Sub-Trust A cl.6.8'],
            ['Pre-Expansion Day record',
             'SHA-256 database record is the authoritative legal record of each allocation until Expansion Day. Migrated on-chain at Expansion Day.',
             'Declaration cl.23D.5'],
            ['Smart contract attributes',
             "Fungible token — class-rule-gated P2P transfer only\nNo-fiat-sale rule (entrenched cl.35(w)) — absolute prohibition\nSHA-256 hash record — authoritative pre-Expansion Day\nAdditional allocations require Members Poll direction (cl.23D.4)\nAnti-capture cap inclusion\nOn-chain migration at Expansion Day (cl.23D.5)",
             'Declaration Sch.9 Part A; Sub-Trust A cl.6.8'],
        ],

        'P' => [
            ['Token type',
             'Non-Fungible Token (NFT) — Pay It Forward COG$ (Class P). Non-transferable donor record. Exhausted and cancelled on allocation to recipient; surplus unallocated within 24 months transferred to Sub-Trust C.',
             'JVPA cl.1.5(f); Declaration cls.26.1, 26.6'],
            ['Governance right (donor)',
             'None — Pay It Forward units carry no vote and no Beneficial Unit for the donor before or after allocation.',
             'Declaration cl.26.1A'],
            ['Beneficial Unit (Sub-Trust B)',
             'None for the donor. The funded recipient receives all Class S rights on activation, including 1 Sub-Trust B income unit.',
             'Declaration cl.26.1'],
            ['Function',
             'Funds a free Class S or kS membership for a nominated recipient who cannot meet the joining fee. Donor receives a Pay It Forward record. Annual donor cap of $40,000 AUD applies.',
             'Declaration cls.26.1, 26.1A; Sub-Trust A cl.6.5'],
            ['Consideration',
             '$4.00 AUD per unit (or whole multiple). Applied as $4.00 per funded Class S or kS recipient. Surplus transferred to Sub-Trust C after 24 months.',
             'Declaration cl.26.6; Sub-Trust A cl.6.5'],
            ['Smart contract attributes',
             "Non-transferable donor record\nExcluded from Beneficial Class Unit count and anti-capture cap until allocation\nRecipient activation triggers Class S or kS unit issue to recipient\n3-of-Board multisig required for recipient token minting\n24-month unallocated surplus transferred to Sub-Trust C\nDonor cap enforcement ($40,000 AUD annual)",
             'Declaration Sch.9 Part A; Sub-Trust A cl.6.5'],
        ],

        'D' => [
            ['Token type',
             'Non-Fungible Token (NFT) — Donation COG$ (Class D). Non-transferable. No personal Beneficial Unit for the donor. Sub-Trust C is registered as the D Class Beneficial Unit Holder in the Members Vault for each validly issued Class D unit.',
             'JVPA cl.1.5(f)(v); Declaration cls.24.1, 24.2'],
            ['Governance right',
             'None — Donation COG$ carries no governance vote for the donor.',
             'Declaration cl.24.3'],
            ['Beneficial Unit Holder',
             'Sub-Trust C — registered as D Class Beneficial Unit Holder. 1 D Class income unit per validly issued Donation COG$. Sub-Trust C receives its proportional share of the Donation Dividend Stream via Sub-Trust B.',
             'Declaration cls.24.2, 35(g) — entrenched; Sub-Trust C Deed Recital D; Sub-Trust B Sch.4 cl.S4.2'],
            ['Funds flow',
             '$4.00 AUD consideration: $2.00 transferred directly to Sub-Trust C (charitable trust) within 2 business days of issue; $2.00 applied through Sub-Trust A to approved Members Asset Pool assets. Donation Ledger entry created.',
             'Declaration cls.24.1, 8.1B, 8.1E(b), 35(g); Sub-Trust A cl.6.7'],
            ['Breach of trust',
             'Failure to transfer $2.00 to Sub-Trust C within 2 business days of issue is a breach of trust enforceable in the Supreme Court of South Australia.',
             'Declaration cl.24.3; Sub-Trust A cl.7.4'],
            ['Smart contract attributes',
             "Non-transferable — no sale, assignment, or dealing\nSub-Trust C auto-registered as D Class Beneficial Unit Holder on minting\n\$2.00 automatic transfer to Sub-Trust C triggered on issue\nDonation Ledger entry created and anchored\n3-of-Board multisig required for token minting\nDonation Dividend Stream routing enforced via Sub-Trust B",
             'Declaration Sch.9 Part A; Sub-Trust A cls.6.7, 7.1, 7.4'],
        ],

        'Lr' => [
            ['Token type',
             'Fungible Token — Resident COG$ (Class Lr). Fungible local governance unit. Not a non-fungible token. Non-transferable. No Beneficial Unit. No yield. No national governance vote.',
             'Declaration cl.23B.1; JVPA cl.1.5(f)(ix)'],
            ['Local governance right',
             '1,000 weighted local votes per declared Affected Zone, giving an effective total of 1,001 votes to 1 in any Local Decision Vote relating to that zone. Local weighted vote only — no national governance vote.',
             'Declaration cls.23B.2, 35(s); Schedule 9 Part A'],
            ['Beneficial Unit (Sub-Trust B)',
             'None — Class Lr carries no Sub-Trust B income unit, no Beneficiary Distribution Stream entitlement, and no yield.',
             'Declaration cl.23B.2'],
            ['Auto-lapse conditions',
             'Lapses automatically if: (a) the Affected Zone declaration expires or is revoked; or (b) the member ceases to be a verified resident of that zone. Automatic — no administrative action required.',
             'Declaration cl.23B.4'],
            ['Consideration',
             'Nil — no monetary consideration. Issued automatically by the Members Vault once Affected Zone is declared and residency eligibility is verified.',
             'Declaration cl.23B.3; Sub-Trust A cl.6.8'],
            ['Smart contract attributes',
             "Fungible token — non-transferable\nExcluded from anti-capture cap and Beneficial Class Unit count\nAffected Zone declaration required before issuance\nResidency eligibility verified via geofencing specification\nAuto-lapse trigger on zone expiry or residency loss\nWeighted local vote calculation enforced at vote time\nZone-linked — one allocation per declared Affected Zone",
             'Declaration Sch.9 Part A; Sub-Trust A cl.6.8; Declaration cl.23B.3'],
        ],

        'A' => [
            ['Token type',
             'Fungible Token — ASX COG$ (Class A). Fungible Tier 2 investment token backed by ASX-listed Australian resource company shareholdings in the Members Asset Pool.',
             'JVPA cl.1.5(f)(vi); Declaration cls.22, 35(j)'],
            ['Governance right',
             'No dedicated national governance vote by reason only of Class A. Governance rights arise from co-held Class S or Class B unit.',
             'Declaration cl.22; Schedule 9 Part A'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit per validly issued Class A unit — proportional share of the Beneficiary Distribution Stream.',
             'Sub-Trust B Sch.4 cl.S4.2'],
            ['Transfer and dealing rules',
             "Subject to 12-month Lock Period (Stewardship Season) from Expansion Day.\nAfter Lock Period: own-class swap to Landholder (1:1, irrevocable); own-class conversion to Donation (1:1, irrevocable); P2P transfer to KYC-verified Beneficiary Holder as gift or goods and services (no fiat) via Members Vault with 3-of-Board multisig within 5 business days. No fiat sale.",
             'Declaration cls.22.3, 35(j) — entrenched; Sub-Trust A cl.6.6'],
            ['Consideration',
             '$4.00 AUD per unit for eligible Class S holders; $40.00 AUD per unit for eligible Class B holders. Increase by Special Resolution under cl.28A only.',
             'Declaration cl.22.1; Sub-Trust A cl.6.6'],
            ['Smart contract attributes',
             "Fungible investment token — CHESS-backed ASX shareholding\n12-month Lock Period enforced from Expansion Day\nPost-lock: class swap, donation conversion, P2P gift transfer enabled\n3-of-Board multisig for all token dealings post-lock\nNo-fiat-sale rule (entrenched cl.35(w))\nAnti-capture cap check (1,000,000 combined units)\nSub-Trust B income unit activation on issue",
             'Declaration Sch.9 Part A; Sub-Trust A cl.6.6'],
        ],

        'Lh' => [
            ['Token type',
             'Fungible Token — Landholder COG$ (Class Lh). Fungible Tier 2 token. Issued per hectare to eligible landholders, LALCs, and Prescribed Bodies Corporate. Zero-cost issuance for LALCs and PBCs (entrenched).',
             'JVPA cl.1.5(f)(vii),(q) — entrenched; Declaration cls.23, 23.4, 35(ab)'],
            ['Governance right',
             'Weighted affected-zone governance vote calculated automatically by Members Vault smart contract. No national governance vote by reason of Class Lh alone.',
             'Declaration cl.23.3; Schedule 9 Part A'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit per validly issued Class Lh unit. Full Beneficiary Distribution Stream participation. 100% of all consideration applied to Sub-Trust A.',
             'Declaration cl.23.1; Sub-Trust B Sch.4 cl.S4.2'],
            ['Transfer and dealing rules',
             "Own-class swap to ASX Class A (1:1, irrevocable at any time). Own-class conversion to Donation (1:1, irrevocable). P2P transfer to eligible landholder — gift or goods/services only, no fiat, 3-of-Board multisig, 5-business-day window. Property title transfer election within 30 days of land transfer. LALC/PBC transfers subject to Aboriginal Land Rights Act 1983 (NSW) and Native Title Act 1993 (Cth).",
             'Declaration cls.23.2, 23.4.5; Sub-Trust A cl.6.8'],
            ['LALC/PBC entitlement',
             'Automatic zero-cost issuance for LALCs and PBCs. Maximum 1,000 tokens per hectare. FNAC written endorsement required. Entity KYC-verified with named authorised representative.',
             'Declaration cls.23.4, 35(ab) — entrenched'],
            ['Smart contract attributes',
             "Fungible token — class-rule-gated P2P transfer only\nNo-fiat-sale rule (entrenched cl.35(w))\n1,000-per-hectare cap enforced before issue\nLALC/PBC zero-cost pathway enforced\nFNAC endorsement required for LALC/PBC issuance\n3-of-Board multisig for all token dealings\nWeighted local vote calculation enforced at vote time\nProperty title transfer election enforced (30-day window)\nSub-Trust B income unit activation on lawful issue",
             'Declaration Sch.9 Part A; Sub-Trust A cls.6.8, 7.2'],
        ],

        'BP' => [
            ['Token type',
             'Fungible Token — Business Property COG$ (Class BP). Fungible Tier 2 token for eligible commercial property holders. Members Asset Pool participation unit.',
             'JVPA cl.1.5(f)(viii); Declaration cls.23C, 35(w)'],
            ['Governance right',
             'Weighted affected-zone governance rights only. No national governance vote by reason of Class BP alone.',
             'Declaration cl.23C.1; Schedule 9 Part A'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit per validly issued Class BP unit where the Declaration expressly provides. Full proceeds applied to Sub-Trust A.',
             'Declaration cl.23C.1; Sub-Trust B Sch.4 cl.S4.2'],
            ['Transfer and dealing rules',
             'Transferable with property title or P2P to eligible commercial property holder via Members Vault. No fiat sale under any circumstances (entrenched cl.35(w)).',
             'Declaration cl.23C.2; Sub-Trust A cl.6.8'],
            ['Smart contract attributes',
             "Fungible token — class-rule-gated dealing only\nNo-fiat-sale rule (entrenched cl.35(w)) — absolute prohibition\nProperty title transfer mechanics enforced\nWeighted affected-zone vote enforced at vote time\n3-of-Board multisig for token dealings\nSub-Trust B income unit activation on lawful issue",
             'Declaration Sch.9 Part A; Sub-Trust A cls.6.8, 7.2'],
        ],

        'R' => [
            ['Token type',
             'Non-Fungible Token (NFT) — RWA COG$ (Class R). Tier 2 real-world-asset-backed token. Non-transferable until class-specific dealing rules are expressly adopted under the Declaration.',
             'JVPA cl.1.5(f)(x); Declaration cls.23A, 23A.4'],
            ['Governance right',
             'No national governance vote and no local weighted vote by reason only of Class R issuance.',
             'Declaration cl.23A.4'],
            ['Beneficial Unit (Sub-Trust B)',
             '1 Sub-Trust B income unit per validly issued Class R unit from the moment of issue.',
             'Declaration cl.23A.3; Sub-Trust B Sch.4 cl.S4.2'],
            ['Funds flow',
             '100% of activated Class R unit consideration applied to Sub-Trust A for the relevant approved RWA acquisition, valuation-linked governance framework, or other Board-approved asset-side allocation.',
             'Declaration cl.23A.3'],
            ['Transfer and dealing rules',
             'Non-transferable until class-specific dealing rules are expressly adopted under the Declaration. Any future transfer or reclassification must occur only through the Members Vault in accordance with adopted rules.',
             'Declaration cl.23A.4'],
            ['Smart contract attributes',
             "Non-fungible token — non-transferable until class rules adopted\nBoard resolution required for each RWA acquisition approval\n3-of-Board multisig required for token minting\nFull proceeds to Sub-Trust A on activation\nSub-Trust B income unit activation on lawful issue\nValuation-linked governance framework enforced",
             'Declaration Sch.9 Part A; Sub-Trust A cl.6'],
        ],

        default => [
            ['Rights and attributes',
             'Defined in CJVM Hybrid Trust Declaration Schedule 9, Part A.',
             'Refer to the governing instruments for full details of this unit class.'],
        ],
    };
    return $rows;
}

$rights  = ucv_rights($classCode, $memberType);
$isFinancial = $certType === 'financial';
$isCommunity = $certType === 'community';
$isGovAlloc  = $certType === 'governance_allocation';

$certTypeLabel = $isFinancial ? 'Financial Certificate' : ($isCommunity ? 'Community Exchange Record' : 'Governance Allocation Notice');
$backUrl  = ucv_h(admin_url('unit_issuance.php')) . '?tab=certs';
$regUrl   = ucv_h(admin_url('unit_issuance.php')) . '?tab=register';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificate <?= ucv_h($row['cert_ref'] ?? '') ?> — COG$ of Australia Foundation</title>
<style>
/* ── Screen reset ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body {
  background: #e8e4de;
  font-family: Georgia, "Times New Roman", serif;
  color: #1a1208;
  min-height: 100vh;
}

/* ── Admin toolbar — screen only ─────────────────────────── */
.admin-bar {
  position: sticky; top: 0; z-index: 200;
  background: #0f0d09;
  border-bottom: 2px solid #c8901a;
  padding: 9px 24px;
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.admin-bar .bar-info { flex: 1; min-width: 180px; }
.admin-bar .bar-ref { font-size: 13px; font-weight: 700; color: #f0b429; font-family: monospace; }
.admin-bar .bar-meta { font-size: 11px; color: #9a8a74; margin-top: 2px; }
.admin-bar a, .admin-bar button {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 14px; border-radius: 7px;
  font-size: 12px; font-weight: 600; font-family: -apple-system, sans-serif;
  text-decoration: none; cursor: pointer;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.07); color: #f0e8d6;
  transition: background .15s;
}
.admin-bar a:hover, .admin-bar button:hover { background: rgba(255,255,255,.13); }
.admin-bar .print-btn { background: rgba(212,178,92,.18); border-color: rgba(212,178,92,.4); color: #f0b429; }
.admin-bar .print-btn:hover { background: rgba(212,178,92,.3); }
.badge-sent { padding: 2px 9px; border-radius: 99px; font-size: 10px; font-weight: 700;
  background: rgba(82,184,122,.15); border: 1px solid rgba(82,184,122,.3); color: #7ee0a0;
  font-family: -apple-system, sans-serif; }
.badge-pending { padding: 2px 9px; border-radius: 99px; font-size: 10px; font-weight: 700;
  background: rgba(200,144,26,.15); border: 1px solid rgba(200,144,26,.3); color: #e8cc80;
  font-family: -apple-system, sans-serif; }

/* ── Page wrapper (A4 proportions on screen) ──────────────── */
.page-wrap {
  max-width: 794px; /* A4 at 96dpi */
  margin: 28px auto 60px;
  background: #fdfbf8;
  box-shadow: 0 4px 32px rgba(0,0,0,.18);
}

/* ── Document header ──────────────────────────────────────── */
.doc-header {
  background: #1a1208;
  padding: 24px 44px 20px;
  border-bottom: 4px solid <?= $accentHex ?>;
  display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
}
.doc-header-left { flex: 1; }
.org-name { font-size: 22px; font-weight: 700; color: #f0b429; letter-spacing: .02em;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
.org-sub  { font-size: 11px; color: #9a8a74; margin-top: 3px; letter-spacing: .04em;
  font-family: -apple-system, sans-serif; }
.cert-type-badge {
  display: inline-block; margin-top: 14px;
  padding: 4px 12px; border-radius: 4px; font-size: 10px; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  background: rgba(<?= implode(',', sscanf($accentHex, '#%02x%02x%02x') ?? [139,105,20]) ?>,.25);
  border: 1px solid <?= $accentHex ?>50;
  color: <?= $accentHex ?>;
  font-family: -apple-system, sans-serif;
}
.doc-header-right { text-align: right; }
.cert-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
  color: #9a8a74; font-family: -apple-system, sans-serif; margin-bottom: 6px; }
.cert-ref-display { font-size: 20px; font-weight: 700; color: #f0b429;
  font-family: "Courier New", monospace; letter-spacing: .04em; }
.class-pill {
  display: inline-block; margin-top: 8px;
  padding: 3px 14px; border-radius: 99px; font-size: 13px; font-weight: 700;
  background: <?= $accentHex ?>22; border: 1.5px solid <?= $accentHex ?>;
  color: <?= $accentHex ?>;
  font-family: -apple-system, sans-serif;
}

/* ── Document body ────────────────────────────────────────── */
.doc-body { padding: 28px 44px 20px; }

/* Intro text */
.intro-text { font-size: 12px; line-height: 1.65; color: #3a2e1a; margin-bottom: 20px;
  border-bottom: 1px solid #ddd8ce; padding-bottom: 16px; }
.intro-text strong { color: #1a1208; }

/* Two-column holding details grid */
.details-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 0; border: 1.5px solid #c8b99a; border-radius: 4px;
  overflow: hidden; margin-bottom: 20px;
}
.detail-cell {
  padding: 11px 16px; border-bottom: 1px solid #ddd8ce;
}
.detail-cell:nth-child(odd) { border-right: 1px solid #ddd8ce; background: #fdfbf8; }
.detail-cell:nth-child(even) { background: #faf7f2; }
.detail-cell:nth-last-child(-n+2) { border-bottom: none; }
/* If odd total cells, last one spans */
.detail-cell.span2 { grid-column: 1 / -1; border-right: none; }
.detail-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #8b7a5a; margin-bottom: 3px;
  font-family: -apple-system, sans-serif; }
.detail-value { font-size: 13.5px; font-weight: 600; color: #1a1208; line-height: 1.35; }
.detail-value.mono { font-family: "Courier New", monospace; font-size: 12.5px; }
.detail-value.large { font-size: 16px; color: <?= $accentHex ?>; font-weight: 700; }

/* Hash row */
.hash-row {
  border: 1px solid #ddd8ce; border-radius: 4px;
  padding: 10px 16px; margin-bottom: 20px; background: #f5f2ec;
}
.hash-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #8b7a5a; margin-bottom: 4px;
  font-family: -apple-system, sans-serif; }
.hash-value { font-size: 10.5px; font-family: "Courier New", monospace;
  color: #3a2e1a; word-break: break-all; line-height: 1.5; }

/* Section headings */
.section-heading {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .09em;
  color: <?= $accentHex ?>; margin: 0 0 10px;
  padding-bottom: 5px; border-bottom: 2px solid <?= $accentHex ?>40;
  font-family: -apple-system, sans-serif;
}

/* Rights table */
.rights-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.rights-table tr { border-bottom: 1px solid #e8e2d8; }
.rights-table tr:last-child { border-bottom: none; }
.rights-table td { padding: 10px 14px; vertical-align: top; font-size: 12px; }
.rights-table td:first-child {
  width: 28%; font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #8b7a5a; padding-top: 12px;
  font-family: -apple-system, sans-serif;
}
.rights-table td:nth-child(2) { font-size: 12.5px; color: #1a1208; font-weight: 600; line-height: 1.4; }
.rights-table td:last-child {
  width: 30%; font-size: 10.5px; color: #7a6a4e; line-height: 1.5;
  border-left: 1px solid #e8e2d8; padding-left: 14px;
}
.rights-table tr:nth-child(even) { background: #faf7f2; }

.smart-contract-list { margin: 0; padding: 0; list-style: none; }
.smart-contract-list li { font-size: 12px; color: #1a1208; line-height: 1.55;
  padding: 1px 0 1px 14px; position: relative; }
.smart-contract-list li::before { content: "›"; position: absolute; left: 0; color: <?= $accentHex ?>; font-weight: 700; }

/* Notice box for community/gov-alloc types */
.notice-box {
  border-left: 3px solid <?= $accentHex ?>; background: <?= $accentHex ?>0d;
  padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0;
}
.notice-box p { font-size: 12px; line-height: 1.65; color: #3a2e1a; }
.notice-box strong { color: #1a1208; }

/* ── Governing instruments ─────────────────────────────────── */
.instruments-section {
  border-top: 1.5px solid #c8b99a; padding-top: 22px; margin-top: 6px;
}
.instruments-grid {
  display: grid; grid-template-columns: 1fr 1fr 1fr;
  gap: 12px; margin-bottom: 14px;
}
.instrument-card {
  border: 1px solid #ddd8ce; border-radius: 4px; padding: 10px 14px;
  background: #faf7f2;
}
.instrument-title { font-size: 9px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: <?= $accentHex ?>; margin-bottom: 3px;
  font-family: -apple-system, sans-serif; }
.instrument-title--supreme { color: #8b1a1a; }
.instrument-name  { font-size: 10.5px; color: #1a1208; font-weight: 600; line-height: 1.3; }
.instrument-note  { font-size: 9px; color: #7a6a4e; margin-top: 3px; line-height: 1.4;
  font-family: -apple-system, sans-serif; }
.instruments-grid-5 {
  display: grid; grid-template-columns: repeat(5, 1fr);
  gap: 8px; margin-bottom: 14px;
}

/* ── Document footer ──────────────────────────────────────── */
.doc-footer {
  background: #1a1208;
  padding: 18px 44px;
  border-top: 3px solid <?= $accentHex ?>;
  display: flex; justify-content: space-between; align-items: flex-end; gap: 24px;
}
.footer-trustee { flex: 1; }
.footer-trustee-name { font-size: 13px; font-weight: 700; color: #f0e8d6;
  font-family: -apple-system, sans-serif; }
.footer-trustee-role { font-size: 10.5px; color: #9a8a74; margin-top: 2px;
  font-family: -apple-system, sans-serif; }
.footer-trustee-org  { font-size: 10px; color: #6b5c44; margin-top: 6px; line-height: 1.6;
  font-family: -apple-system, sans-serif; }
.footer-seal { text-align: right; }
.seal-circle {
  width: 64px; height: 64px; border-radius: 50%;
  border: 2.5px solid <?= $accentHex ?>80;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  margin-left: auto;
}
.seal-text { font-size: 7px; text-transform: uppercase; letter-spacing: .06em;
  color: <?= $accentHex ?>; text-align: center; line-height: 1.4;
  font-family: -apple-system, sans-serif; font-weight: 700; }

/* ── Print styles ──────────────────────────────────────────── */
@page {
  size: A4 portrait;
  margin: 0;
}
@media print {
  /* ── Reset everything that causes whitespace ────────────────── */
  html {
    font-size: 12.5px;
    background: #fff !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  body {
    background: #fff !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
  }
  /* Admin bar: zero height, not just hidden — prevents top gap */
  .admin-bar {
    display: none !important;
    height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
    position: static !important;
  }
  .page-wrap {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    box-shadow: none !important;
    background: #fff !important;
    width: 100% !important;
  }

  /* ── Force colour on dark sections ─────────────────────────── */
  .doc-header, .doc-footer,
  .rights-table tr:nth-child(even),
  .hash-row, .instrument-card, .notice-box, .detail-cell {
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* ── Header — tight but readable ───────────────────────────── */
  .doc-header       { padding: 10px 18px 9px; }
  .org-name         { font-size: 14px; }
  .org-sub          { font-size: 8.5px; margin-top: 2px; }
  .cert-ref-display { font-size: 13px; letter-spacing: .02em; }
  .cert-title       { font-size: 8.5px; margin-bottom: 3px; }
  .cert-type-badge  { margin-top: 4px; padding: 2px 6px; font-size: 7px; }
  .class-pill       { font-size: 8.5px; padding: 1px 7px; margin-top: 3px; }

  /* ── Body ──────────────────────────────────────────────────── */
  .doc-body         { padding: 9px 18px 7px; }

  /* ── Intro text ─────────────────────────────────────────────── */
  .intro-text       { font-size: 9px; line-height: 1.4; margin-bottom: 7px;
                      padding-bottom: 6px; }

  /* ── Section headings ───────────────────────────────────────── */
  .section-heading  { font-size: 8px; margin: 0 0 5px; padding-bottom: 3px; }

  /* ── Details grid ───────────────────────────────────────────── */
  .details-grid     { margin-bottom: 7px; }
  .detail-cell      { padding: 3px 9px; }
  .detail-label     { font-size: 6.5px; margin-bottom: 1px; }
  .detail-value     { font-size: 10px; line-height: 1.2; }
  .detail-value.large { font-size: 10.5px; }
  .detail-value.mono  { font-size: 9.5px; }

  /* ── Hash row — single line ─────────────────────────────────── */
  .hash-row         { padding: 4px 9px; margin-bottom: 7px; }
  .hash-label       { font-size: 6.5px; margin-bottom: 1px; }
  .hash-value       { font-size: 8px; line-height: 1.2;
                      white-space: nowrap; overflow: hidden;
                      text-overflow: ellipsis; max-width: 100%; }

  /* ── Notice box ─────────────────────────────────────────────── */
  .notice-box       { padding: 5px 9px; margin-bottom: 6px; }
  .notice-box p     { font-size: 8.5px; line-height: 1.35; }

  /* ── Rights table ───────────────────────────────────────────── */
  .rights-table               { margin-bottom: 7px; table-layout: fixed; }
  .rights-table td            { padding: 3px 7px; line-height: 1.25; }
  .rights-table td:first-child {
    width: 20%; font-size: 7px; padding-top: 4px; letter-spacing: .03em;
  }
  .rights-table td:nth-child(2) { font-size: 9px; line-height: 1.2; width: 44%; }
  .rights-table td:last-child   {
    font-size: 8px; line-height: 1.2; width: 36%; padding-left: 7px;
  }

  /* Smart contract list — inline dot-separated on print */
  .smart-contract-list        { display: inline; }
  .smart-contract-list li     {
    display: inline; font-size: 8.5px; line-height: 1.2; padding: 0;
  }
  .smart-contract-list li::before       { content: " · "; }
  .smart-contract-list li:first-child::before { content: ""; }

  /* ── Instruments ─────────────────────────────────────────────── */
  .instruments-section { padding-top: 7px; }
  .instruments-grid-5  { gap: 4px; margin-bottom: 7px; }
  .instrument-card     { padding: 3px 6px; }
  .instrument-title    { font-size: 6.5px; margin-bottom: 1px; }
  .instrument-name     { font-size: 8px; line-height: 1.2; }
  .instrument-note     { font-size: 7px; margin-top: 1px; line-height: 1.2; }

  /* ── Footer ──────────────────────────────────────────────────── */
  .doc-footer           { padding: 8px 18px; }
  .footer-trustee-name  { font-size: 9.5px; }
  .footer-trustee-role  { font-size: 8px; margin-top: 1px; }
  .footer-trustee-org   { font-size: 7.5px; margin-top: 3px; line-height: 1.4; }
  .seal-circle          { width: 40px; height: 40px; }
  .seal-text            { font-size: 5px; }

  .print-hide { display: none !important; }
  a { text-decoration: none !important; color: inherit !important; }
}
</style>
</head>
<body>

<!-- Admin toolbar (screen only) -->
<div class="admin-bar">
  <div class="bar-info">
    <div class="bar-ref"><?= ucv_h($row['cert_ref'] ?? '') ?></div>
    <div class="bar-meta">
      <?= ucv_h($row['full_name'] ?? '') ?>
      &nbsp;·&nbsp;
      <?= ucv_h($className) ?> (Class <?= ucv_h($classCode) ?>)
      <?php if ($emailSentAt): ?>
        &nbsp;·&nbsp; <span class="badge-sent">✅ Emailed <?= $emailSentAt ?></span>
      <?php else: ?>
        &nbsp;·&nbsp; <span class="badge-pending">⏳ Email pending</span>
      <?php endif; ?>
    </div>
  </div>
  <button class="print-btn" onclick="window.print()">🖨 Print / Save PDF</button>
  <a href="<?= $regUrl ?>">📚 Register</a>
  <a href="<?= $backUrl ?>">← Certs</a>
</div>

<!-- A4 Document -->
<div class="page-wrap">

  <!-- Document header -->
  <div class="doc-header">
    <div class="doc-header-left">
      <div class="org-name">COG$ of Australia Foundation</div>
      <div class="org-sub">Community Joint Venture — Wahlubal Country, Bundjalung Nation</div>
      <div class="cert-type-badge"><?= ucv_h($certTypeLabel) ?></div>
    </div>
    <div class="doc-header-right">
      <div class="cert-title">Certificate of Unit Holding</div>
      <div class="cert-ref-display"><?= ucv_h($row['cert_ref'] ?? '') ?></div>
      <div class="class-pill">Class <?= ucv_h($classCode) ?></div>
    </div>
  </div>

  <!-- Document body -->
  <div class="doc-body">

    <!-- Intro -->
    <p class="intro-text">
      This Certificate of Unit Holding is issued under the authority of the
      <strong>CJVM Hybrid Trust Declaration</strong> and
      <strong>Sub-Trust A Deed</strong> by the Caretaker Trustee of the
      <strong>COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust</strong>
      (ABN 61 734 327 831). It constitutes the formal legal record of unit holding for the
      unitholder named below.
      <span class="print-hide"> The rights, restrictions, and smart contract attributes
      programmed into the corresponding COG$ Token are set out below and are class-specific only.</span>
    </p>

    <!-- Holding details -->
    <p class="section-heading">Unit Holding Details</p>
    <div class="details-grid">
      <div class="detail-cell">
        <div class="detail-label">Certificate Reference</div>
        <div class="detail-value large mono"><?= ucv_h($row['cert_ref'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Register Reference</div>
        <div class="detail-value mono"><?= ucv_h($row['register_ref'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Unitholder</div>
        <div class="detail-value"><?= ucv_h($row['full_name'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Member Number</div>
        <div class="detail-value mono"><?= ucv_h($row['member_number'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Unit Class</div>
        <div class="detail-value"><?= ucv_h($className) ?> <span style="font-size:11px;font-weight:400;color:#7a6a4e;">(Class <?= ucv_h($classCode) ?>)</span></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Units Issued</div>
        <div class="detail-value large"><?= ucv_h($units) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Issue Date</div>
        <div class="detail-value"><?= ucv_h(date('j F Y', strtotime((string)($row['issue_date'] ?? 'today')))) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Consideration</div>
        <div class="detail-value"><?= ucv_h($consDisplay) ?></div>
      </div>
      <div class="detail-cell span2">
        <div class="detail-label">Issuance Gate</div>
        <div class="detail-value"><?= ucv_h($gateLabel) ?></div>
      </div>
    </div>

    <!-- Cryptographic record -->
    <div class="hash-row">
      <div class="hash-label">Cryptographic Record — SHA-256 Hash</div>
      <div class="hash-value"><?= ucv_h($row['sha256_hash'] ?? '') ?></div>
    </div>

    <!-- Notice for community / governance alloc types -->
    <?php if ($isCommunity): ?>
    <div class="notice-box">
      <p><strong>Important:</strong> Class C Community COG$ units carry <strong>no Sub-Trust B income unit and no yield</strong>. They are not a financial investment. They function as an immutable record of barter and service exchange between Members within the Foundation ecosystem.</p>
    </div>
    <?php elseif ($isGovAlloc): ?>
    <div class="notice-box">
      <p><strong>Important:</strong> Class Lr Resident COG$ units carry <strong>no Beneficial Unit, no yield, and no national governance vote</strong>. This is a local governance allocation only.</p>
    </div>
    <?php endif; ?>

    <!-- Rights and attributes -->
    <p class="section-heading">Rights and Attributes — Class <?= ucv_h($classCode) ?></p>
    <p style="font-size:11px;color:#7a6a4e;margin-bottom:14px;line-height:1.55;">
      The following rights and smart contract attributes apply exclusively to this unit class
      as defined in the governing instruments. No rights from other unit classes apply to this certificate.
    </p>
    <table class="rights-table">
      <tbody>
        <?php foreach ($rights as [$label, $value, $note]): ?>
        <tr>
          <td><?= ucv_h($label) ?></td>
          <td>
            <?php if ($label === 'Smart contract attributes'): ?>
              <ul class="smart-contract-list">
                <?php foreach (array_filter(explode("\n", $value)) as $item): ?>
                <li><?= ucv_h(trim($item)) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <?= nl2br(ucv_h($value)) ?>
            <?php endif; ?>
          </td>
          <td><?= ucv_h($note) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Governing instruments — all five -->
    <div class="instruments-section">
      <p class="section-heading">Governing Instruments</p>
      <div class="instruments-grid-5">
        <div class="instrument-card">
          <div class="instrument-title instrument-title--supreme">① Supreme</div>
          <div class="instrument-name">Joint Venture Participation Agreement (JVPA)</div>
          <div class="instrument-note">All other instruments subordinate — cl.1.2(a)</div>
        </div>
        <div class="instrument-card">
          <div class="instrument-title">② Declaration</div>
          <div class="instrument-name">CJVM Hybrid Trust Declaration</div>
          <div class="instrument-note">Legal trust instrument — governs unit structure</div>
        </div>
        <div class="instrument-card">
          <div class="instrument-title">③ Sub-Trust A</div>
          <div class="instrument-name">Members Asset Pool Unit Trust Deed</div>
          <div class="instrument-note">Unit issuance, funds flow &amp; token recording</div>
        </div>
        <div class="instrument-card">
          <div class="instrument-title">④ Sub-Trust B</div>
          <div class="instrument-name">Beneficiary Distribution Trust Deed</div>
          <div class="instrument-note">Distribution stream &amp; Beneficial Unit rights</div>
        </div>
        <div class="instrument-card">
          <div class="instrument-title">⑤ Sub-Trust C</div>
          <div class="instrument-name">Discretionary Charitable Trust Deed</div>
          <div class="instrument-note">D Class Beneficial Unit Holder — charitable purposes</div>
        </div>
      </div>
      <p style="font-size:10.5px;color:#7a6a4e;line-height:1.6;">
        <strong style="color:#3a2e1a;">Governing law:</strong> South Australia, Australia &nbsp;·&nbsp;
        <strong style="color:#3a2e1a;">ABN:</strong> 61 734 327 831 &nbsp;·&nbsp;
        <strong style="color:#3a2e1a;">Registered office:</strong> Drake Village Resource Centre, Drake Village NSW 2469 &nbsp;·&nbsp;
        Wahlubal Country, Bundjalung Nation
      </p>
    </div>

  </div><!-- /doc-body -->

  <!-- Document footer -->
  <div class="doc-footer">
    <div class="footer-trustee">
      <div class="footer-trustee-name">Thomas Boyd Cunliffe</div>
      <div class="footer-trustee-role">Caretaker Trustee</div>
      <div class="footer-trustee-org">
        COGS of Australia Foundation<br>
        Drake Village NSW 2469 &nbsp;·&nbsp; members@cogsaustralia.org
      </div>
    </div>
    <div class="footer-seal">
      <div class="seal-circle">
        <div class="seal-text">COG$<br>FOUNDATION<br>ISSUED<br><?= ucv_h(date('Y', strtotime((string)($row['issue_date'] ?? 'today')))) ?></div>
      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

</body>
</html>

<?php
declare(strict_types=1);

/**
 * ParcelLandholderAgent — COGS Australia Foundation
 *
 * Verifies a landholder claim for Landholder COG$ (Class Lh) eligibility.
 *
 * Implements Compliance Plan §4.3, MD clauses 23.1, 23.2, 23.4, Geofencing Spec §8.3.
 *
 * Pipeline:
 *   1. Geoscape Cadastre API → parcel geometry + area in hectares
 *   2. Tenement adjacency check → does parcel overlap/adjoin a resource tenement zone?
 *   3. Country overlay check → NNTT / LALC flags → FNAC routing
 *   4. Holder type classification → freehold / LALC / PBC / native_title_group
 *   5. Token entitlement → ceil(hectares) × 1,000 Lh tokens (MD 12.4)
 *   6. Zero-cost eligibility → LALC / PBC get free issuance (MD 23.4.2)
 *   7. SHA-256 evidence hash → off-chain facts, on-chain proofs
 *   8. DB write → landholder_verifications, evidence_vault_entries, members
 *
 * Token formula (MD clause 12.4):
 *   max 1,000 Lh tokens per hectare or part thereof
 *   tokens_calculated = ceil(area_hectares) * 1000
 *
 * Eligibility criteria (MD clause 23.1):
 *   Land must overlap with or be adjacent to a resource tenement
 *   in which Sub-Trust A holds or targets shares.
 *
 * FNAC routing (MD 23.4.3):
 *   Required for ALL LALC, PBC, and native title holder claims.
 *   Required for any freehold parcel overlapping Country/NNTT zones.
 *
 * Environment variables (add to .env):
 *   GEOSCAPE_API_KEY            — shared with GnafAddressAgent
 *   GEOSCAPE_BASE_URL           — shared with GnafAddressAgent
 *   TENEMENT_BUFFER_METRES=500  — adjacency buffer around tenement zones
 *
 * Deploy to: _app/api/services/ParcelLandholderAgent.php
 */

class ParcelLandholderAgent
{
    private PDO    $db;
    private string $consumerKey;
    private string $consumerSecret;
    private string $tokenUrl;
    private string $baseUrl;
    private int    $tenementBufferMetres;
    private bool   $mockMode;
    private ?string $_cachedToken = null;
    private int     $_tokenExpiry = 0;

    // Holder types that always require FNAC routing (MD 23.4.3)
    private const FNAC_REQUIRED_TYPES = ['lalc', 'pbc', 'native_title_group'];

    // Holder types eligible for zero-cost issuance (MD 23.4.2)
    private const ZERO_COST_TYPES = ['lalc', 'pbc'];

    public function __construct(PDO $db)
    {
        $this->db                   = $db;
        $this->consumerKey          = $this->getEnv('GEOSCAPE_CONSUMER_KEY');
        $this->consumerSecret       = $this->getEnv('GEOSCAPE_CONSUMER_SECRET');
        $this->tokenUrl             = ''; // Not used — PSMA uses Consumer Key directly
        $this->baseUrl              = rtrim($this->getEnv('GEOSCAPE_BASE_URL') ?: 'https://api.psma.com.au', '/');
        $this->tenementBufferMetres = (int)($this->getEnv('TENEMENT_BUFFER_METRES') ?: '500');
        $this->mockMode             = $this->consumerKey === '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Verify a landholder claim and calculate token entitlement.
     *
     * @param int    $memberId       FK to members.id
     * @param string $memberNumber   16-digit member number
     * @param string $holderType     'freehold' | 'lalc' | 'pbc' | 'native_title_group'
     * @param string $lot            Lot number (e.g. '1')
     * @param string $plan           Plan number (e.g. 'DP12345')
     * @param string $jurisdiction   State code (e.g. 'NSW')
     * @param string $titleReference Certificate of Title ref (e.g. 'CT/12345/67')
     * @param string $parcelPid      Geoscape parcel PID (from GnafAddressAgent if known)
     * @return array                 Structured result
     */
    public function verify(
        int    $memberId,
        string $memberNumber,
        string $holderType,
        string $lot,
        string $plan,
        string $jurisdiction = 'NSW',
        string $titleReference = '',
        string $parcelPid = ''
    ): array {
        $startedAt = gmdate('Y-m-d H:i:s');

        try {
            $holderType = $this->normaliseHolderType($holderType);

            // Step 1 — Cadastre API: lot/plan → parcel geometry + area
            $cadastre = $this->fetchCadastreData($lot, $plan, $jurisdiction, $parcelPid);

            // Step 2 — Tenement adjacency: does parcel touch a resource zone?
            $tenement = $this->checkTenementAdjacency(
                $cadastre['parcel_geometry_wkt'] ?? null,
                (float)($cadastre['centroid_lat'] ?? 0),
                (float)($cadastre['centroid_lng'] ?? 0)
            );

            // Step 3 — Country overlay: NNTT / LALC flags → FNAC routing
            $country = $this->checkCountryOverlay(
                $cadastre['parcel_geometry_wkt'] ?? null,
                (float)($cadastre['centroid_lat'] ?? 0),
                (float)($cadastre['centroid_lng'] ?? 0)
            );

            // Step 4 — FNAC routing determination
            $fnacRequired = in_array($holderType, self::FNAC_REQUIRED_TYPES, true)
                         || $country['nntt_overlap']
                         || $country['lalc_boundary_overlap'];

            // Step 5 — Token entitlement (MD 12.4)
            $hectares         = (float)($cadastre['area_hectares'] ?? 0);
            $tokensCalculated = $hectares > 0 ? (int)ceil($hectares) * 1000 : 0;
            $zeroCostEligible = in_array($holderType, self::ZERO_COST_TYPES, true);

            // Step 6 — Determine verification status
            $status = $this->determineStatus(
                $cadastre, $tenement, $fnacRequired, $holderType
            );

            // Step 7 — Build evidence record and hash
            $evidenceRecord = $this->buildEvidenceRecord(
                $memberId, $memberNumber, $holderType, $lot, $plan,
                $jurisdiction, $titleReference, $cadastre, $tenement,
                $country, $fnacRequired, $hectares, $tokensCalculated,
                $zeroCostEligible, $status, $startedAt
            );
            $evidenceHash = hash('sha256',
                json_encode($evidenceRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );

            // Step 8 — Write to landholder_verifications
            $verificationId = $this->writeLandholderVerification(
                $memberId, $memberNumber, $holderType,
                $lot, $plan, $jurisdiction, $titleReference,
                $cadastre, $tenement, $country,
                $fnacRequired, $hectares, $tokensCalculated,
                $zeroCostEligible, $status, $evidenceHash
            );

            // Step 9 — Write to evidence_vault_entries
            $this->writeEvidenceVault(
                $memberId, $memberNumber, $verificationId,
                $evidenceHash, $status, $holderType,
                $tokensCalculated, $tenement
            );

            // Step 10 — Update members table
            if (in_array($status, ['cadastre_matched', 'title_verified', 'verified'], true)) {
                $this->updateMember(
                    $memberId, $holderType, $hectares,
                    $tokensCalculated, $zeroCostEligible,
                    $fnacRequired, $evidenceHash
                );
            }

            return [
                'success'               => true,
                'verification_id'       => $verificationId,
                'status'                => $status,
                'holder_type'           => $holderType,
                'lot'                   => $lot,
                'plan'                  => $plan,
                'jurisdiction'          => $jurisdiction,
                'parcel_pid'            => $cadastre['parcel_pid'] ?? null,
                'area_hectares'         => $hectares ?: null,
                'area_source'           => $cadastre['area_source'] ?? null,
                'tokens_calculated'     => $tokensCalculated,
                'zero_cost_eligible'    => $zeroCostEligible,
                'tenement_overlap'      => $tenement['overlap'],
                'tenement_zone_code'    => $tenement['zone_code'],
                'tenement_check_method' => $tenement['check_method'],
                'nntt_overlap'          => $country['nntt_overlap'],
                'lalc_boundary_overlap' => $country['lalc_boundary_overlap'],
                'fnac_routing_required' => $fnacRequired,
                'evidence_hash'         => $evidenceHash,
                'mock_mode'             => $this->mockMode,
                'requires_admin_review' => $status === 'manual_review',
                'requires_fnac'         => $fnacRequired,
                'verified_at'           => $status === 'verified' ? gmdate('Y-m-d H:i:s') : null,
                'note'                  => $this->buildStatusNote($status, $fnacRequired, $zeroCostEligible, $tokensCalculated),
            ];

        } catch (\Throwable $e) {
            error_log('[ParcelLandholderAgent] verify failed for member ' . $memberNumber . ': ' . $e->getMessage());
            return [
                'success'         => false,
                'status'          => 'manual_review',
                'error'           => $e->getMessage(),
                'mock_mode'       => $this->mockMode,
                'requires_admin_review' => true,
            ];
        }
    }

    /**
     * Re-check all verified landholders against current zone boundaries.
     * Called when a new zone is declared or tenement boundaries change.
     */
    public function batchRecheck(): array
    {
        $stmt = $this->db->query(
            "SELECT lv.id, lv.member_id, lv.member_number, lv.holder_type,
                    lv.lot, lv.plan, lv.jurisdiction, lv.parcel_pid,
                    lv.parcel_centroid_lat, lv.parcel_centroid_lng
             FROM landholder_verifications lv
             WHERE lv.status IN ('verified', 'title_verified', 'cadastre_matched')
               AND lv.parcel_centroid_lat IS NOT NULL"
        );

        $results = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lat = (float)$row['parcel_centroid_lat'];
            $lng = (float)$row['parcel_centroid_lng'];

            $tenement = $this->checkTenementAdjacency(null, $lat, $lng);
            $country  = $this->checkCountryOverlay(null, $lat, $lng);

            $fnacRequired = in_array($row['holder_type'], self::FNAC_REQUIRED_TYPES, true)
                         || $country['nntt_overlap']
                         || $country['lalc_boundary_overlap'];

            $this->db->prepare(
                'UPDATE landholder_verifications
                 SET tenement_overlap = ?, tenement_zone_id = ?, tenement_zone_code = ?,
                     nntt_overlap = ?, lalc_boundary_overlap = ?, fnac_routing_required = ?,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([
                $tenement['overlap'] ? 1 : 0,
                $tenement['zone_id'],
                $tenement['zone_code'],
                $country['nntt_overlap'] ? 1 : 0,
                $country['lalc_boundary_overlap'] ? 1 : 0,
                $fnacRequired ? 1 : 0,
                (int)$row['id'],
            ]);

            $results[] = [
                'member_number'    => $row['member_number'],
                'tenement_overlap' => $tenement['overlap'],
                'zone_code'        => $tenement['zone_code'],
                'fnac_required'    => $fnacRequired,
            ];
        }

        return ['checked' => count($results), 'results' => $results];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 1: GEOSCAPE CADASTRE API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch parcel geometry and area from Geoscape Cadastre API.
     * Returns parcel PID, area in hectares, centroid, and WKT geometry.
     *
     * Geoscape Cadastre endpoint:
     *   GET /v2/parcels?lotNumber={lot}&planNumber={plan}&stateTerritory={state}
     *
     * Source hierarchy rank 2 (Geofencing Spec §5):
     *   Relevant state/territory cadastre via Geoscape DataBox.
     */
    private function fetchCadastreData(
        string $lot, string $plan, string $jurisdiction, string $parcelPid
    ): array {
        if ($this->mockMode) {
            return $this->mockCadastreData($lot, $plan, $jurisdiction);
        }

        // If we already have a parcel PID (from GnafAddressAgent), use direct lookup
        if ($parcelPid !== '') {
            $url = $this->baseUrl . '/v2/parcels/' . urlencode($parcelPid);
            $response = $this->apiGet($url);
            if ($response) {
                return $this->parseCadastreResponse($response);
            }
        }

        // Otherwise search by lot/plan
        $url = $this->baseUrl . '/v2/parcels?' . http_build_query([
            'lotNumber'      => $lot,
            'planNumber'     => $plan,
            'stateTerritory' => $jurisdiction,
            'maxResults'     => 1,
        ]);

        $response = $this->apiGet($url);
        if (!$response || empty($response['parcels'])) {
            return [
                'parcel_pid'          => null,
                'area_hectares'       => null,
                'area_source'         => null,
                'centroid_lat'        => null,
                'centroid_lng'        => null,
                'parcel_geometry_wkt' => null,
                'found'               => false,
            ];
        }

        return $this->parseCadastreResponse($response['parcels'][0]);
    }

    private function parseCadastreResponse(array $data): array
    {
        // Geoscape Cadastre API response parsing
        $geometry = $data['geometry'] ?? [];
        $area     = $data['area'] ?? $data['areaHectares'] ?? null;
        $centroid = $data['centroid'] ?? $data['geocode'] ?? [];

        // Convert area — API may return m² or ha
        $areaHa = null;
        if ($area !== null) {
            $areaVal = (float)(is_array($area) ? ($area['value'] ?? $area) : $area);
            $unit    = is_array($area) ? strtolower($area['unit'] ?? 'ha') : 'ha';
            $areaHa  = $unit === 'm2' || $unit === 'm²' ? $areaVal / 10000 : $areaVal;
        }

        return [
            'parcel_pid'          => (string)($data['parcelId'] ?? $data['pid'] ?? ''),
            'area_hectares'       => $areaHa,
            'area_source'         => 'cadastre_api',
            'centroid_lat'        => isset($centroid['latitude'])  ? (float)$centroid['latitude']  : null,
            'centroid_lng'        => isset($centroid['longitude']) ? (float)$centroid['longitude'] : null,
            'parcel_geometry_wkt' => $geometry['wkt'] ?? null,
            'found'               => true,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 2: TENEMENT ADJACENCY CHECK
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the parcel overlaps or is adjacent to a resource tenement zone.
     *
     * Per MD clause 23.1: eligible if land "overlaps with or is adjacent to
     * a resource tenement in which Sub-Trust A holds or targets shares."
     *
     * Checks affected_zones WHERE zone_type IN ('project_impact', 'affected_zone').
     * Uses ST_Contains (overlap) OR ST_Distance <= buffer (adjacency).
     *
     * Note: MariaDB 10.6 supports ST_Distance for point-to-polygon distance.
     * For polygon-to-polygon distance we use centroid as proxy when WKT unavailable.
     */
    private function checkTenementAdjacency(
        ?string $parcelWkt, float $lat, float $lng
    ): array {
        $empty = [
            'overlap'      => false,
            'zone_id'      => null,
            'zone_code'    => null,
            'zone_name'    => null,
            'check_method' => null,
        ];

        if ($lat === 0.0 && $lng === 0.0) {
            return $empty;
        }

        try {
            // First try: ST_Contains — parcel centroid inside zone
            $stmt = $this->db->prepare(
                "SELECT id, zone_code, zone_name
                 FROM affected_zones
                 WHERE status = 'active'
                   AND zone_type IN ('project_impact', 'affected_zone')
                   AND (expires_at IS NULL OR expires_at > CURDATE())
                   AND ST_Contains(
                       geometry,
                       ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326)
                   )
                 ORDER BY zone_type = 'project_impact' DESC, id ASC
                 LIMIT 1"
            );
            $stmt->execute([$lng, $lat]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($zone) {
                return [
                    'overlap'      => true,
                    'zone_id'      => (int)$zone['id'],
                    'zone_code'    => $zone['zone_code'],
                    'zone_name'    => $zone['zone_name'],
                    'check_method' => 'spatial_query',
                ];
            }

            // Second try: ST_Distance <= buffer — adjacency
            // MariaDB ST_Distance works with SRID-aware geometries
            // Buffer in degrees approx: 500m ≈ 0.0045°
            $bufferDeg = $this->tenementBufferMetres / 111320.0;

            $adjStmt = $this->db->prepare(
                "SELECT id, zone_code, zone_name,
                        ST_Distance(
                            geometry,
                            ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326)
                        ) AS dist_deg
                 FROM affected_zones
                 WHERE status = 'active'
                   AND zone_type IN ('project_impact', 'affected_zone')
                   AND (expires_at IS NULL OR expires_at > CURDATE())
                 HAVING dist_deg <= ?
                 ORDER BY dist_deg ASC
                 LIMIT 1"
            );
            $adjStmt->execute([$lng, $lat, $bufferDeg]);
            $adjacent = $adjStmt->fetch(\PDO::FETCH_ASSOC);

            if ($adjacent) {
                return [
                    'overlap'      => true,
                    'zone_id'      => (int)$adjacent['id'],
                    'zone_code'    => $adjacent['zone_code'],
                    'zone_name'    => $adjacent['zone_name'],
                    'check_method' => 'buffer_check_' . $this->tenementBufferMetres . 'm',
                ];
            }

        } catch (\Throwable $e) {
            // Fall back to WKT polygon check
            error_log('[ParcelLandholderAgent] ST spatial check failed: ' . $e->getMessage());
            return $this->checkTenementFallback($lat, $lng);
        }

        return $empty;
    }

    private function checkTenementFallback(float $lat, float $lng): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT id, zone_code, zone_name, geometry_wkt
                 FROM affected_zones
                 WHERE status = 'active'
                   AND zone_type IN ('project_impact', 'affected_zone')
                   AND geometry_wkt IS NOT NULL"
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zone) {
                if ($this->pointInPolygonWkt($lat, $lng, (string)$zone['geometry_wkt'])
                    || $this->pointNearPolygonWkt($lat, $lng, (string)$zone['geometry_wkt'], $this->tenementBufferMetres)) {
                    return [
                        'overlap'      => true,
                        'zone_id'      => (int)$zone['id'],
                        'zone_code'    => $zone['zone_code'],
                        'zone_name'    => $zone['zone_name'],
                        'check_method' => 'wkt_fallback',
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[ParcelLandholderAgent] WKT fallback failed: ' . $e->getMessage());
        }

        return ['overlap' => false, 'zone_id' => null, 'zone_code' => null, 'zone_name' => null, 'check_method' => 'wkt_fallback'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 3: COUNTRY OVERLAY CHECK
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the parcel overlaps NNTT or LALC boundary zones.
     * Routes to FNAC consultation per MD 23.4.3 and Geofencing Spec §7.
     */
    private function checkCountryOverlay(
        ?string $parcelWkt, float $lat, float $lng
    ): array {
        $result = ['nntt_overlap' => false, 'lalc_boundary_overlap' => false];

        if ($lat === 0.0 && $lng === 0.0) {
            return $result;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT zone_type FROM affected_zones
                 WHERE status = 'active'
                   AND zone_type IN ('country_overlay')
                   AND ST_Contains(
                       geometry,
                       ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326)
                   )
                 LIMIT 5"
            );
            $stmt->execute([$lng, $lat]);

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zone) {
                // For now all country_overlay zones trigger both flags
                // Future: distinguish NNTT vs LALC by zone_code prefix or sub-type
                $result['nntt_overlap']          = true;
                $result['lalc_boundary_overlap'] = true;
            }
        } catch (\Throwable $e) {
            error_log('[ParcelLandholderAgent] country overlay check failed: ' . $e->getMessage());
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 4: STATUS DETERMINATION
    // ═══════════════════════════════════════════════════════════════════════

    private function determineStatus(
        array $cadastre, array $tenement,
        bool $fnacRequired, string $holderType
    ): string {
        // No parcel found → manual review
        if (!($cadastre['found'] ?? false) || !$cadastre['area_hectares']) {
            return 'manual_review';
        }

        // No tenement adjacency → ineligible under MD 23.1
        // Exception: LALC/PBC land is eligible regardless (MD 23.4.1)
        if (!$tenement['overlap'] && !in_array($holderType, ['lalc', 'pbc', 'native_title_group'], true)) {
            return 'manual_review'; // Admin must confirm eligibility or adjoin zone
        }

        // FNAC required → cannot proceed to 'verified' without endorsement
        if ($fnacRequired) {
            return 'fnac_pending';
        }

        // Cadastre matched, area known — ready for title verification
        return 'cadastre_matched';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 7: EVIDENCE RECORD
    // ═══════════════════════════════════════════════════════════════════════

    private function buildEvidenceRecord(
        int $memberId, string $memberNumber, string $holderType,
        string $lot, string $plan, string $jurisdiction, string $titleReference,
        array $cadastre, array $tenement, array $country,
        bool $fnacRequired, float $hectares, int $tokensCalculated,
        bool $zeroCostEligible, string $status, string $startedAt
    ): array {
        return [
            'agent'           => 'ParcelLandholderAgent',
            'version'         => '1.0',
            'member_id'       => $memberId,
            'member_number'   => $memberNumber,
            'holder_type'     => $holderType,
            'parcel' => [
                'lot'             => $lot,
                'plan'            => $plan,
                'jurisdiction'    => $jurisdiction,
                'title_reference' => $titleReference,
                'parcel_pid'      => $cadastre['parcel_pid'] ?? null,
                'area_hectares'   => $hectares,
                'area_source'     => $cadastre['area_source'] ?? null,
                'centroid_lat'    => $cadastre['centroid_lat'] ?? null,
                'centroid_lng'    => $cadastre['centroid_lng'] ?? null,
            ],
            'tenement' => [
                'overlap'      => $tenement['overlap'],
                'zone_code'    => $tenement['zone_code'],
                'check_method' => $tenement['check_method'] ?? null,
            ],
            'country' => [
                'nntt_overlap'          => $country['nntt_overlap'],
                'lalc_boundary_overlap' => $country['lalc_boundary_overlap'],
            ],
            'entitlement' => [
                'tokens_calculated'  => $tokensCalculated,
                'zero_cost_eligible' => $zeroCostEligible,
                'fnac_required'      => $fnacRequired,
            ],
            'status'       => $status,
            'mock_mode'    => $this->mockMode,
            'started_at'   => $startedAt,
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 8: WRITE landholder_verifications
    // ═══════════════════════════════════════════════════════════════════════

    private function writeLandholderVerification(
        int $memberId, string $memberNumber, string $holderType,
        string $lot, string $plan, string $jurisdiction, string $titleReference,
        array $cadastre, array $tenement, array $country,
        bool $fnacRequired, float $hectares, int $tokensCalculated,
        bool $zeroCostEligible, string $status, string $evidenceHash
    ): int {
        $lat = $cadastre['centroid_lat'] ?? null;
        $lng = $cadastre['centroid_lng'] ?? null;

        // Store parcel geometry if available
        $parcelGeom = null;
        if ($lat && $lng) {
            try {
                $ptStmt = $this->db->prepare(
                    "SELECT ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326) AS pt"
                );
                $ptStmt->execute([$lng, $lat]);
                $ptRow = $ptStmt->fetch(\PDO::FETCH_ASSOC);
                $parcelGeom = $ptRow['pt'] ?? null;
            } catch (\Throwable $e) {
                $parcelGeom = null;
            }
        }

        $verifiedAt = $status === 'verified' ? gmdate('Y-m-d H:i:s') : null;

        $this->db->prepare("
            INSERT INTO landholder_verifications
                (member_id, member_number, holder_type,
                 lot, plan, title_reference, parcel_pid, jurisdiction,
                 area_hectares, area_source, tokens_calculated,
                 zero_cost_eligible, parcel_geometry,
                 parcel_centroid_lat, parcel_centroid_lng,
                 tenement_overlap, tenement_zone_id, tenement_zone_code,
                 tenement_check_method, buffer_metres,
                 nntt_overlap, lalc_boundary_overlap, fnac_routing_required,
                 status, verified_by, evidence_hash,
                 created_at, updated_at, verified_at)
            VALUES
                (?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?,
                 ?, ?,
                 ?, ?, ?,
                 ?, ?,
                 ?, ?, ?,
                 ?, 'system', ?,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)
        ")->execute([
            $memberId, $memberNumber, $holderType,
            $lot ?: null, $plan ?: null, $titleReference ?: null,
            $cadastre['parcel_pid'] ?: null, $jurisdiction,
            $hectares ?: null, $cadastre['area_source'] ?? null, $tokensCalculated ?: null,
            $zeroCostEligible ? 1 : 0, $parcelGeom,
            $lat, $lng,
            $tenement['overlap'] ? 1 : 0,
            $tenement['zone_id'] ?? null,
            $tenement['zone_code'] ?? null,
            $tenement['check_method'] ?? null,
            $this->tenementBufferMetres,
            $country['nntt_overlap'] ? 1 : 0,
            $country['lalc_boundary_overlap'] ? 1 : 0,
            $fnacRequired ? 1 : 0,
            $status, $evidenceHash, $verifiedAt,
        ]);

        return (int)$this->db->lastInsertId();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 9: WRITE evidence_vault_entries
    // ═══════════════════════════════════════════════════════════════════════

    private function writeEvidenceVault(
        int $memberId, string $memberNumber, int $verificationId,
        string $evidenceHash, string $status, string $holderType,
        int $tokensCalculated, array $tenement
    ): void {
        $summary = sprintf(
            'Parcel/landholder verification for member %s (%s). Status: %s. Tokens calculated: %s. Tenement: %s.',
            $memberNumber,
            $holderType,
            $status,
            $tokensCalculated > 0 ? number_format($tokensCalculated) : 'pending',
            $tenement['zone_code'] ?? 'none'
        );

        try {
            $this->db->prepare("
                INSERT INTO evidence_vault_entries
                    (entry_type, subject_type, subject_id, subject_ref,
                     payload_hash, payload_summary, source_system,
                     created_by_type, created_at)
                VALUES
                    ('parcel_verification', 'member', ?, ?,
                     ?, ?, 'geoscape_cadastre',
                     'system', UTC_TIMESTAMP())
            ")->execute([$memberId, $memberNumber, $evidenceHash, $summary]);
        } catch (\Throwable $e) {
            error_log('[ParcelLandholderAgent] evidence_vault write failed: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 10: UPDATE members
    // ═══════════════════════════════════════════════════════════════════════

    private function updateMember(
        int $memberId, string $holderType, float $hectares,
        int $tokensCalculated, bool $zeroCostEligible,
        bool $fnacRequired, string $evidenceHash
    ): void {
        try {
            $this->db->prepare("
                UPDATE members SET
                    landholder_hectares          = ?,
                    landholder_tokens_calculated = ?,
                    landholder_holder_type       = ?,
                    landholder_zero_cost         = ?,
                    landholder_fnac_required     = ?,
                    landholder_verified_at       = UTC_TIMESTAMP(),
                    updated_at                   = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([
                $hectares ?: null,
                $tokensCalculated ?: null,
                $holderType,
                $zeroCostEligible ? 1 : 0,
                $fnacRequired ? 1 : 0,
                $memberId,
            ]);
        } catch (\Throwable $e) {
            error_log('[ParcelLandholderAgent] members update failed: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GEOMETRY HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function pointInPolygonWkt(float $lat, float $lng, string $wkt): bool
    {
        if ($wkt === '' || !preg_match('/POLYGON\s*\(\s*\(\s*([^)]+)\s*\)\s*\)/i', $wkt, $m)) {
            return false;
        }
        $pairs = array_map('trim', explode(',', $m[1]));
        $points = [];
        foreach ($pairs as $pair) {
            $xy = preg_split('/\s+/', trim($pair));
            if (count($xy) >= 2) {
                $points[] = [(float)$xy[0], (float)$xy[1]];
            }
        }
        if (count($points) < 3) return false;
        $inside = false;
        $n = count($points);
        $j = $n - 1;
        for ($i = 0; $i < $n; $i++) {
            if ((($points[$i][1] > $lat) !== ($points[$j][1] > $lat))
                && ($lng < ($points[$j][0] - $points[$i][0]) * ($lat - $points[$i][1])
                         / ($points[$j][1] - $points[$i][1]) + $points[$i][0])) {
                $inside = !$inside;
            }
            $j = $i;
        }
        return $inside;
    }

    /**
     * Check if a point is within $bufferMetres of any vertex of a WKT polygon.
     * Rough adjacency check — good enough for 500m buffer screening.
     */
    private function pointNearPolygonWkt(float $lat, float $lng, string $wkt, int $bufferMetres): bool
    {
        if ($wkt === '' || !preg_match('/POLYGON\s*\(\s*\(\s*([^)]+)\s*\)\s*\)/i', $wkt, $m)) {
            return false;
        }
        $bufferDeg = $bufferMetres / 111320.0;
        $pairs = array_map('trim', explode(',', $m[1]));
        foreach ($pairs as $pair) {
            $xy = preg_split('/\s+/', trim($pair));
            if (count($xy) >= 2) {
                $dx = abs((float)$xy[0] - $lng);
                $dy = abs((float)$xy[1] - $lat);
                if (sqrt($dx * $dx + $dy * $dy) <= $bufferDeg) {
                    return true;
                }
            }
        }
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MOCK MODE
    // ═══════════════════════════════════════════════════════════════════════

    private function mockCadastreData(string $lot, string $plan, string $jurisdiction): array
    {
        // Drake area mock — 780 Sugarbag Road West is approximately 200-400 ha rural
        // AZ-DRAKE-001 boundary centre: lat -29.175, lng 152.375
        $isDrakeNSW = strtoupper($jurisdiction) === 'NSW'
                   && (str_contains(strtolower($plan), 'dp') || $lot !== '');

        $mockHa  = $isDrakeNSW ? 320.5 : 50.0;
        $mockLat = $isDrakeNSW ? -29.18 : -33.87;
        $mockLng = $isDrakeNSW ?  152.37 : 151.21;
        $mockPid = 'PARCEL-MOCK-' . strtoupper(substr(md5("{$lot}{$plan}{$jurisdiction}"), 0, 8));

        return [
            'parcel_pid'          => $mockPid,
            'area_hectares'       => $mockHa,
            'area_source'         => 'mock_cadastre',
            'centroid_lat'        => $mockLat,
            'centroid_lng'        => $mockLng,
            'parcel_geometry_wkt' => null,
            'found'               => true,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HTTP + ENV HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function apiGet(string $url): ?array
    {
        // PSMA/Geoscape: Consumer Key sent directly as Authorization header value
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->consumerKey,
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT      => 'COGS-Australia-ParcelAgent/1.0',
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($err || $code < 200 || $code >= 300) {
            error_log("ParcelLandholderAgent API error: HTTP $code $err URL: $url");
            return null;
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function getEnv(string $name): string
    {
        $val = getenv($name);
        if (!$val && isset($_ENV[$name])) $val = $_ENV[$name];
        if (!$val && function_exists('env')) $val = env($name, '');
        return (string)($val ?: '');
    }

    private function normaliseHolderType(string $type): string
    {
        $map = [
            'freehold'           => 'freehold',
            'lalc'               => 'lalc',
            'pbc'                => 'pbc',
            'native_title'       => 'native_title_group',
            'native_title_group' => 'native_title_group',
        ];
        return $map[strtolower(trim($type))] ?? 'freehold';
    }

    private function buildStatusNote(
        string $status, bool $fnacRequired,
        bool $zeroCostEligible, int $tokens
    ): string {
        return match ($status) {
            'cadastre_matched' => sprintf(
                'Parcel area confirmed. %s Lh tokens calculated (%s). Title verification required before issuance.',
                number_format($tokens),
                $zeroCostEligible ? 'Zero-cost eligible (LALC/PBC)' : '$4.00/token'
            ),
            'fnac_pending' => sprintf(
                '%s Lh tokens calculated. FNAC endorsement required before issuance can proceed (MD 23.4.3).',
                number_format($tokens)
            ),
            'manual_review' => 'Parcel not found in cadastre or no tenement adjacency confirmed. Admin review required.',
            'verified'      => sprintf('%s Lh tokens ready for issuance.', number_format($tokens)),
            default         => 'Status: ' . $status,
        };
    }
}

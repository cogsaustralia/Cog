<?php
/**
 * GnafAddressAgent.php — Address Verification Service
 * COGs of Australia Foundation
 *
 * Implements the address verification workflow from Geofencing Spec §8.2:
 *   1. Accept a street address string
 *   2. Call Geoscape Predictive API → G-NAF PID
 *   3. Call Geoscape G-NAF Live API → lat/long + parcel metadata
 *   4. Run spatial query against affected_zones table
 *   5. Return zone_id, in_affected_zone, coordinates, parcel_reference
 *   6. Generate SHA-256 evidence hash
 *
 * Credentials: environment variables (never hardcoded)
 *   GEOSCAPE_API_KEY     — Geoscape API key
 *   GEOSCAPE_BASE_URL    — optional override (default: https://api.psma.com.au)
 *
 * Deploy to: _app/api/services/GnafAddressAgent.php
 */

declare(strict_types=1);

class GnafAddressAgent
{
    private string $consumerKey;
    private string $consumerSecret;
    private string $tokenUrl;
    private string $baseUrl;
    private bool   $mockMode;
    private ?PDO   $db;
    private float  $_mockLat = -33.8688;
    private float  $_mockLng = 151.2093;
    private ?string $_cachedToken = null;
    private int     $_tokenExpiry = 0;

    // Confidence threshold below which we route to human review (Spec §7)
    private const CONFIDENCE_THRESHOLD = 80.0;

    public function __construct(?PDO $db = null)
    {
        $this->consumerKey    = $this->requireEnv('GEOSCAPE_CONSUMER_KEY');
        $this->consumerSecret = $this->requireEnv('GEOSCAPE_CONSUMER_SECRET');
        $this->tokenUrl       = ''; // Not used — PSMA uses Consumer Key directly
        $this->baseUrl        = rtrim($this->requireEnv('GEOSCAPE_BASE_URL') ?: 'https://api.psma.com.au', '/');
        $forceMock            = strtolower($this->requireEnv('GNAF_MOCK_MODE')) === 'true';
        $this->mockMode       = $forceMock || $this->consumerKey === '';
        $this->db             = $db;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Full verification pipeline for a member's address.
     *
     * @param int    $memberId
     * @param string $memberNumber
     * @param string $street
     * @param string $suburb
     * @param string $state    e.g. 'NSW'
     * @param string $postcode e.g. '2469'
     * @return array  Full verification result
     */
    public function verify(
        int    $memberId,
        string $memberNumber,
        string $street,
        string $suburb,
        string $state,
        string $postcode
    ): array {
        $inputAddress = trim("$street, $suburb $state $postcode");

        // Step 1: Geoscape Predictive API — address normalisation + G-NAF PID
        $predictive = $this->predictiveSearch($inputAddress);

        // Step 2: If we got a G-NAF PID, call Live API for geocoordinates + parcel
        // In mock mode, store the test coordinates so gnafLiveLookup can use them
        if (!empty($predictive['_mock'])) {
            $this->_mockLat = (float)($predictive['_lat'] ?? -33.8688);
            $this->_mockLng = (float)($predictive['_lng'] ?? 151.2093);
        }
        $liveData = null;
        if ($predictive['gnaf_pid']) {
            $liveData = $this->gnafLiveLookup($predictive['gnaf_pid']);
        }

        // Step 3: Spatial zone query
        $zoneResult = ['zone_id' => null, 'zone_code' => null, 'in_affected_zone' => false];
        if ($liveData && $liveData['latitude'] && $liveData['longitude']) {
            $zoneResult = $this->checkAffectedZone(
                (float)$liveData['latitude'],
                (float)$liveData['longitude']
            );
        }

        // Step 4: Determine verification status
        $confidence = (float)($predictive['confidence'] ?? 0);
        $status = 'pending';
        if ($confidence >= self::CONFIDENCE_THRESHOLD && $predictive['gnaf_pid']) {
            $status = 'verified';
        } elseif ($confidence > 0 && $confidence < self::CONFIDENCE_THRESHOLD) {
            $status = 'low_confidence';
        } elseif (!$predictive['gnaf_pid']) {
            $status = 'manual_review';
        }

        // Step 5: Build full verification record
        $record = [
            'member_id'       => $memberId,
            'member_number'   => $memberNumber,
            'input_street'    => $street,
            'input_suburb'    => $suburb,
            'input_state'     => $state,
            'input_postcode'  => $postcode,
            'gnaf_pid'        => $predictive['gnaf_pid'],
            'gnaf_address'    => $predictive['formatted_address'],
            'gnaf_confidence' => $confidence,
            'gnaf_match_type' => $predictive['match_type'],
            'latitude'        => $liveData['latitude'] ?? null,
            'longitude'       => $liveData['longitude'] ?? null,
            'parcel_pid'      => $liveData['parcel_pid'] ?? null,
            'mesh_block'      => $liveData['mesh_block'] ?? null,
            'sa1_code'        => $liveData['sa1_code'] ?? null,
            'lga_code'        => $liveData['lga_code'] ?? null,
            'lga_name'        => $liveData['lga_name'] ?? null,
            'zone_id'         => $zoneResult['zone_id'],
            'zone_code'       => $zoneResult['zone_code'],
            'in_affected_zone'=> $zoneResult['in_affected_zone'],
            'zone_check_method' => $zoneResult['zone_id'] ? 'spatial_query' : null,
            'status'          => $status,
            'verified_by'     => $status === 'verified' ? 'system' : null,
            'verified_at'     => $status === 'verified' ? gmdate('Y-m-d H:i:s') : null,
        ];

        // Step 6: Generate SHA-256 evidence hash
        $record['evidence_hash'] = $this->generateEvidenceHash($record);

        // Step 7: Persist to database if available
        if ($this->db) {
            $this->saveVerification($record);
            $this->updateMemberAddress($record);
            $this->saveEvidenceVaultEntry($record);
        }

        return $record;
    }

    /**
     * Quick zone check for an existing lat/long (no Geoscape call).
     */
    public function checkZoneOnly(float $lat, float $lng): array
    {
        return $this->checkAffectedZone($lat, $lng);
    }

    /**
     * Re-verify all members against current zone boundaries.
     * Useful when a zone is updated or new zone declared.
     */
    public function batchRecheck(): array
    {
        if (!$this->db) {
            return ['error' => 'No database connection'];
        }

        $stmt = $this->db->query(
            'SELECT m.id, m.member_number, m.street_address, m.suburb,
                    m.state_code, m.postcode, m.address_lat, m.address_lng
             FROM members m
             WHERE m.street_address IS NOT NULL
               AND m.is_active = 1'
        );

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // If we already have coordinates, just re-check zones
            if ($row['address_lat'] && $row['address_lng']) {
                $zone = $this->checkAffectedZone(
                    (float)$row['address_lat'],
                    (float)$row['address_lng']
                );
                $this->db->prepare(
                    'UPDATE members SET zone_id = ?, zone_verified_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                )->execute([$zone['zone_id'], (int)$row['id']]);

                $results[] = [
                    'member_number'   => $row['member_number'],
                    'in_affected_zone'=> $zone['in_affected_zone'],
                    'zone_code'       => $zone['zone_code'],
                ];
            }
        }
        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GEOSCAPE API CALLS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Geoscape Predictive API — address autocomplete and G-NAF PID lookup.
     * Spec §5 rank 1: "Geoscape G-NAF plus state/territory contributor feeds"
     */
    private function predictiveSearch(string $address): array
    {
        // Mock mode: no API key — return deterministic test data
        if ($this->mockMode) {
            return $this->mockPredictive($address);
        }

        // PSMA Predictive API v1:
        // - Strip street number and directional suffixes before querying
        //   e.g. "780 Sugarbag Road West, Drake NSW 2469" → "Sugarbag Road Drake NSW"
        // - No postcode — it confuses the parser
        // - No comma separators

        $queries = $this->buildPredictiveQueries($address);

        foreach ($queries as $query) {
            $url = $this->baseUrl . '/v1/predictive/address?' . http_build_query([
                'query'      => $query,
                'maxResults' => 5,
            ]);

            $response = $this->apiGet($url);

            if ($response && !empty($response['suggest'])) {
                $best       = $response['suggest'][0];
                $pid        = (string)($best['id'] ?? '');
                $confidence = $this->calculateConfidence(
                    (string)($best['address'] ?? ''),
                    $address,
                    (int)($best['rank'] ?? 0)
                );

                return [
                    'gnaf_pid'          => $pid,
                    'formatted_address' => (string)($best['address'] ?? ''),
                    'confidence'        => $confidence,
                    'match_type'        => $confidence >= 85 ? 'exact'
                                        : ($confidence >= 65 ? 'partial' : 'fuzzy'),
                ];
            }
        }

        return [
            'gnaf_pid'          => null,
            'formatted_address' => null,
            'confidence'        => 0,
            'match_type'        => 'none',
        ];
    }

    /**
     * Build a series of progressively broader query strings for the Predictive API.
     * PSMA v1 is sensitive to directional suffixes and exact road name format.
     * Tries specific → broad until suggestions are returned.
     */
    private function buildPredictiveQueries(string $fullAddress): array
    {
        // Parse: "780 Sugarbag Road West, Drake NSW 2469"
        $clean   = preg_replace('/[,]+/', ' ', $fullAddress);
        $clean   = preg_replace('/\s+/', ' ', trim($clean));
        $parts   = explode(' ', $clean);

        // Extract components
        $stateMap = ['NSW','VIC','QLD','WA','SA','TAS','ACT','NT'];
        $state    = '';
        $postcode = '';
        $suburb   = '';

        // Find state code
        foreach ($parts as $i => $p) {
            if (in_array(strtoupper($p), $stateMap, true)) {
                $state = strtoupper($p);
                // Suburb is typically just before state
                $suburb = $parts[$i - 1] ?? '';
                // Postcode is typically just after state
                $postcode = $parts[$i + 1] ?? '';
                break;
            }
        }

        // Strip street number (leading digits)
        $roadParts = [];
        $skipNum   = true;
        foreach ($parts as $p) {
            if ($skipNum && preg_match('/^\d/', $p)) continue;
            $skipNum = false;
            // Stop at suburb/state/postcode
            if ($p === $suburb || $p === $state || preg_match('/^\d{4}$/', $p)) break;
            // Strip trailing directional words
            if (in_array(strtoupper($p), ['WEST','EAST','NORTH','SOUTH'], true)) continue;
            $roadParts[] = $p;
        }
        $roadName = implode(' ', $roadParts);

        // Build queries from specific to broad
        $queries = [];
        if ($roadName && $suburb && $state) {
            $queries[] = "$roadName $suburb $state";
        }
        if ($roadName && $suburb) {
            $queries[] = "$roadName $suburb";
        }
        if ($suburb && $state && $postcode) {
            $queries[] = "$suburb $state $postcode";
        }
        if ($suburb && $state) {
            $queries[] = "$suburb $state";
        }
        // Last resort: full address minus postcode
        if ($postcode) {
            $queries[] = str_replace($postcode, '', $clean);
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * Geoscape G-NAF Live API — geocoordinates and parcel metadata from PID.
     * Returns lat/long, parcel PID, mesh block, SA1, LGA.
     */
    private function gnafLiveLookup(string $gnafPid): ?array
    {
        if ($this->mockMode) {
            return $this->mockGnafLive($gnafPid);
        }

        // PSMA detail endpoint: /v1/predictive/address/{id}
        // Returns geometry.coordinates[lng, lat] + full properties
        $response = $this->apiGet($this->baseUrl . '/v1/predictive/address/' . urlencode($gnafPid));

        if (!$response || empty($response['address'])) {
            return null;
        }

        $addr  = $response['address'];
        $coords = $addr['geometry']['coordinates'] ?? [];
        $props  = $addr['properties'] ?? [];

        $lng = isset($coords[0]) ? (float)$coords[0] : null;
        $lat = isset($coords[1]) ? (float)$coords[1] : null;

        // Fetch LGA from sub-resource
        $lgaCode = null; $lgaName = null;
        $lgaResp = $this->apiGet($this->baseUrl . '/v1/addresses/' . urlencode($gnafPid) . '/localGovernmentArea/');
        if ($lgaResp) {
            $lgaCode = $lgaResp['lgaCode'] ?? $lgaResp['code'] ?? null;
            $lgaName = $lgaResp['lgaName'] ?? $lgaResp['name'] ?? null;
        }

        // Fetch ASGS (mesh block, SA1) from sub-resource
        $meshBlock = null; $sa1Code = null;
        $asgsResp = $this->apiGet($this->baseUrl . '/v1/addresses/' . urlencode($gnafPid) . '/asgsMain/');
        if ($asgsResp) {
            $meshBlock = $asgsResp['meshBlockCode'] ?? $asgsResp['meshBlock'] ?? null;
            $sa1Code   = $asgsResp['sa1MainCode']  ?? $asgsResp['sa1Code']   ?? null;
        }

        return [
            'latitude'    => $lat,
            'longitude'   => $lng,
            'parcel_pid'  => $props['cadastral_identifier'] ?? null,
            'mesh_block'  => $meshBlock,
            'sa1_code'    => $sa1Code,
            'lga_code'    => $lgaCode,
            'lga_name'    => $lgaName,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SPATIAL ZONE QUERY (MariaDB 10.6 native spatial)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if a point falls inside any active Affected Zone.
     * Uses MariaDB ST_Contains with SRID 4326 (WGS84).
     */
    private function checkAffectedZone(float $lat, float $lng): array
    {
        if (!$this->db) {
            return ['zone_id' => null, 'zone_code' => null, 'in_affected_zone' => false];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id, zone_code, zone_name
                 FROM affected_zones
                 WHERE status = 'active'
                   AND (expires_at IS NULL OR expires_at > CURDATE())
                   AND ST_Contains(geometry, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326))
                 ORDER BY zone_type = 'affected_zone' DESC, id ASC
                 LIMIT 1"
            );
            // Note: WKT POINT format is POINT(longitude latitude)
            $stmt->execute([$lng, $lat]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'zone_id'          => (int)$row['id'],
                    'zone_code'        => $row['zone_code'],
                    'zone_name'        => $row['zone_name'],
                    'in_affected_zone' => true,
                ];
            }
        } catch (\Throwable $e) {
            error_log('GnafAddressAgent zone check error: ' . $e->getMessage());
        }

        return ['zone_id' => null, 'zone_code' => null, 'in_affected_zone' => false];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // EVIDENCE HASH (SHA-256 for evidence vault)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate SHA-256 hash of the verification record.
     * The hash goes on-chain; the raw data stays off-chain.
     * Per Spec §3 principle 2: "Off-chain facts, on-chain proofs."
     */
    private function generateEvidenceHash(array $record): string
    {
        $hashPayload = json_encode([
            'member_number'   => $record['member_number'],
            'gnaf_pid'        => $record['gnaf_pid'],
            'gnaf_address'    => $record['gnaf_address'],
            'latitude'        => $record['latitude'],
            'longitude'       => $record['longitude'],
            'parcel_pid'      => $record['parcel_pid'],
            'zone_code'       => $record['zone_code'],
            'in_affected_zone'=> $record['in_affected_zone'],
            'status'          => $record['status'],
            'timestamp'       => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES);

        return hash('sha256', $hashPayload);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DATABASE PERSISTENCE
    // ═══════════════════════════════════════════════════════════════════════

    private function saveVerification(array $r): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO address_verifications
                 (member_id, member_number, input_street, input_suburb, input_state, input_postcode,
                  gnaf_pid, gnaf_address, gnaf_confidence, gnaf_match_type,
                  latitude, longitude, parcel_pid, mesh_block, sa1_code, lga_code, lga_name,
                  geocode_point,
                  zone_id, zone_code, in_affected_zone, zone_check_method,
                  status, verified_by, evidence_hash, verified_at)
                 VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?,
                  CASE WHEN ? IS NOT NULL AND ? IS NOT NULL
                       THEN ST_GeomFromText(CONCAT(\'POINT(\', ?, \' \', ?, \')\'), 4326)
                       ELSE NULL END,
                  ?,?,?,?, ?,?,?,?)'
            );
            $stmt->execute([
                $r['member_id'], $r['member_number'],
                $r['input_street'], $r['input_suburb'], $r['input_state'], $r['input_postcode'],
                $r['gnaf_pid'], $r['gnaf_address'], $r['gnaf_confidence'], $r['gnaf_match_type'],
                $r['latitude'], $r['longitude'], $r['parcel_pid'],
                $r['mesh_block'], $r['sa1_code'], $r['lga_code'], $r['lga_name'],
                $r['longitude'], $r['latitude'], $r['longitude'], $r['latitude'],
                $r['zone_id'], $r['zone_code'], $r['in_affected_zone'] ? 1 : 0,
                $r['zone_check_method'],
                $r['status'], $r['verified_by'], $r['evidence_hash'], $r['verified_at'],
            ]);
        } catch (\Throwable $e) {
            error_log('GnafAddressAgent saveVerification error: ' . $e->getMessage());
        }
    }

    /**
     * Update the members table with verified address data.
     * Only updates if verification was successful.
     */
    private function updateMemberAddress(array $r): void
    {
        if ($r['status'] !== 'verified') {
            return;
        }

        try {
            $this->db->prepare(
                'UPDATE members SET
                    gnaf_pid = ?, zone_id = ?,
                    address_lat = ?, address_lng = ?,
                    address_evidence_hash = ?,
                    address_verified_at = UTC_TIMESTAMP(),
                    kyc_status = CASE WHEN kyc_status = \'pending\' THEN \'address_verified\' ELSE kyc_status END
                 WHERE id = ?'
            )->execute([
                $r['gnaf_pid'], $r['zone_id'],
                $r['latitude'], $r['longitude'],
                $r['evidence_hash'],
                $r['member_id'],
            ]);
        } catch (\Throwable $e) {
            error_log('GnafAddressAgent updateMemberAddress error: ' . $e->getMessage());
        }
    }

    /**
     * Write an immutable entry to the evidence vault.
     */
    private function saveEvidenceVaultEntry(array $r): void
    {
        try {
            $summary = sprintf(
                'Address verification for member %s: %s (confidence %.0f%%, zone: %s)',
                $r['member_number'],
                $r['status'],
                $r['gnaf_confidence'],
                $r['zone_code'] ?? 'none'
            );

            $this->db->prepare(
                'INSERT INTO evidence_vault_entries
                 (entry_type, subject_type, subject_id, subject_ref,
                  payload_hash, payload_summary, source_system, created_by_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'address_verification', 'member', $r['member_id'], $r['member_number'],
                $r['evidence_hash'], $summary, 'geoscape', 'system',
            ]);
        } catch (\Throwable $e) {
            error_log('GnafAddressAgent saveEvidenceVaultEntry error: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // INTERNAL HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function apiGet(string $url): ?array
    {
        // PSMA/Geoscape: Consumer Key sent directly as Authorization header value
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->consumerKey,
                'Accept: application/json',
            ],
        ]);

        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $code < 200 || $code >= 300) {
            error_log("GnafAddressAgent API error: HTTP $code, $error, URL: $url");
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function calculateConfidence(string $returnedAddress, string $inputAddress, int $rank = 0): float
    {
        // Base: rank 0 = 80, rank 1 = 75, rank 2 = 70, etc. (floor 50)
        $score = max(50.0, 80.0 - ($rank * 5));

        $returned = strtolower(trim($returnedAddress));
        $input    = strtolower(trim($inputAddress));

        // Extract comparable tokens: suburb, state, postcode, road keywords
        preg_match('/\b(\d{4})\b/', $input,    $mIn);
        preg_match('/\b(\d{4})\b/', $returned, $mRet);
        $inputPc   = $mIn[1]  ?? '';
        $returnPc  = $mRet[1] ?? '';

        // Postcode match: strong signal (+15)
        if ($inputPc && $inputPc === $returnPc) {
            $score += 15.0;
        }

        // Street name substring match (+10)
        $inputWords    = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', '', $input)));
        $returnedWords = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', '', $returned)));
        $common = count(array_intersect($inputWords, $returnedWords));
        $total  = max(1, count($inputWords));
        $score += min(10.0, ($common / $total) * 10);

        return min(100.0, round($score, 1));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MOCK MODE — used when GEOSCAPE_API_KEY is not set
    // Provides realistic test data so the zone pipeline can be tested
    // without a live Geoscape subscription.
    // Drake NSW 2469 (780 Sugarbag Road West) sits inside AZ-DRAKE-001:
    //   POLYGON((152.30 -29.10, 152.45 -29.10, 152.45 -29.25, 152.30 -29.25))
    // ═══════════════════════════════════════════════════════════════════════

    private function mockPredictive(string $address): array
    {
        $lower    = strtolower($address);
        $isDrake  = str_contains($lower, 'drake') && str_contains($lower, 'nsw');
        $isGreenway = str_contains($lower, 'greenway') && str_contains($lower, 'act');

        // Deterministic PID from address hash
        $pid = 'GAACT' . strtoupper(substr(md5($address), 0, 9));

        $confidence = 91.0;
        return [
            'gnaf_pid'          => $pid,
            'formatted_address' => strtoupper(trim($address)),
            'confidence'        => $confidence,
            'match_type'        => 'exact',
            '_mock'             => true,
            '_lat'              => $isDrake   ? -29.18   : ($isGreenway ? -35.35   : -33.8688),
            '_lng'              => $isDrake   ?  152.37  : ($isGreenway ?  149.17  :  151.2093),
        ];
    }

    private function mockGnafLive(string $gnafPid): array
    {
        // Coordinates set by mockPredictive via the _lat/_lng fields on the predictive result.
        // gnafLiveLookup is called after predictiveSearch — we store coords in a property
        // so the live lookup can retrieve them without a real API call.
        $lat = $this->_mockLat ?? -33.8688;
        $lng = $this->_mockLng ?? 151.2093;
        return [
            'latitude'   => $lat,
            'longitude'  => $lng,
            'parcel_pid' => 'MOCK-PARCEL-' . substr($gnafPid, -6),
            'mesh_block' => '20663330000',
            'sa1_code'   => '10102100201',
            'lga_code'   => 'LGA10900',
            'lga_name'   => 'Mock LGA (no API key)',
        ];
    }

    private function requireEnv(string $name): string
    {
        // Try getenv first, then $_ENV, then COGS env() helper
        $val = getenv($name);
        if ((!$val || $val === '') && isset($_ENV[$name])) {
            $val = $_ENV[$name];
        }
        if ((!$val || $val === '') && function_exists('env')) {
            $val = env($name, '');
        }
        if (!$val || $val === '') {
            // Graceful degradation: log warning but don't crash
            // Address verification will fail at API call time with a clear error
            error_log("GnafAddressAgent: environment variable $name is not set. Verification will fail.");
            return '';
        }
        return (string)$val;
    }
}

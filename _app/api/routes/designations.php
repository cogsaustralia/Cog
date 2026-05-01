<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Sub-action dispatch ───────────────────────────────────────────────────────
// Route: designations/public  → public milestone feed (no auth)
// Route: designations/canvass → member canvass sentiment summary (auth required)
$subAction = trim((string)($id ?? ''), '/');

// ── designations/public ───────────────────────────────────────────────────────
if ($subAction === 'public') {

    require_once __DIR__ . '/../services/SimpleCache.php';
    $cache    = new SimpleCache('/tmp/cogs_cache');
    $cacheKey = 'designations_public';
    $cached   = $cache->get($cacheKey);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    try {
        $db = getDB();

        $designations = $db->query(
            'SELECT id, company_name, asx_code, designation_status,
                    strategy_version, strategy_issued_date,
                    first_nations_engagement_summary, esg_rationale_summary,
                    public_display_order
             FROM poor_esg_target_designations
             WHERE is_active = 1
             ORDER BY public_display_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($designations)) {
            $result = ['success' => true, 'data' => []];
            echo json_encode($result);
            exit;
        }

        // Attach milestones to each designation
        $ids = array_map(fn($d) => (int)$d['id'], $designations);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $milestones = $db->prepare(
            "SELECT designation_id, milestone_key, milestone_label,
                    milestone_status, completed_date, status_note, display_order
             FROM designation_milestones
             WHERE designation_id IN ($placeholders)
             ORDER BY designation_id, display_order"
        );
        $milestones->execute($ids);
        $msRows = $milestones->fetchAll(PDO::FETCH_ASSOC);

        // Index milestones by designation_id
        $msMap = [];
        foreach ($msRows as $ms) {
            $msMap[(int)$ms['designation_id']][] = $ms;
        }

        // Build response
        $data = [];
        foreach ($designations as $d) {
            $data[] = [
                'id'                               => (int)$d['id'],
                'company_name'                     => $d['company_name'],
                'asx_code'                         => $d['asx_code'],
                'designation_status'               => $d['designation_status'],
                'strategy_version'                 => $d['strategy_version'],
                'strategy_issued_date'             => $d['strategy_issued_date'],
                'first_nations_engagement_summary' => $d['first_nations_engagement_summary'],
                'esg_rationale_summary'            => $d['esg_rationale_summary'],
                'milestones'                       => $msMap[(int)$d['id']] ?? [],
            ];
        }

        $result = ['success' => true, 'data' => $data];
        $cache->set($cacheKey, $result, 3600); // 1-hour TTL — milestones change rarely
        echo json_encode($result);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error.']);
    }
    exit;
}

// ── designations/canvass — member canvass sentiment summary ───────────────────
if ($subAction === 'canvass') {

    $principal = getAuthPrincipal();
    if (!$principal) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit;
    }

    try {
        $db = getDB();

        $rows = $db->query(
            'SELECT d.company_name, d.asx_code,
                    mvs.canvass_sentiment,
                    COUNT(*) AS response_count
             FROM member_voice_submissions mvs
             JOIN poor_esg_target_designations d
               ON d.id = mvs.canvass_designation_id
             WHERE mvs.canvass_designation_id IS NOT NULL
               AND mvs.canvass_sentiment IS NOT NULL
             GROUP BY d.id, mvs.canvass_sentiment
             ORDER BY d.public_display_order, mvs.canvass_sentiment'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Pivot into per-designation summary
        $summary = [];
        foreach ($rows as $r) {
            $key = $r['asx_code'];
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'company_name' => $r['company_name'],
                    'asx_code'     => $r['asx_code'],
                    'sentiments'   => [],
                    'total'        => 0,
                ];
            }
            $summary[$key]['sentiments'][$r['canvass_sentiment']] = (int)$r['response_count'];
            $summary[$key]['total'] += (int)$r['response_count'];
        }

        apiSuccess(['designations' => array_values($summary)]);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error.']);
    }
    exit;
}

// Unknown sub-action
http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Unknown designations action.']);

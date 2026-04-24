<?php
/**
 * vault-hubs.php — Management Hub endpoints
 *
 * Included by _app/api/routes/vault.php. All handlers require snft auth.
 * Conventions (match vault.php):
 *   requireAuth('snft')  → principal
 *   getDB()              → PDO
 *   jsonBody()           → decoded POST body
 *   apiSuccess/apiError  → JSON responses
 *   requireMethod(...)   → 405 if wrong verb
 *
 * Tables used:
 *   members.participation_answers (JSON) — area enrolment
 *   members.hub_roster_visible    (tinyint) — roster opt-out
 *   partner_op_threads            — per-area forum threads
 *   partner_op_replies            — thread replies
 *   partner_op_broadcast_reads    — read receipts
 *   hub_projects                  — per-area projects
 *   hub_project_participants      — project opt-ins
 *   hub_project_comments          — per-project discussion
 *   v_hub_roster / v_hub_projects_live / v_hub_activity / v_hub_mainspring_summary
 */

declare(strict_types=1);

/** The 9 canonical area keys. Kept in sync with _PATHWAYS in wallets/member.html. */
function hubAreaKeys(): array {
    return [
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
}

/** Human labels for areas. */
function hubAreaLabel(string $key): string {
    $map = [
        'operations_oversight'   => 'Day-to-Day Operations',
        'governance_polls'       => 'Research & Acquisitions',
        'esg_proxy_voting'       => 'ESG & Proxy Voting',
        'first_nations'          => 'First Nations Joint Venture',
        'community_projects'     => 'Community Projects',
        'technology_blockchain'  => 'Technology & Blockchain',
        'financial_oversight'    => 'Financial Oversight',
        'place_based_decisions'  => 'Place-Based Decisions',
        'education_outreach'     => 'Education & Outreach',
    ];
    return $map[$key] ?? $key;
}

/** Validate an area key or error out. */
function hubRequireArea(string $key): string {
    $key = trim($key);
    if (!$key) apiError('area_key required.');
    if (!in_array($key, hubAreaKeys(), true)) {
        apiError('Unknown area_key: ' . $key);
    }
    return $key;
}

/** Load member row + enrolled area list. Errors if no member. */
function hubResolveMember(PDO $db, array $principal): array {
    // Try with hub_roster_visible (added by migration); fall back if column missing
    try {
        $stmt = $db->prepare(
            'SELECT id, member_number, first_name, state_code, suburb,
                    participation_answers, hub_roster_visible, hub_roster_show_name
               FROM members
              WHERE member_type = ? AND (member_number = ? OR id = ?)
              LIMIT 1'
        );
        $stmt->execute(['personal', (string)$principal['subject_ref'], (int)$principal['principal_id']]);
    } catch (Throwable) {
        // Fallback without new columns (partial migration)
        $stmt = $db->prepare(
            'SELECT id, member_number, first_name, state_code, suburb,
                    participation_answers
               FROM members
              WHERE member_type = ? AND (member_number = ? OR id = ?)
              LIMIT 1'
        );
        $stmt->execute(['personal', (string)$principal['subject_ref'], (int)$principal['principal_id']]);
    }
    $m = $stmt->fetch();
    if (!$m) apiError('Member not found.', 404);

    $areas = [];
    if (!empty($m['participation_answers'])) {
        $dec = json_decode((string)$m['participation_answers'], true);
        if (is_array($dec)) $areas = array_values(array_filter($dec, 'is_string'));
    }
    return [
        'id'              => (int)$m['id'],
        'member_number'   => (string)$m['member_number'],
        'first_name'      => (string)($m['first_name'] ?? ''),
        'state_code'      => (string)($m['state_code'] ?? ''),
        'suburb'          => (string)($m['suburb'] ?? ''),
        'areas'           => $areas,
        'roster_visible'  => (int)($m['hub_roster_visible'] ?? 1) === 1,
        'show_name'       => (int)($m['hub_roster_show_name'] ?? 0) === 1,
    ];
}

/**
 * Returns true if the given member is cross-referenced to an active
 * trustee record via mobile OR personal_email match.
 *
 * No schema changes — joins on existing columns:
 *   trustees.mobile         ↔ members.mobile
 *   trustees.personal_email ↔ members.email
 *
 * Defensive: if the trustees table does not exist (e.g. on legacy envs
 * predating the Trustee Records System migration), returns false silently.
 */
function hubMemberIsTrustee(PDO $db, int $memberId): bool {
    if ($memberId < 1) return false;
    try {
        $stmt = $db->prepare(
            "SELECT 1
               FROM members m
               JOIN trustees t
                 ON (t.mobile = m.mobile OR t.personal_email = m.email)
              WHERE m.id = ?
                AND t.status = 'active'
              LIMIT 1"
        );
        $stmt->execute([$memberId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** Update the member's participation_answers atomically. Returns fresh list. */
function hubUpdateAreas(PDO $db, int $memberId, array $areas): array {
    $areas = array_values(array_unique(array_filter(array_map('strval', $areas), 'strlen')));
    foreach ($areas as $a) {
        if (!in_array($a, hubAreaKeys(), true)) {
            apiError('Invalid area key in list: ' . $a);
        }
    }
    $json = json_encode($areas, JSON_UNESCAPED_SLASHES);
    $db->prepare(
        'UPDATE members
            SET participation_answers    = ?,
                participation_completed  = 1,
                participation_completed_at = COALESCE(participation_completed_at, NOW()),
                updated_at               = NOW()
          WHERE id = ?'
    )->execute([$json, $memberId]);
    return $areas;
}

// =============================================================================
// DISPATCH — called from vault.php
// =============================================================================

if ($action === 'hub') {
    handleHub();
}
if ($action === 'hub-join') {
    handleHubJoin();
}
if ($action === 'hub-leave') {
    handleHubLeave();
}
if ($action === 'hub-roster') {
    handleHubRoster();
}
if ($action === 'hub-roster-visibility') {
    handleHubRosterVisibility();
}
if ($action === 'hub-roster-name-visibility') {
    handleHubRosterNameVisibility();
}
if ($action === 'hub-projects') {
    handleHubProjects();
}
if ($action === 'hub-project') {
    handleHubProject();   // single project detail (GET) with comments
}
if ($action === 'hub-project-join') {
    handleHubProjectJoin();
}
if ($action === 'hub-project-leave') {
    handleHubProjectLeave();
}
if ($action === 'hub-project-comment') {
    handleHubProjectComment();
}
if ($action === 'hub-project-advance') {
    require_once __DIR__ . '/hub_lifecycle.php';
    handleHubProjectAdvance();
}
if ($action === 'hub-project-vote') {
    handleHubProjectVote();
}
if ($action === 'hub-milestone-add') {
    handleHubMilestoneAdd();
}
if ($action === 'hub-milestone-toggle') {
    handleHubMilestoneToggle();
}
if ($action === 'hub-mainspring') {
    handleHubMainspring();
}
if ($action === 'hub-mainspring-stats') {
    handleHubMainspringStats();
}
if ($action === 'hub-query') {
    handleHubQuery();
}
if ($action === 'hub-my-queries') {
    handleHubMyQueries();
}
if ($action === 'hub-resolved-queries') {
    handleHubResolvedQueries();
}
if ($action === 'hub-ai') {
    handleHubAI();
}
if ($action === 'hub-admin-activity') {
    handleHubAdminActivity();
}

// =============================================================================
// Handlers
// =============================================================================

/**
 * GET /vault/hub?area=<key>
 * Full hub bundle: area meta, user's enrolment flag, thread summary, project summary,
 * recent activity. Roster returned via separate hub-roster endpoint (paginated).
 */
function handleHub(): void {
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db        = getDB();

    $area = hubRequireArea((string)($_GET['area'] ?? ''));
    $me   = hubResolveMember($db, $principal);
    $enrolled = in_array($area, $me['areas'], true);

    // Thread summary — last 20 threads + broadcasts for this area
    // (scoped to area; forum-level detail fetched via existing partner-op-threads)
    $thStmt = $db->prepare(
        "SELECT t.id, t.direction, t.subject, t.body, t.status,
                t.reply_count, t.last_reply_at, t.created_at,
                t.initiated_by_member_id,
                COALESCE(m.first_name,'Admin') AS author_first_name
           FROM partner_op_threads t
           LEFT JOIN members m ON m.id = t.initiated_by_member_id
          WHERE t.area_key = ?
            AND t.status != 'archived'
          ORDER BY t.created_at DESC
          LIMIT 20"
    );
    $thStmt->execute([$area]);
    $threads = $thStmt->fetchAll();

    // Unread count for this member in this area (broadcasts + partner-initiated threads)
    $unreadBc = 0; $unreadPosts = 0;
    try {
        $u1 = $db->prepare(
            "SELECT COUNT(*) FROM partner_op_broadcast_reads br
               JOIN partner_op_threads t ON t.id = br.thread_id
              WHERE t.area_key = ? AND t.direction = 'broadcast'
                AND br.member_id = ? AND br.read_at IS NULL"
        );
        $u1->execute([$area, $me['id']]);
        $unreadBc = (int)$u1->fetchColumn();

        $u2 = $db->prepare(
            "SELECT COUNT(*) FROM partner_op_broadcast_reads br
               JOIN partner_op_threads t ON t.id = br.thread_id
              WHERE t.area_key = ? AND t.direction = 'inbound'
                AND br.member_id = ? AND br.read_at IS NULL"
        );
        $u2->execute([$area, $me['id']]);
        $unreadPosts = (int)$u2->fetchColumn();
    } catch (Throwable) { /* table may not exist on older envs */ }

    // Projects — top 20 active + proposed first
    $projects = [];
    try {
        $pjStmt = $db->prepare(
            "SELECT id, area_key, title, summary, status, lead_type,
                    lead_member_id, lead_first_name, lead_state_code,
                    target_close_at, linked_poll_id, participant_count,
                    created_at, updated_at
               FROM v_hub_projects_live
              WHERE area_key = ?
              ORDER BY FIELD(status,'active','proposed','paused','completed') ASC,
                       updated_at DESC
              LIMIT 20"
        );
        $pjStmt->execute([$area]);
        $projects = $pjStmt->fetchAll();
    } catch (Throwable) { /* hub_projects table not yet migrated */ }

    // Which projects has this member joined?
    $joined = [];
    if ($projects) {
        $ids = array_column($projects, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $jStmt = $db->prepare(
            "SELECT project_id FROM hub_project_participants
              WHERE member_id = ? AND project_id IN ({$ph})"
        );
        $jStmt->execute(array_merge([$me['id']], $ids));
        $joined = array_map('intval', array_column($jStmt->fetchAll(), 'project_id'));
    }

    // Referenced projects — projects in OTHER hubs that tag this area as an
    // interest area. Read-only in this hub. Capped at 5 to prevent clutter.
    // Silently degrades to empty if interest_area_keys column not yet migrated.
    $referenced = [];
    try {
        $refStmt = $db->prepare(
            "SELECT p.id, p.area_key AS owner_area_key, p.title, p.summary,
                    p.status, p.lead_type, p.lead_member_id,
                    lm.first_name AS lead_first_name,
                    lm.state_code AS lead_state_code,
                    p.target_close_at, p.linked_poll_id, p.participant_count,
                    p.phase_target_end_at,
                    p.created_at, p.updated_at
               FROM hub_projects p
               LEFT JOIN members lm ON lm.id = p.lead_member_id
              WHERE p.area_key != ?
                AND p.status != 'archived'
                AND p.interest_area_keys IS NOT NULL
                AND FIND_IN_SET(?, p.interest_area_keys) > 0
              ORDER BY p.updated_at DESC
              LIMIT 5"
        );
        $refStmt->execute([$area, $area]);
        $referenced = $refStmt->fetchAll();
    } catch (Throwable) { /* column not yet migrated */ }

    // Summary counts
    $summary = ['member_count' => 0, 'thread_count' => 0,
                'active_project_count' => 0, 'last_activity_at' => null];
    try {
        $sumStmt = $db->prepare(
            "SELECT member_count, thread_count, active_project_count, last_activity_at
               FROM v_hub_mainspring_summary WHERE area_key = ?"
        );
        $sumStmt->execute([$area]);
        $summary = $sumStmt->fetch() ?: $summary;
    } catch (Throwable) { /* views not yet migrated */ }

    apiSuccess([
        'area_key'       => $area,
        'area_label'     => hubAreaLabel($area),
        'enrolled'       => $enrolled,
        'roster_visible' => $me['roster_visible'],
        'show_name'      => $me['show_name'] ?? false,
        'is_trustee'     => hubMemberIsTrustee($db, $me['id']),
        'summary'        => $summary,
        'unread_broadcasts' => $unreadBc,
        'unread_threads'    => $unreadPosts,
        'threads'        => $threads,
        'projects'       => array_merge(
            array_map(function($p) use ($joined) {
                $p['joined_by_me']  = in_array((int)$p['id'], $joined, true);
                $p['is_referenced'] = false;
                return $p;
            }, $projects),
            array_map(function($r) {
                $r['joined_by_me']    = false;
                $r['is_referenced']   = true;
                $r['owner_area_label'] = hubAreaLabel((string)$r['owner_area_key']);
                return $r;
            }, $referenced)
        ),
    ]);
}

/**
 * POST /vault/hub-join { area_key }
 * Add an area to member's participation_answers. Enables posting/creating.
 */
function handleHubJoin(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $area = hubRequireArea((string)($body['area_key'] ?? ''));
    $me   = hubResolveMember($db, $principal);

    if (in_array($area, $me['areas'], true)) {
        apiSuccess(['areas' => $me['areas'], 'already' => true]);
    }
    $next = array_values(array_merge($me['areas'], [$area]));
    $next = hubUpdateAreas($db, $me['id'], $next);

    apiSuccess(['areas' => $next, 'joined' => $area]);
}

/**
 * POST /vault/hub-leave { area_key }
 * Remove an area from member's participation_answers.
 */
function handleHubLeave(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $area = hubRequireArea((string)($body['area_key'] ?? ''));
    $me   = hubResolveMember($db, $principal);

    $next = array_values(array_diff($me['areas'], [$area]));
    $next = hubUpdateAreas($db, $me['id'], $next);

    apiSuccess(['areas' => $next, 'left' => $area]);
}

/**
 * GET /vault/hub-roster?area=<key>&page=1&per=20
 * Paginated roster for one area. Read-accessible to any authenticated member,
 * even if not enrolled in this area (read-only preview).
 */
function handleHubRoster(): void {
    requireMethod('GET');
    requireAuth('snft');
    $db   = getDB();
    $area = hubRequireArea((string)($_GET['area'] ?? ''));

    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = max(1, min(100, (int)($_GET['per'] ?? 20)));
    $off  = ($page - 1) * $per;

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM v_hub_roster WHERE area_key = ?");
    $cntStmt->execute([$area]);
    $total = (int)$cntStmt->fetchColumn();

    // LIMIT/OFFSET must be inline integers for older PDO/MariaDB combos
    $sql = "SELECT member_id, member_number_masked, first_name, state_code, suburb,
                   joined_area_at, last_active_at
              FROM v_hub_roster
             WHERE area_key = ?
             ORDER BY joined_area_at DESC
             LIMIT {$per} OFFSET {$off}";
    $rStmt = $db->prepare($sql);
    $rStmt->execute([$area]);
    $rows = $rStmt->fetchAll();

    apiSuccess([
        'area_key' => $area,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per,
        'members'  => $rows,
    ]);
}

/**
 * POST /vault/hub-roster-visibility { visible: 0|1 }
 * Toggle member's own roster visibility.
 */
function handleHubRosterVisibility(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $vis = isset($body['visible']) ? (int)(!!$body['visible']) : 1;
    $me  = hubResolveMember($db, $principal);

    $db->prepare('UPDATE members SET hub_roster_visible = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$vis, $me['id']]);

    apiSuccess(['roster_visible' => $vis === 1]);
}

/**
 * POST /vault/hub-roster-name-visibility { show_name: 0|1 }
 * Toggle whether the member's first name appears on hub rosters.
 * Default is 0 (anonymous) — member must explicitly opt in.
 */
function handleHubRosterNameVisibility(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $show = isset($body['show_name']) ? (int)(!!$body['show_name']) : 0;
    $me   = hubResolveMember($db, $principal);

    try {
        $db->prepare('UPDATE members SET hub_roster_show_name = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$show, $me['id']]);
    } catch (Throwable) {
        // Column may not exist yet if migration not run — fail gracefully
        apiError('Name visibility setting not available yet.', 503);
    }

    apiSuccess(['show_name' => $show === 1]);
}

/**
 * GET  /vault/hub-projects?area=<key>
 * POST /vault/hub-projects { area_key, title, summary?, body?, target_close_at? }
 */
function handleHubProjects(): void {
    $method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $principal = requireAuth('snft');
    $db        = getDB();

    if ($method === 'GET') {
        $area = hubRequireArea((string)($_GET['area'] ?? ''));
        $stmt = $db->prepare(
            "SELECT id, area_key, title, summary, status, lead_type,
                    lead_member_id, lead_first_name, lead_state_code,
                    target_close_at, linked_poll_id, participant_count,
                    phase_opened_at, phase_target_end_at, urgency_flagged,
                    created_at, updated_at
               FROM v_hub_projects_live
              WHERE area_key = ?
              ORDER BY FIELD(status,
                'open_for_input','vote','deliberation','accountability',
                'draft','active','proposed','paused','completed') ASC,
                       updated_at DESC"
        );
        $stmt->execute([$area]);
        apiSuccess(['projects' => $stmt->fetchAll()]);
    }

    // POST — create project (requires enrolment)
    requireMethod('POST');
    $body = jsonBody();
    $area  = hubRequireArea((string)($body['area_key'] ?? ''));
    $title = trim((string)($body['title'] ?? ''));
    $summary = trim((string)($body['summary'] ?? ''));
    $bodyTxt = trim((string)($body['body'] ?? ''));
    $tcAt    = trim((string)($body['target_close_at'] ?? ''));

    if (!$title) apiError('Title is required.');
    if (mb_strlen($title) > 255) apiError('Title too long (max 255).');
    if (mb_strlen($summary) > 2000) apiError('Summary too long (max 2000).');
    if (mb_strlen($bodyTxt) > 8000) apiError('Body too long (max 8000).');

    $tcDate = null;
    if ($tcAt !== '') {
        $ts = strtotime($tcAt);
        if ($ts === false) apiError('Invalid target_close_at; use YYYY-MM-DD.');
        $tcDate = date('Y-m-d', $ts);
    }

    $me = hubResolveMember($db, $principal);
    if (!in_array($area, $me['areas'], true)) {
        apiError('Activate participation in this area before creating projects.', 403);
    }

    // Trustee-gated: interest_area_keys for cross-hub referencing.
    // Non-Trustees: silently ignore any interest_area_keys in the body (defensive).
    $interestKeys = null;
    if (hubMemberIsTrustee($db, $me['id'])) {
        $raw = $body['interest_area_keys'] ?? null;
        if (is_array($raw)) {
            $valid   = [];
            $allKeys = hubAreaKeys();
            foreach ($raw as $k) {
                $k = trim((string)$k);
                if ($k === '' || $k === $area)        continue; // cannot self-reference
                if (!in_array($k, $allKeys, true))    continue; // must be valid hub
                if (!in_array($k, $valid, true))      $valid[] = $k;
            }
            if ($valid) $interestKeys = implode(',', $valid);
        }
    }

    $db->beginTransaction();
    try {
        // Column list is conditional on interest_area_keys presence so that
        // project creation by non-Trustees continues to work even if the
        // phase1 SQL migration hasn't yet been run. Trustees submitting
        // interest_area_keys before the migration runs will get a clear
        // error message (column not found) — acceptable and diagnostic.
        if ($interestKeys !== null) {
            $db->prepare(
                "INSERT INTO hub_projects
                    (area_key, title, summary, body, status, lead_type,
                     lead_member_id, target_close_at, created_by_member_id,
                     phase_opened_at, participant_count, interest_area_keys,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'draft', 'member', ?, ?, ?, NOW(), 1, ?, NOW(), NOW())"
            )->execute([$area, $title, $summary ?: null, $bodyTxt ?: null,
                        $me['id'], $tcDate, $me['id'], $interestKeys]);
        } else {
            $db->prepare(
                "INSERT INTO hub_projects
                    (area_key, title, summary, body, status, lead_type,
                     lead_member_id, target_close_at, created_by_member_id,
                     phase_opened_at, participant_count,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'draft', 'member', ?, ?, ?, NOW(), 1, NOW(), NOW())"
            )->execute([$area, $title, $summary ?: null, $bodyTxt ?: null,
                        $me['id'], $tcDate, $me['id']]);
        }
        $pid = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO hub_project_participants (project_id, member_id, role, joined_at)
             VALUES (?, ?, 'coordinator', NOW())"
        )->execute([$pid, $me['id']]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Could not create project: ' . $e->getMessage(), 500);
    }
    apiSuccess(['created' => true, 'project_id' => $pid]);
}

/**
 * GET /vault/hub-project?id=<id>
 * Single project with comments and participant list (masked).
 */
function handleHubProject(): void {
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db        = getDB();

    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) apiError('id required.');

    $pStmt = $db->prepare(
        "SELECT p.*, m.first_name AS lead_first_name, m.state_code AS lead_state_code
           FROM hub_projects p
           LEFT JOIN members m ON m.id = p.lead_member_id
          WHERE p.id = ? LIMIT 1"
    );
    $pStmt->execute([$id]);
    $project = $pStmt->fetch();
    if (!$project) apiError('Project not found.', 404);

    $me = hubResolveMember($db, $principal);

    // Participants — masked identities only
    $partStmt = $db->prepare(
        "SELECT hp.member_id, hp.role, hp.joined_at,
                m.first_name, m.state_code, m.member_number
           FROM hub_project_participants hp
           JOIN members m ON m.id = hp.member_id
          WHERE hp.project_id = ?
            AND m.hub_roster_visible = 1
          ORDER BY FIELD(hp.role,'coordinator','participant','reviewer'), hp.joined_at ASC"
    );
    $partStmt->execute([$id]);
    $participants = array_map(function($r) {
        $mn = (string)$r['member_number'];
        return [
            'member_id' => (int)$r['member_id'],
            'first_name'=> (string)$r['first_name'],
            'state_code'=> (string)$r['state_code'],
            'member_number_masked' => (strlen($mn) >= 8)
                ? substr($mn,0,4).' •••• •••• '.substr($mn,-4)
                : $mn,
            'role'      => (string)$r['role'],
            'joined_at' => (string)$r['joined_at'],
        ];
    }, $partStmt->fetchAll());

    // Have I joined?
    $jStmt = $db->prepare(
        "SELECT role FROM hub_project_participants WHERE project_id = ? AND member_id = ? LIMIT 1"
    );
    $jStmt->execute([$id, $me['id']]);
    $myRole = $jStmt->fetchColumn();

    // Comments (last 100)
    $cStmt = $db->prepare(
        "SELECT c.id, c.body, c.created_at,
                c.member_id, m.first_name AS member_first_name,
                c.admin_user_id
           FROM hub_project_comments c
           LEFT JOIN members m ON m.id = c.member_id
          WHERE c.project_id = ?
          ORDER BY c.created_at ASC
          LIMIT 100"
    );
    $cStmt->execute([$id]);
    $comments = $cStmt->fetchAll();

    // Vote summary — available for all phases; only meaningful when status='vote'
    $vsStmt = $db->prepare(
        'SELECT agree_count, disagree_count, block_count, abstain_count, total_votes
           FROM v_hub_project_vote_summary WHERE project_id = ? LIMIT 1'
    );
    $vsStmt->execute([$id]);
    $voteSummary = $vsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'agree_count' => 0, 'disagree_count' => 0,
        'block_count' => 0, 'abstain_count'  => 0, 'total_votes' => 0,
    ];

    // Caller's own vote position (null if not voted)
    $myVoteStmt = $db->prepare(
        'SELECT position, reasoning FROM hub_project_votes
          WHERE project_id = ? AND member_id = ? LIMIT 1'
    );
    $myVoteStmt->execute([$id, $me['id']]);
    $myVote = $myVoteStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Milestones — always fetched; rendered in accountability phase
    $mlStmt = $db->prepare(
        'SELECT id, label, target_date, done, done_at, sort_order
           FROM hub_project_milestones
          WHERE project_id = ?
          ORDER BY sort_order ASC'
    );
    $mlStmt->execute([$id]);
    $milestones = $mlStmt->fetchAll();

    apiSuccess([
        'project'          => $project,
        'participants'     => $participants,
        'comments'         => $comments,
        'my_role'          => $myRole ?: null,
        'enrolled_in_area' => in_array((string)$project['area_key'], $me['areas'], true),
        'vote_summary'     => $voteSummary,
        'my_vote'          => $myVote,
        'milestones'       => $milestones,
    ]);
}

/**
 * POST /vault/hub-project-join { project_id }
 */
function handleHubProjectJoin(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $pid = (int)($body['project_id'] ?? 0);
    if ($pid < 1) apiError('project_id required.');

    $pStmt = $db->prepare("SELECT area_key FROM hub_projects WHERE id = ? LIMIT 1");
    $pStmt->execute([$pid]);
    $p = $pStmt->fetch();
    if (!$p) apiError('Project not found.', 404);

    $me = hubResolveMember($db, $principal);
    if (!in_array((string)$p['area_key'], $me['areas'], true)) {
        apiError('Activate participation in this area before joining projects.', 403);
    }

    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT IGNORE INTO hub_project_participants
                (project_id, member_id, role, joined_at)
             VALUES (?, ?, 'participant', NOW())"
        )->execute([$pid, $me['id']]);

        $db->prepare(
            "UPDATE hub_projects
                SET participant_count = (SELECT COUNT(*) FROM hub_project_participants WHERE project_id = ?),
                    updated_at = NOW()
              WHERE id = ?"
        )->execute([$pid, $pid]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Could not join project: ' . $e->getMessage(), 500);
    }
    apiSuccess(['joined' => true, 'project_id' => $pid]);
}

/**
 * POST /vault/hub-project-leave { project_id }
 * Coordinator cannot leave (would orphan the project — they must transfer lead first).
 */
function handleHubProjectLeave(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $pid = (int)($body['project_id'] ?? 0);
    if ($pid < 1) apiError('project_id required.');

    $me = hubResolveMember($db, $principal);

    $rStmt = $db->prepare(
        "SELECT role FROM hub_project_participants WHERE project_id = ? AND member_id = ? LIMIT 1"
    );
    $rStmt->execute([$pid, $me['id']]);
    $role = $rStmt->fetchColumn();
    if (!$role) apiSuccess(['left' => true, 'already' => true]);
    if ($role === 'coordinator') {
        apiError('Coordinators cannot leave — transfer lead first or archive the project.', 409);
    }

    $db->beginTransaction();
    try {
        $db->prepare(
            "DELETE FROM hub_project_participants WHERE project_id = ? AND member_id = ?"
        )->execute([$pid, $me['id']]);

        $db->prepare(
            "UPDATE hub_projects
                SET participant_count = (SELECT COUNT(*) FROM hub_project_participants WHERE project_id = ?),
                    updated_at = NOW()
              WHERE id = ?"
        )->execute([$pid, $pid]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Could not leave project: ' . $e->getMessage(), 500);
    }
    apiSuccess(['left' => true, 'project_id' => $pid]);
}

/**
 * POST /vault/hub-project-comment { project_id, body }
 * Must be enrolled in the project's area to comment.
 */
function handleHubProjectComment(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $pid  = (int)($body['project_id'] ?? 0);
    $text = trim((string)($body['body'] ?? ''));
    if ($pid < 1)              apiError('project_id required.');
    if ($text === '')          apiError('Comment body is required.');
    if (mb_strlen($text) > 4000) apiError('Comment too long (max 4000).');

    $pStmt = $db->prepare("SELECT area_key FROM hub_projects WHERE id = ? LIMIT 1");
    $pStmt->execute([$pid]);
    $p = $pStmt->fetch();
    if (!$p) apiError('Project not found.', 404);

    $me = hubResolveMember($db, $principal);
    if (!in_array((string)$p['area_key'], $me['areas'], true)) {
        apiError('Activate participation in this area before commenting.', 403);
    }

    $db->prepare(
        "INSERT INTO hub_project_comments (project_id, member_id, body, created_at)
         VALUES (?, ?, ?, NOW())"
    )->execute([$pid, $me['id'], $text]);

    $db->prepare("UPDATE hub_projects SET updated_at = NOW() WHERE id = ?")
       ->execute([$pid]);

    apiSuccess(['created' => true, 'comment_id' => (int)$db->lastInsertId()]);
}

/**
 * POST /vault/hub-project-vote { project_id, position, reasoning? }
 * Cast or change a consent vote on a project in 'vote' phase.
 * position: agree | disagree | block | abstain
 * reasoning is required when position = 'block'.
 * One vote per member per project — re-voting updates the existing row.
 */
function handleHubProjectVote(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $projectId = (int)($body['project_id'] ?? 0);
    $position  = trim((string)($body['position']  ?? ''));
    $reasoning = trim((string)($body['reasoning'] ?? ''));

    if ($projectId < 1) apiError('project_id required.');
    if (!in_array($position, ['agree','disagree','block','abstain'], true)) {
        apiError('position must be agree, disagree, block, or abstain.');
    }
    if ($position === 'block' && $reasoning === '') {
        apiError('Reasoning is required when blocking a proposal.');
    }
    if (mb_strlen($reasoning) > 2000) apiError('Reasoning too long (max 2000 characters).');

    // Project must exist and be in 'vote' phase
    $pStmt = $db->prepare('SELECT status, area_key FROM hub_projects WHERE id = ? LIMIT 1');
    $pStmt->execute([$projectId]);
    $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$proj) apiError('Project not found.', 404);
    if ((string)$proj['status'] !== 'vote') {
        apiError('Votes can only be cast while the project is in the vote phase.', 409);
    }

    $me = hubResolveMember($db, $principal);

    // Must be enrolled in the hub to vote
    if (!in_array((string)$proj['area_key'], $me['areas'], true)) {
        apiError('Activate participation in this hub before voting.', 403);
    }

    // Upsert — one vote per (project, member)
    $db->prepare(
        'INSERT INTO hub_project_votes (project_id, member_id, position, reasoning)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           position  = VALUES(position),
           reasoning = VALUES(reasoning),
           updated_at = NOW()'
    )->execute([$projectId, $me['id'], $position, $reasoning ?: null]);

    // Return fresh summary
    $vsStmt = $db->prepare(
        'SELECT agree_count, disagree_count, block_count, abstain_count, total_votes
           FROM v_hub_project_vote_summary WHERE project_id = ? LIMIT 1'
    );
    $vsStmt->execute([$projectId]);
    $summary = $vsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'agree_count' => 0, 'disagree_count' => 0,
        'block_count' => 0, 'abstain_count'  => 0, 'total_votes' => 0,
    ];

    apiSuccess(['summary' => $summary, 'my_position' => $position]);
}

/**
 * POST /vault/hub-milestone-add { project_id, label, target_date? }
 * Coordinator adds a delivery milestone to a project in 'accountability' phase.
 */
function handleHubMilestoneAdd(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $projectId  = (int)($body['project_id']  ?? 0);
    $label      = trim((string)($body['label']       ?? ''));
    $targetDate = trim((string)($body['target_date'] ?? ''));

    if ($projectId < 1) apiError('project_id required.');
    if ($label === '')  apiError('Milestone label is required.');
    if (mb_strlen($label) > 255) apiError('Label too long (max 255).');

    $ts = $targetDate !== '' ? strtotime($targetDate) : false;
    $tDate = ($ts !== false) ? date('Y-m-d', $ts) : null;

    // Must be coordinator of a project in accountability phase
    $pStmt = $db->prepare('SELECT status, lead_member_id FROM hub_projects WHERE id = ? LIMIT 1');
    $pStmt->execute([$projectId]);
    $proj = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$proj) apiError('Project not found.', 404);
    if ((string)$proj['status'] !== 'accountability') {
        apiError('Milestones can only be added in the accountability phase.', 409);
    }

    $me = hubResolveMember($db, $principal);
    if ((int)$proj['lead_member_id'] !== (int)$me['id']) {
        apiError('Only the project coordinator can add milestones.', 403);
    }

    // sort_order = max existing + 1
    $maxOrd = (int)$db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM hub_project_milestones WHERE project_id = ?')
        ->execute([$projectId]) ? $db->query("SELECT COALESCE(MAX(sort_order),0) FROM hub_project_milestones WHERE project_id = $projectId")->fetchColumn() : 0;

    $db->prepare(
        'INSERT INTO hub_project_milestones (project_id, label, target_date, sort_order)
         VALUES (?, ?, ?, ?)'
    )->execute([$projectId, $label, $tDate, ((int)$maxOrd) + 1]);

    $mid = (int)$db->lastInsertId();

    // Return full milestone list so UI refreshes atomically
    $mStmt = $db->prepare(
        'SELECT id, label, target_date, done, done_at, sort_order
           FROM hub_project_milestones WHERE project_id = ? ORDER BY sort_order ASC'
    );
    $mStmt->execute([$projectId]);
    apiSuccess(['created' => true, 'milestone_id' => $mid, 'milestones' => $mStmt->fetchAll()]);
}

/**
 * POST /vault/hub-milestone-toggle { milestone_id }
 * Coordinator toggles the done state of a milestone.
 */
function handleHubMilestoneToggle(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $milestoneId = (int)($body['milestone_id'] ?? 0);
    if ($milestoneId < 1) apiError('milestone_id required.');

    // Load milestone + project to verify coordinator
    $mStmt = $db->prepare(
        'SELECT m.*, p.lead_member_id, p.status
           FROM hub_project_milestones m
           JOIN hub_projects p ON p.id = m.project_id
          WHERE m.id = ? LIMIT 1'
    );
    $mStmt->execute([$milestoneId]);
    $m = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) apiError('Milestone not found.', 404);

    $me = hubResolveMember($db, $principal);
    if ((int)$m['lead_member_id'] !== (int)$me['id']) {
        apiError('Only the project coordinator can update milestones.', 403);
    }

    $newDone  = $m['done'] ? 0 : 1;
    $newDoneAt = $newDone ? date('Y-m-d H:i:s') : null;

    $db->prepare(
        'UPDATE hub_project_milestones SET done = ?, done_at = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$newDone, $newDoneAt, $milestoneId]);

    // Return full milestone list
    $listStmt = $db->prepare(
        'SELECT id, label, target_date, done, done_at, sort_order
           FROM hub_project_milestones WHERE project_id = ? ORDER BY sort_order ASC'
    );
    $listStmt->execute([(int)$m['project_id']]);
    apiSuccess(['done' => (bool)$newDone, 'milestones' => $listStmt->fetchAll()]);
}

/**
 * POST /vault/hub-project-advance { project_id }
 * Advances a project to the next lifecycle phase.
 * Only the project coordinator (lead_member_id) may call this.
 * Phases: draft → open_for_input → deliberation → vote → accountability
 */
function handleHubProjectAdvance(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $projectId = (int)($body['project_id'] ?? 0);
    if ($projectId < 1) apiError('project_id required.');

    $me = hubResolveMember($db, $principal);

    $db->beginTransaction();
    try {
        $result = hubAdvancePhase($db, $projectId, (int)$me['id']);
        $db->commit();
        apiSuccess($result);
    } catch (RuntimeException $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($e->getMessage(), 400);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Could not advance phase: ' . $e->getMessage(), 500);
    }
}

/**
 * GET /vault/hub-mainspring-stats
 *
 * Command-centre summary statistics for the Mainspring page.
 * Five aggregated stats only — no member PII, no individual rows.
 * Auth: requireAuth('snft') — any authenticated Partner.
 */
function handleHubMainspringStats(): void
{
    requireMethod('GET');
    requireAuth('snft');
    $db = getDB();

    $stats = [];

    // 1. Active Partners (wallet_status = 'active', no PII)
    try {
        $s = $db->query("SELECT COUNT(*) FROM members WHERE wallet_status = 'active'");
        $stats['active_partners'] = (int)$s->fetchColumn();
    } catch (Throwable) {}

    // 2. ASX portfolio book value (sum of total_cost_cents)
    try {
        $s = $db->query("SELECT COALESCE(SUM(total_cost_cents),0) FROM asx_holdings");
        $stats['portfolio_book_cents'] = (int)$s->fetchColumn();
        $s2 = $db->query("SELECT COUNT(*) FROM asx_holdings");
        $stats['portfolio_holdings'] = (int)$s2->fetchColumn();
    } catch (Throwable) {}

    // 3. Open governance proposals
    try {
        $s = $db->query("SELECT COUNT(*) FROM vote_proposals WHERE status = 'open'");
        $stats['open_proposals'] = (int)$s->fetchColumn();
    } catch (Throwable) {}

    // 4. Godley invariant health (total violations across I1-I12)
    try {
        $s = $db->query("SELECT COALESCE(SUM(violation_count),0) FROM v_godley_invariant_status");
        $stats['invariant_violations'] = (int)$s->fetchColumn();
    } catch (Throwable) {}

    // 5. Next compliance deadline
    try {
        $s = $db->query(
            "SELECT MIN(compliance_due_by) FROM trust_transfers
              WHERE status IN ('pending','approved')
                AND compliance_due_by IS NOT NULL
                AND compliance_due_by >= NOW()"
        );
        $stats['next_deadline'] = $s->fetchColumn() ?: null;
    } catch (Throwable) {}

    apiSuccess(['stats' => $stats]);
}

/**
 * GET /vault/hub-mainspring
 * All 9 areas at a glance + combined recent activity (last 30).
 */
/**
 * POST /vault/hub-ai { message, history, system, area_key }
 * Proxies member messages to the Anthropic API with hub-specific context.
 * API key is kept server-side — not exposed to the browser.
 */
function handleHubAI(): void {
    requireMethod('POST');
    requireAuth('snft');           // Must be authenticated — no anon AI access

    $body       = jsonBody();
    $userMsg    = trim((string)($body['message'] ?? ''));
    $history    = is_array($body['history'] ?? null) ? $body['history'] : [];
    $systemTxt  = trim((string)($body['system']  ?? ''));
    $areaKey    = trim((string)($body['area_key']?? ''));

    if ($userMsg === '') apiError('Empty message.');
    if (mb_strlen($userMsg) > 4000) apiError('Message too long.');
    if (mb_strlen($systemTxt) > 12000) $systemTxt = mb_substr($systemTxt, 0, 12000); // safety cap

    // Sanitise history — only allow role/content pairs, cap at 10 turns
    $messages = [];
    foreach (array_slice($history, -10) as $h) {
        $role    = in_array((string)($h['role'] ?? ''), ['user','assistant'], true) ? $h['role'] : null;
        $content = trim((string)($h['content'] ?? ''));
        if ($role && $content) {
            $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 3000)];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMsg];

    // env() is always available here — loaded by _app/api/config/bootstrap.php
    // via cogs_load_env_once() which reads .env into $_ENV on first call.
    $apiKey = (string)(env('ANTHROPIC_API_KEY') ?? '');

    if ($apiKey === '') {
        apiError('AI assistant not configured — ANTHROPIC_API_KEY not set.', 503);
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 800,
        'system'     => $systemTxt ?: 'You are a helpful governance assistant for the COG$ of Australia Foundation.',
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $raw      = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) apiError('Network error contacting AI service: ' . $curlErr, 502);

    $resp = json_decode($raw ?: '{}', true) ?: [];
    if ($httpCode !== 200) {
        $msg = (string)($resp['error']['message'] ?? ('HTTP ' . $httpCode));
        apiError('AI service error: ' . $msg, 502);
    }

    $reply = '';
    foreach ($resp['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $reply .= $block['text'];
    }

    apiSuccess(['reply' => $reply]);
}

/**
 * POST /vault/hub-query { area_key, subject, body, transparency }
 * Member raises a governance query from a hub page.
 * transparency: 'private' | 'hub_members' | 'public_record'
 */
function handleHubQuery(): void {
    requireMethod('POST');
    $principal = requireAuth('snft');
    $db        = getDB();
    $body      = jsonBody();

    $area     = hubRequireArea((string)($body['area_key']     ?? ''));
    $subject  = trim((string)($body['subject']               ?? ''));
    $bodyText = trim((string)($body['body']                  ?? ''));
    $trans    = trim((string)($body['transparency']          ?? 'private'));

    if (!$subject)                   apiError('Subject is required.');
    if (mb_strlen($subject) > 255)   apiError('Subject too long (max 255 characters).');
    if (!$bodyText)                  apiError('Query body is required.');
    if (mb_strlen($bodyText) > 6000) apiError('Query body too long (max 6000 characters).');
    if (!in_array($trans, ['private','hub_members','public_record'], true)) {
        apiError('Invalid transparency value.');
    }

    $me = hubResolveMember($db, $principal);

    try {
        $db->prepare(
            "INSERT INTO member_hub_queries
               (member_id, area_key, subject, body, transparency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'open', NOW(), NOW())"
        )->execute([$me['id'], $area, $subject, $bodyText, $trans]);
        $queryId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        apiError('Could not save query. Please try again.', 500);
    }

    apiSuccess(['created' => true, 'query_id' => $queryId]);
}

/**
 * GET /vault/hub-my-queries&area=<key>
 * Returns this member's own queries for a hub area.
 */
function handleHubMyQueries(): void {
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db        = getDB();

    $area = hubRequireArea((string)($_GET['area'] ?? ''));
    $me   = hubResolveMember($db, $principal);

    try {
        $stmt = $db->prepare(
            "SELECT id, area_key, subject, LEFT(body,200) AS body_preview,
                    transparency, status, created_at, updated_at
               FROM member_hub_queries
              WHERE member_id = ? AND area_key = ?
              ORDER BY created_at DESC
              LIMIT 20"
        );
        $stmt->execute([$me['id'], $area]);
        $queries = $stmt->fetchAll();
    } catch (Throwable) {
        $queries = []; // table not yet migrated
    }

    apiSuccess(['queries' => $queries]);
}

/**
 * GET /vault/hub-resolved-queries?area_key=<key>
 * Returns resolved/closed queries for a hub in the past 30 days.
 * Only hub_members and public_record transparency rows are returned —
 * private queries are never surfaced regardless of caller.
 */
function handleHubResolvedQueries(): void {
    requireMethod('GET');
    requireAuth('snft');   // must be logged in; enrolment not required to read
    $db   = getDB();
    $area = hubRequireArea((string)($_GET['area_key'] ?? ''));

    try {
        $stmt = $db->prepare(
            "SELECT id, subject, transparency, status, admin_notes,
                    updated_at AS resolved_at
               FROM member_hub_queries
              WHERE area_key    = ?
                AND status      IN ('resolved','closed')
                AND transparency IN ('hub_members','public_record')
                AND updated_at  >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              ORDER BY updated_at DESC
              LIMIT 10"
        );
        $stmt->execute([$area]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Trim admin_notes to a 280-char excerpt for display
        $queries = array_map(function(array $r): array {
            $note = (string)($r['admin_notes'] ?? '');
            return [
                'id'               => (int)$r['id'],
                'subject'          => (string)$r['subject'],
                'transparency'     => (string)$r['transparency'],
                'status'           => (string)$r['status'],
                'resolution_excerpt' => $note !== '' ? mb_substr($note, 0, 280) : null,
                'resolved_at'      => (string)$r['resolved_at'],
            ];
        }, $rows);
    } catch (Throwable) {
        $queries = []; // member_hub_queries table not yet migrated
    }

    apiSuccess(['queries' => $queries]);
}

function handleHubMainspring(): void {
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db        = getDB();

    $me = hubResolveMember($db, $principal);

    $sumStmt = $db->prepare(
        "SELECT area_key, member_count, thread_count, active_project_count, last_activity_at
           FROM v_hub_mainspring_summary
          ORDER BY FIELD(area_key,
                         'operations_oversight','governance_polls','esg_proxy_voting',
                         'first_nations','community_projects','technology_blockchain',
                         'financial_oversight','place_based_decisions','education_outreach')"
    );
    $sumStmt->execute();
    $tiles = array_map(function($row) use ($me) {
        $row['area_label']  = hubAreaLabel((string)$row['area_key']);
        $row['enrolled']    = in_array((string)$row['area_key'], $me['areas'], true);
        return $row;
    }, $sumStmt->fetchAll());

    $actStmt = $db->prepare(
        "SELECT area_key, event_type, ref_id, title, meta,
                actor_member_id, occurred_at
           FROM v_hub_activity
          ORDER BY occurred_at DESC
          LIMIT 30"
    );
    $actStmt->execute();
    $activity = $actStmt->fetchAll();

    apiSuccess([
        'tiles'    => $tiles,
        'activity' => $activity,
        'my_areas' => $me['areas'],
    ]);
}


/**
 * GET /vault/hub-admin-activity?area_key=<key>&limit=<n>
 *
 * Returns two things:
 *   admin_pages — the static hub→admin page map for this area (from config).
 *                 Shows Partners which admin functions serve their hub.
 *   activity    — recent operational events for this area (read-only, no PII).
 *
 * Auth: requireAuth('snft') + hub enrolment check.
 * Read-only. No writes. No mutation.
 */
function handleHubAdminActivity(): void
{
    requireMethod('GET');
    $principal = requireAuth('snft');
    $db        = getDB();

    $area  = hubRequireArea((string)($_GET['area_key'] ?? ''));
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));

    $me = hubResolveMember($db, $principal);

    // Gate: Partner must be enrolled in this hub
    if (!in_array($area, $me['areas'], true)) {
        apiError('You are not enrolled in this hub. Activate Participation to view operational activity.', 403);
    }

    // ── Static admin page map for this hub ──────────────────────────────────
    $helpersPath = __DIR__ . '/../../config/hub_admin_helpers.php';
    $adminPages  = ['primary' => [], 'secondary' => []];
    if (file_exists($helpersPath)) {
        require_once $helpersPath;
        $raw = hub_admin_pages_for($area);
        $toLabelled = static function(array $keys): array {
            return array_map(static function(string $k): array {
                return ['key' => $k, 'label' => hub_admin_page_label($k)];
            }, $keys);
        };
        $adminPages = [
            'primary'   => $toLabelled($raw['primary']),
            'secondary' => $toLabelled($raw['secondary']),
        ];
    }

    // ── Dynamic activity feed — three sources, each try/caught ──────────────
    $activity = [];

    // Source 1: admin-initiated forum threads for this area
    try {
        $stmt = $db->prepare(
            "SELECT 'thread' AS source,
                    subject        AS summary,
                    'admin'      AS actor_type,
                    created_at     AS ts
               FROM partner_op_threads
              WHERE area_key = ?
                AND created_by_admin_user_id IS NOT NULL
              ORDER BY created_at DESC
              LIMIT 8"
        );
        $stmt->execute([$area]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activity[] = [
                'ts'         => (string)$row['ts'],
                'source'     => 'Admin thread',
                'summary'    => (string)$row['summary'],
                'actor_type' => 'admin',
            ];
        }
    } catch (Throwable) { /* table not available */ }

    // Source 2: recent hub projects (created or updated) for this area
    try {
        $stmt = $db->prepare(
            "SELECT title,
                    status,
                    updated_at AS ts
               FROM hub_projects
              WHERE area_key = ?
              ORDER BY updated_at DESC
              LIMIT 6"
        );
        $stmt->execute([$area]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusLabel = match((string)$row['status']) {
                'open'      => 'Active project',
                'completed' => 'Completed project',
                'closed'    => 'Closed project',
                'draft'     => 'Draft project',
                default      => 'Project',
            };
            $activity[] = [
                'ts'         => (string)$row['ts'],
                'source'     => $statusLabel,
                'summary'    => (string)$row['title'],
                'actor_type' => 'system',
            ];
        }
    } catch (Throwable) { /* table not available */ }

    // Source 3: resolved/closed member queries (hub_members or public transparency)
    try {
        $stmt = $db->prepare(
            "SELECT subject     AS summary,
                    updated_at  AS ts
               FROM member_hub_queries
              WHERE area_key      = ?
                AND status        IN ('resolved', 'closed')
                AND transparency  IN ('hub_members', 'public_record')
              ORDER BY updated_at DESC
              LIMIT 5"
        );
        $stmt->execute([$area]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activity[] = [
                'ts'         => (string)$row['ts'],
                'source'     => 'Query resolved',
                'summary'    => (string)$row['summary'],
                'actor_type' => 'admin',
            ];
        }
    } catch (Throwable) { /* table not available */ }

    // Sort all sources by ts DESC and apply limit
    usort($activity, static fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));
    $activity = array_slice($activity, 0, $limit);

    // ── Hub-specific live data ────────────────────────────────────────────────
    $hubData = [];

    if ($area === 'operations_oversight') {
        // 1. Exception counts by severity (open + in_progress)
        try {
            $stmt = $db->prepare(
                "SELECT severity, COUNT(*) AS cnt
                   FROM admin_exceptions
                  WHERE status IN ('open','in_progress')
                  GROUP BY severity"
            );
            $stmt->execute();
            $bySev = []; $total = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bySev[(string)$r['severity']] = (int)$r['cnt'];
                $total += (int)$r['cnt'];
            }
            $hubData['exceptions'] = ['open_count' => $total, 'by_severity' => $bySev];
        } catch (Throwable) {}

        // 2. Pending approvals
        try {
            $s = $db->query(
                "SELECT COUNT(*) FROM approval_requests WHERE request_status = 'pending'"
            );
            $hubData['pending_approvals'] = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // 3. Recent resolved exceptions (summary only, no PII)
        try {
            $stmt = $db->prepare(
                "SELECT summary, exception_type, severity, resolved_at
                   FROM admin_exceptions
                  WHERE status = 'resolved'
                  ORDER BY resolved_at DESC
                  LIMIT 5"
            );
            $stmt->execute();
            $hubData['recent_resolved'] = array_map(
                fn($r) => [
                    'summary'        => (string)$r['summary'],
                    'exception_type' => (string)$r['exception_type'],
                    'severity'       => (string)$r['severity'],
                    'resolved_at'    => (string)($r['resolved_at'] ?? ''),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}
        // 4. Pending approvals by type
        try {
            $stmt = $db->query("SELECT request_type, COUNT(*) AS cnt FROM approval_requests WHERE request_status = 'pending' GROUP BY request_type ORDER BY cnt DESC");
            $hubData['pending_by_type'] = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)
                $hubData['pending_by_type'][(string)$r['request_type']] = (int)$r['cnt'];
        } catch (Throwable) {}
        // 5. Overdue trust transfers
        try {
            $s = $db->query("SELECT COUNT(*) FROM v_overdue_transfers WHERE days_overdue > 0");
            $hubData['overdue_transfer_count'] = (int)$s->fetchColumn();
        } catch (Throwable) {}
            } elseif ($area === 'governance_polls') {
        // 1. Active vote proposals — titles and close dates (no member data)
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS total_open FROM vote_proposals WHERE status = 'open'"
            );
            $stmt->execute();
            $hubData['open_proposal_count'] = (int)$stmt->fetchColumn();

            $stmt2 = $db->prepare(
                "SELECT title, closes_at
                   FROM vote_proposals
                  WHERE status = 'open'
                  ORDER BY closes_at ASC
                  LIMIT 3"
            );
            $stmt2->execute();
            $hubData['open_proposals'] = array_map(
                fn($r) => [
                    'title'     => (string)$r['title'],
                    'closes_at' => (string)($r['closes_at'] ?? ''),
                ],
                $stmt2->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}

        // 2. Portfolio holdings count (distinct ASX positions held)
        try {
            $s = $db->query("SELECT COUNT(*) FROM asx_holdings");
            $hubData['holdings_count'] = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // 3. Recent settled trades — ticker, units, date (no member data)
        try {
            $stmt = $db->prepare(
                "SELECT h.ticker, t.units, t.trade_date
                   FROM asx_trades t
                   JOIN asx_holdings h ON h.id = t.holding_id
                  WHERE t.status = 'settled'
                  ORDER BY t.trade_date DESC
                  LIMIT 3"
            );
            $stmt->execute();
            $hubData['recent_trades'] = array_map(
                fn($r) => [
                    'ticker'     => (string)$r['ticker'],
                    'units'      => (int)$r['units'],
                    'trade_date' => (string)$r['trade_date'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}
        // 4. Closed vote proposal outcomes
        try {
            $stmt = $db->prepare("SELECT title, status, proposal_type, updated_at FROM vote_proposals WHERE status IN ('closed','archived') ORDER BY updated_at DESC LIMIT 5");
            $stmt->execute();
            $hubData['closed_proposals'] = array_map(
                fn($r) => ['title'=>(string)$r['title'],'status'=>(string)$r['status'],
                           'proposal_type'=>(string)$r['proposal_type'],'updated_at'=>(string)$r['updated_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 5. Binding poll outcomes
        try {
            $stmt = $db->prepare("SELECT title, status, voting_closes_at FROM community_polls WHERE status IN ('closed','declared','archived') ORDER BY voting_closes_at DESC LIMIT 3");
            $stmt->execute();
            $hubData['closed_polls'] = array_map(
                fn($r) => ['title'=>(string)$r['title'],'status'=>(string)$r['status'],'closed_at'=>(string)($r['voting_closes_at']??'')],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 6. Business partner count (aggregate only)
        try {
            $s = $db->query("SELECT COUNT(*) FROM members WHERE member_type = 'business'");
            $hubData['business_partner_count'] = (int)$s->fetchColumn();
        } catch (Throwable) {}
            } elseif ($area === 'esg_proxy_voting') {
        // 1. Holdings list — ticker, company_name, units, ESG flag (no member data)
        try {
            $stmt = $db->query(
                "SELECT ticker, company_name, units_held, is_poor_esg_target
                   FROM asx_holdings
                  ORDER BY is_poor_esg_target DESC, ticker ASC
                  LIMIT 20"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $poorCount = 0;
            $hubData['holdings'] = array_map(function($r) use (&$poorCount) {
                if ((int)$r['is_poor_esg_target']) $poorCount++;
                return [
                    'ticker'             => (string)$r['ticker'],
                    'company_name'       => (string)$r['company_name'],
                    'units_held'         => (string)$r['units_held'],
                    'is_poor_esg_target' => (bool)(int)$r['is_poor_esg_target'],
                ];
            }, $rows);
            $hubData['poor_esg_count'] = $poorCount;
        } catch (Throwable) {}

        // 2. Recent proxy engagements — join to holdings for ticker (no member data)
        try {
            $stmt = $db->prepare(
                "SELECT h.ticker, h.company_name, e.engagement_type,
                        e.status, e.meeting_or_event_date
                   FROM asx_proxy_engagements e
                   JOIN asx_holdings h ON h.id = e.holding_id
                  ORDER BY e.created_at DESC
                  LIMIT 5"
            );
            $stmt->execute();
            $hubData['recent_engagements'] = array_map(
                fn($r) => [
                    'ticker'              => (string)$r['ticker'],
                    'company_name'        => (string)$r['company_name'],
                    'engagement_type'     => (string)$r['engagement_type'],
                    'status'              => (string)$r['status'],
                    'meeting_date'        => (string)($r['meeting_or_event_date'] ?? ''),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}
        // 3. ASX purchase history with price (settled trades)
        try {
            $stmt = $db->prepare("SELECT h.ticker, h.company_name, t.units, t.price_cents_per_unit, t.trade_date FROM asx_trades t JOIN asx_holdings h ON h.id = t.holding_id WHERE t.status = 'settled' ORDER BY t.trade_date DESC LIMIT 5");
            $stmt->execute();
            $hubData['settled_trades_detail'] = array_map(
                fn($r) => ['ticker'=>(string)$r['ticker'],'company_name'=>(string)$r['company_name'],
                           'units'=>(int)$r['units'],'price_cents'=>(float)($r['price_cents_per_unit']??0),
                           'trade_date'=>(string)$r['trade_date']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 4. Token class breakdown
        try {
            $stmt = $db->prepare("SELECT class_code, display_name, unit_price_cents, member_type FROM token_classes ORDER BY display_order LIMIT 10");
            $stmt->execute();
            $hubData['token_classes'] = array_map(
                fn($r) => ['class_code'=>(string)$r['class_code'],'display_name'=>(string)$r['display_name'],
                           'unit_price_cents'=>(int)$r['unit_price_cents'],'member_type'=>(string)$r['member_type']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
            } elseif ($area === 'first_nations') {
        // 1. Active Country overlays (no member/personal data)
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS cnt FROM affected_zones
                  WHERE status = 'active' AND zone_type = 'country_overlay'"
            );
            $stmt->execute();
            $hubData['country_overlay_count'] = (int)$stmt->fetchColumn();

            $stmt2 = $db->prepare(
                "SELECT zone_name, effective_date
                   FROM affected_zones
                  WHERE status = 'active' AND zone_type = 'country_overlay'
                  ORDER BY effective_date DESC
                  LIMIT 5"
            );
            $stmt2->execute();
            $hubData['country_overlays'] = array_map(
                fn($r) => [
                    'zone_name'      => (string)$r['zone_name'],
                    'effective_date' => (string)($r['effective_date'] ?? ''),
                ],
                $stmt2->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}

        // 2. First Nations grants — counts by status + total disbursed (no grantee PII)
        try {
            $stmt = $db->prepare(
                "SELECT status, COUNT(*) AS cnt, SUM(amount_cents) AS total
                   FROM grants
                  WHERE is_first_nations = 1
                  GROUP BY status"
            );
            $stmt->execute();
            $grantsByStatus = []; $totalDisbursed = 0; $totalCount = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $grantsByStatus[(string)$r['status']] = (int)$r['cnt'];
                $totalCount += (int)$r['cnt'];
                if (in_array($r['status'], ['disbursed','acquitted'], true)) {
                    $totalDisbursed += (int)$r['total'];
                }
            }
            $hubData['fn_grants'] = [
                'total_count'      => $totalCount,
                'total_disbursed'  => $totalDisbursed,
                'by_status'        => $grantsByStatus,
            ];
        } catch (Throwable) {}

        // 3. Recent FNAC reviews — review_key and status (no member data)
        try {
            $stmt = $db->prepare(
                "SELECT review_key, status, created_at
                   FROM fnac_reviews
                  ORDER BY created_at DESC
                  LIMIT 5"
            );
            $stmt->execute();
            $hubData['recent_fnac_reviews'] = array_map(
                fn($r) => [
                    'review_key' => (string)$r['review_key'],
                    'status'     => (string)$r['status'],
                    'created_at' => (string)$r['created_at'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}
        // 4. Active zone challenges (challenger_member_id NOT returned)
        try {
            $stmt = $db->prepare("SELECT zc.challenge_summary, zc.status, az.zone_name, zc.created_at FROM zone_challenges zc JOIN affected_zones az ON az.id = zc.zone_id WHERE zc.status IN ('open','in_review') ORDER BY zc.created_at DESC LIMIT 5");
            $stmt->execute();
            $hubData['active_zone_challenges'] = array_map(
                fn($r) => ['zone_name'=>(string)$r['zone_name'],'status'=>(string)$r['status'],
                           'summary'=>(string)$r['challenge_summary'],'created_at'=>(string)$r['created_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 5. Evidence reviews (member_id NOT returned)
        try {
            $stmt = $db->prepare("SELECT subject_type, review_type, review_status, created_at FROM evidence_reviews ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $hubData['evidence_reviews'] = array_map(
                fn($r) => ['subject_type'=>(string)$r['subject_type'],'review_type'=>(string)$r['review_type'],
                           'review_status'=>(string)$r['review_status'],'created_at'=>(string)$r['created_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
            } elseif ($area === 'community_projects') {
        // 1. All grants — counts by status + total disbursed (no grantee PII)
        try {
            $stmt = $db->query(
                "SELECT status, COUNT(*) AS cnt, SUM(amount_cents) AS total
                   FROM grants GROUP BY status"
            );
            $grantsByStatus = []; $totalDisbursed = 0; $totalCount = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $grantsByStatus[(string)$r['status']] = (int)$r['cnt'];
                $totalCount += (int)$r['cnt'];
                if (in_array($r['status'], ['disbursed','acquitted'], true)) {
                    $totalDisbursed += (int)$r['total'];
                }
            }
            $hubData['grants'] = [
                'total_count'     => $totalCount,
                'total_disbursed' => $totalDisbursed,
                'by_status'       => $grantsByStatus,
            ];
        } catch (Throwable) {}

        // 2. Open announcements — title and close date only (no member data)
        try {
            $stmt = $db->prepare(
                "SELECT title, closes_at FROM announcements
                  WHERE status = 'open'
                  ORDER BY opens_at DESC LIMIT 3"
            );
            $stmt->execute();
            $hubData['open_announcements'] = array_map(
                fn($r) => [
                    'title'     => (string)$r['title'],
                    'closes_at' => (string)($r['closes_at'] ?? ''),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}

        // 3. Trust income total last 12 months (aggregate only)
        try {
            $s = $db->query(
                "SELECT COALESCE(SUM(net_amount_cents),0) AS total
                   FROM trust_income
                  WHERE income_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)"
            );
            $hubData['trust_income_12m'] = (int)$s->fetchColumn();
        } catch (Throwable) {}
        // 4. Recent grant titles + types (no grantee PII)
        try {
            $stmt = $db->prepare("SELECT title, grant_type, amount_cents, status FROM grants WHERE status IN ('approved','disbursed','acquitted') ORDER BY id DESC LIMIT 5");
            $stmt->execute();
            $hubData['recent_grants'] = array_map(
                fn($r) => ['title'=>(string)$r['title'],'grant_type'=>(string)$r['grant_type'],
                           'amount_cents'=>(int)$r['amount_cents'],'status'=>(string)$r['status']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 5. STC direct transfer compliance (2-business-day rule)
        try {
            $s = $db->query("SELECT COUNT(*) FROM trust_transfers WHERE transfer_type = 'a_to_c_direct' AND status = 'pending' AND compliance_due_by IS NOT NULL");
            $hubData['stc_pending_count'] = (int)$s->fetchColumn();
        } catch (Throwable) {}
            } elseif ($area === 'technology_blockchain') {
        // 1. Ledger nodes by status (no member data)
        try {
            $stmt = $db->query(
                "SELECT status, COUNT(*) AS cnt FROM ledger_nodes GROUP BY status"
            );
            $nodesByStatus = []; $totalNodes = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nodesByStatus[(string)$r['status']] = (int)$r['cnt'];
                $totalNodes += (int)$r['cnt'];
            }
            $hubData['nodes'] = ['total' => $totalNodes, 'by_status' => $nodesByStatus];
        } catch (Throwable) {}

        // 2. Mint queue by status (no member data)
        try {
            $stmt = $db->query(
                "SELECT queue_status, COUNT(*) AS cnt FROM mint_queue GROUP BY queue_status"
            );
            $queueByStatus = []; $totalQueue = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $queueByStatus[(string)$r['queue_status']] = (int)$r['cnt'];
                $totalQueue += (int)$r['cnt'];
            }
            $hubData['mint_queue'] = ['total' => $totalQueue, 'by_status' => $queueByStatus];
        } catch (Throwable) {}

        // 3. Recent mint batches — label, status, date (no member data)
        try {
            $stmt = $db->prepare(
                "SELECT batch_label, batch_status, created_at
                   FROM mint_batches
                  ORDER BY created_at DESC LIMIT 3"
            );
            $stmt->execute();
            $hubData['recent_batches'] = array_map(
                fn($r) => [
                    'batch_label'  => (string)$r['batch_label'],
                    'batch_status' => (string)$r['batch_status'],
                    'created_at'   => (string)$r['created_at'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}
        // 4. Active node incidents
        try {
            $stmt = $db->prepare("SELECT severity, status, summary, created_at FROM node_incidents WHERE status IN ('open','triaged','contained') ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $hubData['node_incidents'] = array_map(
                fn($r) => ['severity'=>(string)$r['severity'],'status'=>(string)$r['status'],
                           'summary'=>(string)$r['summary'],'created_at'=>(string)$r['created_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 5. Published infrastructure reports
        try {
            $stmt = $db->prepare("SELECT report_key, report_type, summary, created_at FROM infrastructure_reports WHERE status = 'published' ORDER BY created_at DESC LIMIT 3");
            $stmt->execute();
            $hubData['infra_reports'] = array_map(
                fn($r) => ['report_key'=>(string)$r['report_key'],'report_type'=>(string)$r['report_type'],
                           'summary'=>(string)($r['summary']??''),'created_at'=>(string)$r['created_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
            } elseif ($area === 'financial_oversight') {
        // 1. Most recent distribution run — date, status, pool total (no member data)
        try {
            $stmt = $db->query(
                "SELECT distribution_date, status, total_pool_cents
                   FROM distribution_runs
                  ORDER BY distribution_date DESC LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $hubData['last_distribution'] = [
                    'distribution_date' => (string)$row['distribution_date'],
                    'status'            => (string)$row['status'],
                    'total_pool_cents'  => (int)$row['total_pool_cents'],
                ];
            }
        } catch (Throwable) {}

        // 2. Overdue transfers count (aggregate only)
        try {
            $s = $db->query(
                "SELECT COUNT(*) FROM v_overdue_transfers WHERE days_overdue > 0"
            );
            $hubData['overdue_transfers'] = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // 3. Trust income total last 12 months (aggregate only)
        try {
            $s = $db->query(
                "SELECT COALESCE(SUM(net_amount_cents),0) FROM trust_income
                  WHERE income_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)"
            );
            $hubData['trust_income_12m'] = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // 4. Trust expenses total last 12 months by category (aggregate only, no payee)
        try {
            $stmt = $db->query(
                "SELECT expense_category, COALESCE(SUM(amount_cents),0) AS total
                   FROM trust_expenses
                  WHERE status = 'paid'
                    AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                  GROUP BY expense_category
                  ORDER BY total DESC"
            );
            $expByCat = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $expByCat[(string)$r['expense_category']] = (int)$r['total'];
            }
            $hubData['expenses_by_category'] = $expByCat;
        } catch (Throwable) {}

        // 5. Godley invariants I1-I12
        try {
            $stmt = $db->query("SELECT code, name, violation_count FROM v_godley_invariant_status ORDER BY code");
            $totalViol = 0;
            $hubData['invariants'] = array_map(function($r) use (&$totalViol) {
                $vc = (int)$r['violation_count']; $totalViol += $vc;
                return ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'violation_count' => $vc];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            $hubData['invariant_violations_total'] = $totalViol;
        } catch (Throwable) {}

        // 6. Sub-trust balances
        try {
            $stmt = $db->query("SELECT sub_trust, display_name, balance_cents FROM v_godley_consolidated ORDER BY sub_trust");
            $hubData['sub_trust_balances'] = array_map(
                fn($r) => ['sub_trust' => (string)$r['sub_trust'], 'display_name' => (string)$r['display_name'], 'balance_cents' => (int)$r['balance_cents']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}

        // 7. Upcoming compliance deadlines within 14 days
        try {
            $stmt = $db->query("SELECT COUNT(*) AS cnt, MIN(compliance_due_by) AS earliest FROM trust_transfers WHERE status IN ('pending','approved') AND compliance_due_by IS NOT NULL AND compliance_due_by >= NOW() AND compliance_due_by <= DATE_ADD(NOW(), INTERVAL 14 DAY)");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hubData['upcoming_deadlines'] = ['count' => (int)($row['cnt'] ?? 0), 'earliest' => (string)($row['earliest'] ?? '')];
        } catch (Throwable) {}

        // 8. Enhanced distribution run (add per-unit rate + due date)
        if (!empty($hubData['last_distribution'])) {
            try {
                $stmt = $db->query("SELECT cents_per_unit, total_beneficial_units, distribution_due_by FROM distribution_runs ORDER BY distribution_date DESC LIMIT 1");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $hubData['last_distribution']['cents_per_unit']         = (int)$row['cents_per_unit'];
                    $hubData['last_distribution']['total_beneficial_units']  = (int)$row['total_beneficial_units'];
                    $hubData['last_distribution']['distribution_due_by']    = (string)($row['distribution_due_by'] ?? '');
                }
            } catch (Throwable) {}
        }
            } elseif ($area === 'place_based_decisions') {
        // 1. Active Affected Zones (governance zone type) — name + date (no member/address data)
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS cnt FROM affected_zones
                  WHERE status = 'active'
                    AND zone_type IN ('affected_zone','project_impact')"
            );
            $stmt->execute();
            $hubData['active_zone_count'] = (int)$stmt->fetchColumn();

            $stmt2 = $db->prepare(
                "SELECT zone_name, zone_type, effective_date
                   FROM affected_zones
                  WHERE status = 'active'
                    AND zone_type IN ('affected_zone','project_impact')
                  ORDER BY effective_date DESC LIMIT 5"
            );
            $stmt2->execute();
            $hubData['active_zones'] = array_map(
                fn($r) => [
                    'zone_name'      => (string)$r['zone_name'],
                    'zone_type'      => (string)$r['zone_type'],
                    'effective_date' => (string)($r['effective_date'] ?? ''),
                ],
                $stmt2->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}

        // 2. All zones status breakdown (aggregate only)
        try {
            $stmt = $db->query(
                "SELECT status, COUNT(*) AS cnt FROM affected_zones GROUP BY status"
            );
            $hubData['zones_by_status'] = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hubData['zones_by_status'][(string)$r['status']] = (int)$r['cnt'];
            }
        } catch (Throwable) {}

        // 3. Active RWA assets — name, type, location (no personal data)
        try {
            $stmt = $db->prepare(
                "SELECT asset_name, asset_type, location_summary
                   FROM rwa_asset_register
                  WHERE status = 'active'
                  ORDER BY created_at DESC LIMIT 5"
            );
            $stmt->execute();
            $hubData['active_rwa_assets'] = array_map(
                fn($r) => [
                    'asset_name'       => (string)$r['asset_name'],
                    'asset_type'       => (string)$r['asset_type'],
                    'location_summary' => (string)($r['location_summary'] ?? ''),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
            $hubData['rwa_count'] = count($hubData['active_rwa_assets']);
        } catch (Throwable) {}
        // 4. Active zone challenges (challenger_member_id NOT returned)
        try {
            $stmt = $db->prepare("SELECT zc.challenge_summary, zc.status, az.zone_name, zc.created_at FROM zone_challenges zc JOIN affected_zones az ON az.id = zc.zone_id WHERE zc.status IN ('open','in_review') ORDER BY zc.created_at DESC LIMIT 5");
            $stmt->execute();
            $hubData['zone_challenges'] = array_map(
                fn($r) => ['zone_name'=>(string)$r['zone_name'],'status'=>(string)$r['status'],
                           'summary'=>(string)$r['challenge_summary'],'created_at'=>(string)$r['created_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
            } elseif ($area === 'education_outreach') {
        // 1. New members last 30 days — COUNT only, no identifying data
        try {
            $s = $db->query(
                "SELECT COUNT(*) FROM members
                  WHERE member_type = 'personal'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $hubData['new_members_30d'] = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // 2. Membership breakdown by wallet_status — aggregate only, no names
        try {
            $stmt = $db->query(
                "SELECT wallet_status, COUNT(*) AS cnt
                   FROM members
                  WHERE member_type = 'personal'
                  GROUP BY wallet_status"
            );
            $byStatus = []; $total = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byStatus[(string)$r['wallet_status']] = (int)$r['cnt'];
                $total += (int)$r['cnt'];
            }
            $hubData['members_total']     = $total;
            $hubData['members_by_status'] = $byStatus;
        } catch (Throwable) {}

        // 3. Active invite codes and total uses — aggregate only, no member data
        try {
            $stmt = $db->query(
                "SELECT COUNT(*) AS code_count, COALESCE(SUM(use_count),0) AS total_uses
                   FROM partner_invite_codes WHERE status = 'active'"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $hubData['invite_codes']  = (int)$row['code_count'];
                $hubData['invite_uses']   = (int)$row['total_uses'];
            }
        } catch (Throwable) {}

        // 4. Open announcements — title and close date only
        try {
            $stmt = $db->prepare(
                "SELECT title, closes_at FROM announcements
                  WHERE status = 'open'
                  ORDER BY opens_at DESC LIMIT 3"
            );
            $stmt->execute();
            $hubData['open_announcements'] = array_map(
                fn($r) => [
                    'title'     => (string)$r['title'],
                    'closes_at' => (string)($r['closes_at'] ?? ''),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {}
        }

    apiSuccess([
        'area_key'    => $area,
        'area_label'  => hubAreaLabel($area),
        'admin_pages' => $adminPages,
        'activity'    => $activity,
        'hub_data'    => $hubData,
    ]);
        // 5. Broadcast wallet messages (member_id IS NULL = broadcast)
        try {
            $stmt = $db->prepare("SELECT subject, message_type, audience, created_at FROM wallet_messages WHERE member_id IS NULL ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $hubData['broadcast_messages'] = array_map(
                fn($r) => ['subject'=>(string)$r['subject'],'message_type'=>(string)$r['message_type'],
                           'audience'=>(string)$r['audience'],'created_at'=>(string)$r['created_at']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {}
        // 6. Email events by type (aggregate, no recipient PII)
        try {
            $stmt = $db->query("SELECT event_type, COUNT(*) AS cnt FROM email_access_log WHERE member_id IS NULL GROUP BY event_type ORDER BY cnt DESC LIMIT 6");
            $hubData['email_event_summary'] = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)
                $hubData['email_event_summary'][(string)$r['event_type']] = (int)$r['cnt'];
        } catch (Throwable) {}
}

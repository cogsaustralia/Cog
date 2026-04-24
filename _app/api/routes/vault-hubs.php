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
if ($action === 'hub-mainspring') {
    handleHubMainspring();
}
if ($action === 'hub-query') {
    handleHubQuery();
}
if ($action === 'hub-my-queries') {
    handleHubMyQueries();
}
if ($action === 'hub-ai') {
    handleHubAI();
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
        'summary'        => $summary,
        'unread_broadcasts' => $unreadBc,
        'unread_threads'    => $unreadPosts,
        'threads'        => $threads,
        'projects'       => array_map(function($p) use ($joined) {
            $p['joined_by_me'] = in_array((int)$p['id'], $joined, true);
            return $p;
        }, $projects),
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
                    created_at, updated_at
               FROM v_hub_projects_live
              WHERE area_key = ?
              ORDER BY FIELD(status,'active','proposed','paused','completed') ASC,
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

    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT INTO hub_projects
                (area_key, title, summary, body, status, lead_type,
                 lead_member_id, target_close_at, created_by_member_id,
                 participant_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'proposed', 'member', ?, ?, ?, 1, NOW(), NOW())"
        )->execute([$area, $title, $summary ?: null, $bodyTxt ?: null,
                    $me['id'], $tcDate, $me['id']]);
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

    apiSuccess([
        'project'      => $project,
        'participants' => $participants,
        'comments'     => $comments,
        'my_role'      => $myRole ?: null,
        'enrolled_in_area' => in_array((string)$project['area_key'], $me['areas'], true),
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

    $apiKey = ''; // read from .env via helpers
    if (function_exists('ops_env')) {
        $apiKey = ops_env('ANTHROPIC_API_KEY');
    } else {
        // Fallback: read directly from .env file
        $envFile = dirname(__DIR__, 3) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile) ?: [] as $line) {
                if (str_starts_with(trim($line), 'ANTHROPIC_API_KEY=')) {
                    $apiKey = trim(explode('=', $line, 2)[1] ?? '');
                    break;
                }
            }
        }
    }

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

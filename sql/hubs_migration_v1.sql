-- =============================================================================
-- COG$ of Australia Foundation — Management Hubs (v1)
-- Adds 9 area-scoped management hubs + Mainspring aggregate.
-- Reuses partner_op_threads / partner_op_replies / partner_op_broadcast_reads
-- for per-hub chat/forum. Adds hub_projects for member-created projects.
--
-- Deploy order: SQL (this file) → PHP routes → HTML/CSS/JS hub pages → wallet.
-- Run once via phpMyAdmin against cogsaust_TRUST.
--
-- All statements are idempotent where the DB supports it. Re-running is safe
-- for the CREATE TABLE IF NOT EXISTS sections; the ALTER TABLE uses a
-- conditional pattern via information_schema.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) members.hub_roster_visible — per-member opt-out from hub roster lists.
-- Default 1 (visible). Uses information_schema guard so it is safe to re-run.
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'members'
     AND COLUMN_NAME  = 'hub_roster_visible'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `members`
     ADD COLUMN `hub_roster_visible` TINYINT(1) NOT NULL DEFAULT 1
       COMMENT ''1 = appear in hub roster lists for enrolled areas; 0 = hidden''
     AFTER `participation_completed_at`',
  'SELECT ''members.hub_roster_visible already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2) hub_projects — member-initiated projects scoped to one participation area.
-- Lightweight: a project is a title + summary + status. Optional escalation
-- to a community poll via linked_poll_id (community_polls.id).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hub_projects` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_key` VARCHAR(60) NOT NULL
    COMMENT 'One of the 9 participation area keys',
  `title` VARCHAR(255) NOT NULL,
  `summary` TEXT DEFAULT NULL,
  `body` MEDIUMTEXT DEFAULT NULL,
  `status` ENUM('proposed','active','paused','completed','archived')
    NOT NULL DEFAULT 'proposed',
  `lead_type` ENUM('trustee','member','fnac','fnac_jlalc')
    NOT NULL DEFAULT 'member',
  `lead_member_id` INT(10) UNSIGNED DEFAULT NULL
    COMMENT 'Originating / coordinating member',
  `target_close_at` DATE DEFAULT NULL,
  `linked_poll_id` BIGINT(20) UNSIGNED DEFAULT NULL
    COMMENT 'Optional FK to community_polls.id when escalated to a binding poll',
  `created_by_member_id` INT(10) UNSIGNED DEFAULT NULL,
  `created_by_admin_user_id` INT(10) UNSIGNED DEFAULT NULL,
  `participant_count` INT(10) UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Denormalised count of hub_project_participants rows',
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp()
    ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_area_status`    (`area_key`,`status`),
  KEY `idx_lead_member`    (`lead_member_id`),
  KEY `idx_created_member` (`created_by_member_id`),
  KEY `idx_linked_poll`    (`linked_poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hub-scoped management projects (member-initiated)';

-- -----------------------------------------------------------------------------
-- 3) hub_project_participants — members opting into a specific project.
-- Distinct from area enrolment (participation_answers).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hub_project_participants` (
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `member_id` INT(10) UNSIGNED NOT NULL,
  `role` ENUM('coordinator','participant','reviewer')
    NOT NULL DEFAULT 'participant',
  `joined_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`project_id`,`member_id`),
  KEY `idx_member` (`member_id`),
  KEY `idx_project_role` (`project_id`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Members opted into specific hub projects';

-- -----------------------------------------------------------------------------
-- 4) hub_project_comments — discussion thread attached to a project.
-- Distinct from the area-level forum (partner_op_threads) — these are
-- scoped to a single project.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hub_project_comments` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `member_id` INT(10) UNSIGNED DEFAULT NULL,
  `admin_user_id` INT(10) UNSIGNED DEFAULT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_created` (`project_id`,`created_at`),
  KEY `idx_member`          (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-project discussion comments';

-- -----------------------------------------------------------------------------
-- 5) v_hub_roster — members visible in each area's roster.
-- Partner is included iff: active, completed participation, roster_visible=1,
-- and area_key appears in participation_answers JSON array.
--
-- Uses JSON_SEARCH rather than JSON_TABLE.  JSON_TABLE-derived columns trip
-- MariaDB 10.6's privilege checker when the executing user (e.g. cogsaust)
-- is not the view's DEFINER (#1143 ANY command denied ... in '(temporary)').
-- JSON_SEARCH is the pre-JSON_TABLE pattern and has no such quirk.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS `v_hub_roster`;
CREATE SQL SECURITY INVOKER VIEW `v_hub_roster` AS
SELECT
  m.id                 AS member_id,
  m.member_number      AS member_number,
  CONCAT(
    SUBSTRING(m.member_number, 1, 4), ' ',
    REPEAT('•', 4), ' ',
    REPEAT('•', 4), ' ',
    SUBSTRING(m.member_number, -4)
  )                    AS member_number_masked,
  m.first_name         AS first_name,
  m.state_code         AS state_code,
  m.suburb             AS suburb,
  m.participation_completed_at AS joined_area_at,
  m.updated_at         AS last_active_at,
  ak.area_key          AS area_key
FROM `members` m
JOIN (
  SELECT 'operations_oversight'   AS area_key UNION ALL
  SELECT 'governance_polls'                   UNION ALL
  SELECT 'esg_proxy_voting'                   UNION ALL
  SELECT 'first_nations'                      UNION ALL
  SELECT 'community_projects'                 UNION ALL
  SELECT 'technology_blockchain'              UNION ALL
  SELECT 'financial_oversight'                UNION ALL
  SELECT 'place_based_decisions'              UNION ALL
  SELECT 'education_outreach'
) ak
  ON JSON_SEARCH(m.participation_answers, 'one', ak.area_key) IS NOT NULL
WHERE m.is_active = 1
  AND m.participation_completed = 1
  AND m.hub_roster_visible = 1;

-- -----------------------------------------------------------------------------
-- 6) v_hub_projects_live — projects with current participant count and
-- area/status grouping.  Denormalised count already on hub_projects; this
-- view is for list queries that also want the lead member's first name.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS `v_hub_projects_live`;
CREATE SQL SECURITY INVOKER VIEW `v_hub_projects_live` AS
SELECT
  p.id,
  p.area_key,
  p.title,
  p.summary,
  p.status,
  p.lead_type,
  p.lead_member_id,
  lm.first_name              AS lead_first_name,
  lm.state_code              AS lead_state_code,
  p.target_close_at,
  p.linked_poll_id,
  p.participant_count,
  p.created_at,
  p.updated_at
FROM `hub_projects` p
LEFT JOIN `members` lm ON lm.id = p.lead_member_id
WHERE p.status != 'archived';

-- -----------------------------------------------------------------------------
-- 7) v_hub_activity — last events per area for Mainspring feed.
-- Unions: new threads, new projects, project status changes.
-- Ordered by occurred_at desc. Caller applies LIMIT per area.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS `v_hub_activity`;
CREATE SQL SECURITY INVOKER VIEW `v_hub_activity` AS
SELECT
  t.area_key                          AS area_key,
  'thread'                            AS event_type,
  t.id                                AS ref_id,
  t.subject                           AS title,
  t.direction                         AS meta,
  t.initiated_by_member_id            AS actor_member_id,
  t.created_at                        AS occurred_at
FROM `partner_op_threads` t
WHERE t.status != 'archived'
UNION ALL
SELECT
  p.area_key                          AS area_key,
  'project'                           AS event_type,
  p.id                                AS ref_id,
  p.title                             AS title,
  p.status                            AS meta,
  p.created_by_member_id              AS actor_member_id,
  p.created_at                        AS occurred_at
FROM `hub_projects` p
WHERE p.status != 'archived';

-- -----------------------------------------------------------------------------
-- 8) v_hub_mainspring_summary — per-area counts for Mainspring grid tiles.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS `v_hub_mainspring_summary`;
CREATE SQL SECURITY INVOKER VIEW `v_hub_mainspring_summary` AS
SELECT
  ak.area_key,
  (SELECT COUNT(*) FROM `v_hub_roster` r      WHERE r.area_key = ak.area_key) AS member_count,
  (SELECT COUNT(*) FROM `partner_op_threads` t
      WHERE t.area_key = ak.area_key AND t.status != 'archived')              AS thread_count,
  (SELECT COUNT(*) FROM `hub_projects` p
      WHERE p.area_key = ak.area_key AND p.status IN ('proposed','active'))   AS active_project_count,
  (SELECT MAX(occurred_at) FROM `v_hub_activity` a
      WHERE a.area_key = ak.area_key)                                          AS last_activity_at
FROM (
  SELECT 'operations_oversight'  AS area_key UNION ALL
  SELECT 'governance_polls'           UNION ALL
  SELECT 'esg_proxy_voting'           UNION ALL
  SELECT 'first_nations'              UNION ALL
  SELECT 'community_projects'         UNION ALL
  SELECT 'technology_blockchain'      UNION ALL
  SELECT 'financial_oversight'        UNION ALL
  SELECT 'place_based_decisions'      UNION ALL
  SELECT 'education_outreach'
) ak;

-- =============================================================================
-- Verification queries (run manually after migration to confirm)
-- =============================================================================
-- SELECT COUNT(*) AS members_with_flag FROM members WHERE hub_roster_visible IS NOT NULL;
-- SHOW CREATE TABLE hub_projects;
-- SHOW CREATE TABLE hub_project_participants;
-- SHOW CREATE TABLE hub_project_comments;
-- SELECT * FROM v_hub_mainspring_summary;
-- SELECT area_key, COUNT(*) AS members FROM v_hub_roster GROUP BY area_key;

-- End of hubs_migration_v1.sql

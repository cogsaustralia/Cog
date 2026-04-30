-- =============================================================================
-- COG$ of Australia Foundation — Hub Roster Name Privacy (v1)
-- Adds hub_roster_show_name to members (default 0 = anonymous).
-- Updates v_hub_roster to respect the new column.
--
-- Deploy order: SQL (this file) → PHP → JS
-- Run once via phpMyAdmin against cogsaust_TRUST.
-- Idempotent — safe to re-run.
-- =============================================================================

-- 1) Add hub_roster_show_name column (default 0 = anonymous, opt-in to show name)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'members'
     AND COLUMN_NAME  = 'hub_roster_show_name'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `members`
     ADD COLUMN `hub_roster_show_name` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = show first name on hub rosters; 0 = anonymous (default)''
     AFTER `hub_roster_visible`',
  'SELECT ''members.hub_roster_show_name already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Recreate v_hub_roster — conditionally expose first_name
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
  CASE WHEN m.hub_roster_show_name = 1
       THEN m.first_name
       ELSE NULL
  END                  AS first_name,
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

-- =============================================================================
-- Verification queries
-- =============================================================================
-- SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members'
--  AND COLUMN_NAME IN ('hub_roster_visible','hub_roster_show_name');
-- SELECT member_id, first_name, state_code, area_key FROM v_hub_roster LIMIT 5;
-- (first_name NULL for members with hub_roster_show_name=0, which is all members initially)

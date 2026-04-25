-- ════════════════════════════════════════════════════════════════════
-- Migration: 2026_04_26_rename_governance_polls_to_research_acquisitions
-- ════════════════════════════════════════════════════════════════════
-- Renames the area_key slug `governance_polls` to `research_acquisitions`
-- across all data tables, JSON columns, log strings, and the two
-- mainspring views that hard-code the area_key UNION list.
--
-- Run order:
--   1. UPDATE data tables (area_key columns)
--   2. UPDATE comma-separated lists (interest_area_keys)
--   3. UPDATE JSON arrays (members.participation_answers)
--   4. UPDATE log strings (wallet_activity)
--   5. DROP and CREATE the two views (cannot ALTER VIEW with new literal)
--
-- Idempotent — safe to re-run. WHERE clauses ensure no double-update.
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- ── 1. Data tables with area_key column ─────────────────────────────

UPDATE `app_error_log`
   SET `area_key` = 'research_acquisitions'
 WHERE `area_key` = 'governance_polls';

UPDATE `hub_projects`
   SET `area_key` = 'research_acquisitions'
 WHERE `area_key` = 'governance_polls';

UPDATE `member_hub_queries`
   SET `area_key` = 'research_acquisitions'
 WHERE `area_key` = 'governance_polls';

UPDATE `partner_op_threads`
   SET `area_key` = 'research_acquisitions'
 WHERE `area_key` = 'governance_polls';

-- ── 2. Comma-separated cross-hub references ─────────────────────────
-- hub_projects.interest_area_keys is a comma-list (no spaces by design).
-- Use REPLACE which is safe because the slug 'governance_polls' does not
-- appear as a substring of any other slug.

UPDATE `hub_projects`
   SET `interest_area_keys` = REPLACE(`interest_area_keys`, 'governance_polls', 'research_acquisitions')
 WHERE `interest_area_keys` LIKE '%governance_polls%';

-- ── 3. JSON arrays (members.participation_answers) ──────────────────
-- participation_answers is a JSON-validated array of strings.
-- JSON_REPLACE doesn't operate on array values; we use a string-level
-- REPLACE on the JSON text. This is safe because:
--   - the slug appears only as a quoted JSON string "governance_polls"
--   - no other slug contains it as a substring
--   - the result remains valid JSON (CHECK constraint will reject if not)

UPDATE `members`
   SET `participation_answers` = REPLACE(
         `participation_answers`,
         '"governance_polls"',
         '"research_acquisitions"'
       )
 WHERE `participation_answers` LIKE '%"governance_polls"%';

-- ── 4. Log strings (wallet_events) ──────────────────────────────────
-- 'Partner participation areas recorded: ...' includes the slug list as
-- comma-separated words. Defensive REPLACE on the message body.

UPDATE `wallet_events`
   SET `description` = REPLACE(`description`, 'governance_polls', 'research_acquisitions')
 WHERE `description` LIKE '%governance_polls%';

-- ── 5. Recreate the two mainspring views ────────────────────────────
-- The UNION-of-literals inside these views hard-codes the area_key list.
-- Drop and recreate with the new slug.

DROP VIEW IF EXISTS `v_hub_mainspring_summary`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_hub_mainspring_summary` AS
SELECT
    `ak`.`area_key` AS `area_key`,
    (SELECT COUNT(0) FROM `v_hub_roster` `r` WHERE `r`.`area_key` = `ak`.`area_key`) AS `member_count`,
    (SELECT COUNT(0) FROM `partner_op_threads` `t` WHERE `t`.`area_key` = `ak`.`area_key` AND `t`.`status` <> 'archived') AS `thread_count`,
    (SELECT COUNT(0) FROM `hub_projects` `p` WHERE `p`.`area_key` = `ak`.`area_key` AND `p`.`status` IN ('proposed','active')) AS `active_project_count`,
    (SELECT MAX(`a`.`occurred_at`) FROM `v_hub_activity` `a` WHERE `a`.`area_key` = `ak`.`area_key`) AS `last_activity_at`
FROM (
    SELECT 'operations_oversight'   AS `area_key` UNION ALL
    SELECT 'research_acquisitions'  AS `area_key` UNION ALL
    SELECT 'esg_proxy_voting'       AS `area_key` UNION ALL
    SELECT 'first_nations'          AS `area_key` UNION ALL
    SELECT 'community_projects'     AS `area_key` UNION ALL
    SELECT 'technology_blockchain'  AS `area_key` UNION ALL
    SELECT 'financial_oversight'    AS `area_key` UNION ALL
    SELECT 'place_based_decisions'  AS `area_key` UNION ALL
    SELECT 'education_outreach'     AS `area_key`
) AS `ak`;

DROP VIEW IF EXISTS `v_hub_roster`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_hub_roster` AS
SELECT
    `m`.`id`            AS `member_id`,
    `m`.`member_number` AS `member_number`,
    CONCAT(SUBSTR(`m`.`member_number`,1,4),' ',REPEAT('•',4),' ',REPEAT('•',4),' ',SUBSTR(`m`.`member_number`,-4)) AS `member_number_masked`,
    CASE WHEN `m`.`hub_roster_show_name` = 1 THEN `m`.`first_name` ELSE NULL END AS `first_name`,
    `m`.`state_code`                  AS `state_code`,
    `m`.`suburb`                      AS `suburb`,
    `m`.`participation_completed_at`  AS `joined_area_at`,
    `m`.`updated_at`                  AS `last_active_at`,
    `ak`.`area_key`                   AS `area_key`
FROM `members` `m`
JOIN (
    SELECT 'operations_oversight'   AS `area_key` UNION ALL
    SELECT 'research_acquisitions'  AS `area_key` UNION ALL
    SELECT 'esg_proxy_voting'       AS `area_key` UNION ALL
    SELECT 'first_nations'          AS `area_key` UNION ALL
    SELECT 'community_projects'     AS `area_key` UNION ALL
    SELECT 'technology_blockchain'  AS `area_key` UNION ALL
    SELECT 'financial_oversight'    AS `area_key` UNION ALL
    SELECT 'place_based_decisions'  AS `area_key` UNION ALL
    SELECT 'education_outreach'     AS `area_key`
) `ak`
  ON JSON_SEARCH(`m`.`participation_answers`, 'one', `ak`.`area_key`) IS NOT NULL
WHERE `m`.`is_active` = 1
  AND `m`.`participation_completed` = 1
  AND `m`.`hub_roster_visible` = 1;

COMMIT;

-- ════════════════════════════════════════════════════════════════════
-- VERIFICATION (run these manually after the migration completes)
-- ════════════════════════════════════════════════════════════════════
-- SELECT COUNT(*) FROM app_error_log         WHERE area_key='governance_polls';   -- expect 0
-- SELECT COUNT(*) FROM hub_projects          WHERE area_key='governance_polls';   -- expect 0
-- SELECT COUNT(*) FROM hub_projects          WHERE interest_area_keys LIKE '%governance_polls%'; -- expect 0
-- SELECT COUNT(*) FROM member_hub_queries    WHERE area_key='governance_polls';   -- expect 0
-- SELECT COUNT(*) FROM partner_op_threads    WHERE area_key='governance_polls';   -- expect 0
-- SELECT COUNT(*) FROM members               WHERE participation_answers LIKE '%"governance_polls"%'; -- expect 0
-- SELECT COUNT(*) FROM wallet_events         WHERE description LIKE '%governance_polls%'; -- expect 0
-- SELECT * FROM v_hub_mainspring_summary WHERE area_key='research_acquisitions';  -- expect 1 row
-- SELECT * FROM v_hub_roster             WHERE area_key='research_acquisitions';  -- expect Thomas (member 1)

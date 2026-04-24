-- =============================================================================
-- COG$ of Australia Foundation — Hub Project Interest Areas (Phase 1)
-- Adds cross-hub project reference mechanism via interest_area_keys column.
--
-- One-time schema extension enabling a project owned by one hub to appear as
-- a read-only reference in other hubs. Foundation for the Community Mobile
-- programme (Phase 2) and any future cross-hub programme.
--
-- Deploy order: SQL (this file) → PHP routes → HTML/CSS/JS hub pages.
-- Run once via phpMyAdmin against cogsaust_TRUST. Safe to re-run.
--
-- The ALTER uses a conditional pattern via information_schema, so re-running
-- is a no-op if the column already exists.
--
-- No data migration required. Existing rows default interest_area_keys to
-- NULL (owner hub only). Trustee-created projects populate this at create
-- time via the modified vault/hub-projects POST endpoint.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) hub_projects.interest_area_keys — comma-separated area_key list
-- Nullable. NULL = owner hub only (existing behaviour).
-- Non-NULL = project appears as a read-only reference in those hubs too.
-- Values validated server-side against hubAreaKeys() in vault-hubs.php.
-- -----------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'hub_projects'
     AND COLUMN_NAME  = 'interest_area_keys'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `hub_projects`
     ADD COLUMN `interest_area_keys` VARCHAR(500) NULL DEFAULT NULL
       COMMENT ''Comma-separated list of area_keys where this project appears as a read-only reference. NULL = owner hub only. Set by Trustee at create time.''
     AFTER `area_key`',
  'SELECT ''hub_projects.interest_area_keys already exists — no-op'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Verification queries (run manually after migration to confirm)
-- =============================================================================
-- Confirm column present:
--   SHOW CREATE TABLE hub_projects;
--
-- Confirm no existing rows are affected (all NULL at rest):
--   SELECT COUNT(*) AS total_projects,
--          SUM(CASE WHEN interest_area_keys IS NULL THEN 1 ELSE 0 END) AS null_count,
--          SUM(CASE WHEN interest_area_keys IS NOT NULL THEN 1 ELSE 0 END) AS set_count
--     FROM hub_projects;
--   -- Expected: set_count = 0 immediately after migration.

-- End of phase1_hub_project_interest_areas_v1.sql

-- ============================================================
-- Migration: 2026_04_24_widen_session_id_v2
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Widens session_id in deed_version_anchors — same storageSessionId()
-- prefix issue as declaration_execution_records (already fixed).
-- Deploy order: SQL only — no PHP/HTML changes required
-- ============================================================

ALTER TABLE `deed_version_anchors`
  MODIFY COLUMN `session_id` varchar(80) DEFAULT NULL
    COMMENT 'UUID or deed_key:UUID prefixed session identifier';

-- ============================================================
-- Verify after running:
-- SHOW COLUMNS FROM deed_version_anchors LIKE 'session_id';
-- Expected: varchar(80)
-- ============================================================

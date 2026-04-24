-- ============================================================
-- Migration: 2026_04_24_widen_session_id
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Reason: storageSessionId() prefixes session UUIDs with deed_key
--   e.g. 'sub_trust_a:550e8400-...' = up to 49 chars, exceeds char(36)
-- Deploy order: SQL only — no PHP/HTML changes required
-- ============================================================

ALTER TABLE `declaration_execution_records`
  MODIFY COLUMN `session_id` varchar(80) NOT NULL
    COMMENT 'UUID or deed_key:UUID prefixed session identifier';

ALTER TABLE `declaration_witness_attestations`
  MODIFY COLUMN `session_id` varchar(80) NOT NULL
    COMMENT 'UUID or deed_key:UUID prefixed session identifier';

-- ============================================================
-- Verify after running:
-- SHOW COLUMNS FROM declaration_execution_records LIKE 'session_id';
-- Expected: varchar(80)
-- SHOW COLUMNS FROM declaration_witness_attestations LIKE 'session_id';
-- Expected: varchar(80)
-- ============================================================

-- ============================================================
-- Migration: 2026_04_26_jvpa_versions_unique_current
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL only — no PHP changes required.
-- Prerequisite: none.
--
-- Issue
--   jvpa_versions.is_current is tinyint(1) with no constraint, so two
--   rows could both be flagged is_current=1. Application code falls
--   back to ORDER BY id DESC LIMIT 1 in that case, but the resulting
--   "current" version becomes implicit and depends on insert order.
--   For an audit-defensible chain, only one row may be current at a
--   time and the DB should enforce it.
--
-- Fix
--   Add a virtual generated column that is 1 only when is_current=1
--   and NULL otherwise, plus a UNIQUE index on that column. MariaDB
--   treats multiple NULLs in a UNIQUE index as non-conflicting, so
--   any number of is_current=0 (or "not current") rows are allowed,
--   but at most one row may satisfy is_current=1.
--
--   Existing column semantics (0/1) are preserved verbatim — every
--   read site (`WHERE is_current = 1`) and write site
--   (`SET is_current = 0, superseded_at = NOW()` in
--   admin/founding_documents.php) continues to work unmodified.
--   The virtual column is recomputed automatically on every read.
--
-- Verification (after running)
--   SHOW CREATE TABLE jvpa_versions;
--   -- should now show:
--   --   `is_current_when_true` tinyint(1) GENERATED ALWAYS AS (...)
--   --   UNIQUE KEY `uq_jvpa_versions_is_current_when_true` (...)
--
--   SELECT id, version_label, is_current, is_current_when_true
--   FROM jvpa_versions ORDER BY id;
--   -- expect: v8 row shows is_current=1, is_current_when_true=1
--   --         any past versions show is_current=0, is_current_when_true=NULL
--
-- Rollback (if ever needed)
--   ALTER TABLE jvpa_versions
--     DROP INDEX uq_jvpa_versions_is_current_when_true,
--     DROP COLUMN is_current_when_true;
-- ============================================================

ALTER TABLE `jvpa_versions`
  ADD COLUMN `is_current_when_true` tinyint(1)
    GENERATED ALWAYS AS (IF(`is_current` = 1, 1, NULL)) VIRTUAL
    COMMENT 'Virtual column for UNIQUE-on-is_current=1 constraint. NULL when is_current=0.',
  ADD UNIQUE KEY `uq_jvpa_versions_is_current_when_true` (`is_current_when_true`);

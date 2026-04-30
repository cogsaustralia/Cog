-- =============================================================================
-- COG$ of Australia Foundation — Member Voice Submission (Phase 4)
-- Migration: phase4_member_voice_submissions_v1.sql
-- Issued: 26 Apr 2026
-- Target DB: cogsaust_TRUST (live) / cogs_mirror (local)
-- Deploy order: SQL (this file) → PHP → JS/CSS/HTML
-- Run via phpMyAdmin against cogsaust_TRUST. Idempotent on re-run.
--
-- Purpose:
--   Add infrastructure for the Member Voice Submission feature — text/audio/video
--   submissions captured from members at and after join, with explicit consent
--   for use on COG$ social media. Files are stored outside public_html and
--   served via authenticated PHP only. Every submission requires admin clearance
--   before use.
--
-- Tables created:
--   1. voice_submission_consent_versions (consent text history)
--   2. member_voice_submissions          (the submissions themselves)
--
-- ENUMs modified:
--   1. audit_access_log.access_type (extended with voice-submission events)
--
-- No existing rows are modified. No existing tables are dropped.
-- =============================================================================

-- Set strict mode for this session so any subtle violation surfaces immediately.
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. voice_submission_consent_versions
-- -----------------------------------------------------------------------------
-- Holds the immutable history of consent text bodies. Each submission records
-- the version_key it was made under. Consent text changes never retroactively
-- apply to existing submissions.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `voice_submission_consent_versions` (
  `version_key`       varchar(20)   NOT NULL,
  `consent_text_body` text          NOT NULL,
  `effective_from`    datetime      NOT NULL,
  `effective_to`      datetime      DEFAULT NULL,
  `created_at`        datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consent text history for member_voice_submissions — every version a member could have agreed to.';

-- Seed v1.0 — the initial consent text.
INSERT INTO `voice_submission_consent_versions`
  (`version_key`, `consent_text_body`, `effective_from`)
VALUES (
  'v1.0',
  'I agree my submission can be used on COG$ social media as a member quote. My first name and state may be shown. I can withdraw consent at any time by emailing info@cogsaustralia.org or from my member dashboard.',
  '2026-04-29 00:00:00'
)
ON DUPLICATE KEY UPDATE
  `consent_text_body` = VALUES(`consent_text_body`);

-- -----------------------------------------------------------------------------
-- 2. member_voice_submissions
-- -----------------------------------------------------------------------------
-- The main feature table. One row per submission attempt. Files referenced by
-- file_path are stored outside public_html at:
--   /home4/cogsaust/secure_uploads/voice_submissions/{partner_id}/{id}.{ext}
-- and never URL-accessible directly.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `member_voice_submissions` (
  `id`                bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_id`        bigint(20) UNSIGNED NOT NULL
    COMMENT 'FK partners.id — the submitting member as a partner record',

  `submission_type`   enum('text','audio','video') NOT NULL,

  -- Text submissions only
  `text_content`      text          DEFAULT NULL
    COMMENT 'Full text body for text submissions; NULL for audio/video',
  `text_char_count`   int(10) UNSIGNED DEFAULT NULL
    COMMENT 'Computed at submit time for analytics',

  -- File submissions (audio/video) only
  `file_path`         varchar(500)  DEFAULT NULL
    COMMENT 'Absolute server path under /home4/cogsaust/secure_uploads/voice_submissions/, NEVER inside public_html',
  `file_original_name` varchar(255) DEFAULT NULL
    COMMENT 'Original filename as supplied by browser — for audit only',
  `file_mime_type`    varchar(100)  DEFAULT NULL
    COMMENT 'Verified by finfo magic bytes server-side, not header trust',
  `file_size_bytes`   bigint(20) UNSIGNED DEFAULT NULL,
  `duration_seconds`  int(10) UNSIGNED DEFAULT NULL
    COMMENT 'Verified server-side via ffprobe where available',

  -- Consent (always present)
  `consent_text_version` varchar(20) NOT NULL DEFAULT 'v1.0'
    COMMENT 'FK voice_submission_consent_versions.version_key',
  `consent_given_at`  datetime      NOT NULL,

  -- Display fields (defaults from members table; member can override at submit)
  `display_name_first` varchar(60)  DEFAULT NULL
    COMMENT 'First name override; if NULL, application falls back to members.first_name at render time',
  `display_state`     varchar(40)   DEFAULT NULL
    COMMENT 'State/territory at submission time; defaults from members.state_code, member-editable',

  -- Compliance review (admin moderation)
  `compliance_status` enum('pending_review','cleared_for_use','rejected','withdrawn')
                      NOT NULL DEFAULT 'pending_review',
  `compliance_reviewer_admin_id` int(10) UNSIGNED DEFAULT NULL
    COMMENT 'Loose FK admin_users.id — matches platform pattern (no CONSTRAINT)',
  `compliance_reviewed_at` datetime DEFAULT NULL,
  `compliance_notes`  text          DEFAULT NULL
    COMMENT 'Internal notes — never shown to member',
  `rejection_reason_to_member` text DEFAULT NULL
    COMMENT 'Member-facing reason; populated only on rejection',

  -- Usage tracking
  `used_in_post_url`  varchar(500)  DEFAULT NULL
    COMMENT 'Permalink to the FB or YT post where this submission was used',
  `used_at`           datetime      DEFAULT NULL,
  `used_by_admin_id`  int(10) UNSIGNED DEFAULT NULL
    COMMENT 'Loose FK admin_users.id',

  -- Withdrawal
  `withdrawn_at`      datetime      DEFAULT NULL,
  `withdrawn_reason`  text          DEFAULT NULL
    COMMENT 'Member-supplied reason on withdrawal; or admin note if admin-withdrawn',

  -- Submission audit
  `submission_ip`     varchar(45)   DEFAULT NULL,
  `submission_user_agent` varchar(255) DEFAULT NULL,

  -- Timestamps
  `created_at`        datetime      NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime      NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_mvs_partner_id` (`partner_id`),
  KEY `idx_mvs_compliance_status` (`compliance_status`),
  KEY `idx_mvs_created_at` (`created_at`),
  KEY `idx_mvs_consent_version` (`consent_text_version`),
  CONSTRAINT `fk_mvs_partner`
    FOREIGN KEY (`partner_id`)
    REFERENCES `partners` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_mvs_consent_version`
    FOREIGN KEY (`consent_text_version`)
    REFERENCES `voice_submission_consent_versions` (`version_key`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Member voice submissions for Fair Say Relay campaign — text/audio/video with explicit consent and admin moderation.';

-- -----------------------------------------------------------------------------
-- 3. Extend audit_access_log.access_type ENUM
-- -----------------------------------------------------------------------------
-- The audit_access_log table is admin-only (admin_user_id NOT NULL). It tracks
-- admin actions on member submissions. Member-side events (submission, withdrawal)
-- are tracked in the member_voice_submissions row's own datetime columns.
--
-- Existing values (must be preserved exactly when extending an ENUM):
--   'login','view_invariants','view_ledger','view_balance_sheet',
--   'view_reconciliation','export','logout'
-- New values for voice submission admin actions:
--   'view_voice_submission','approve_voice_submission','reject_voice_submission',
--   'mark_voice_submission_used','withdraw_voice_submission_admin',
--   'delete_voice_submission_file'
--
-- The MODIFY COLUMN below lists ALL existing values plus the new ones.
-- Per platform memory: every ALTER on an ENUM must list every existing value
-- before appending. Never use a partial list — that drops values.
-- -----------------------------------------------------------------------------

ALTER TABLE `audit_access_log`
  MODIFY COLUMN `access_type` enum(
    'login',
    'view_invariants',
    'view_ledger',
    'view_balance_sheet',
    'view_reconciliation',
    'export',
    'logout',
    'view_voice_submission',
    'approve_voice_submission',
    'reject_voice_submission',
    'mark_voice_submission_used',
    'withdraw_voice_submission_admin',
    'delete_voice_submission_file'
  ) NOT NULL DEFAULT 'login';

-- -----------------------------------------------------------------------------
-- 4. Verification queries (informational — not run as part of migration)
-- -----------------------------------------------------------------------------
-- After running this migration, verify with:
--
--   SELECT COUNT(*) FROM voice_submission_consent_versions;        -- expect 1
--   SELECT COUNT(*) FROM member_voice_submissions;                 -- expect 0
--   SHOW COLUMNS FROM audit_access_log WHERE Field = 'access_type';
--     -- expect ENUM with 13 values total
--   SHOW CREATE TABLE member_voice_submissions;
--     -- inspect indexes and FKs
--
-- Rollback (only if absolutely required — destroys all submissions):
--   DROP TABLE IF EXISTS member_voice_submissions;
--   DROP TABLE IF EXISTS voice_submission_consent_versions;
--   ALTER TABLE audit_access_log MODIFY COLUMN access_type
--     enum('login','view_invariants','view_ledger','view_balance_sheet',
--          'view_reconciliation','export','logout') NOT NULL DEFAULT 'login';
-- -----------------------------------------------------------------------------

COMMIT;

-- End of migration phase4_member_voice_submissions_v1.sql

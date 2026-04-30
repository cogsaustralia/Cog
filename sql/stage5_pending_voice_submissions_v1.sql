-- =============================================================================
-- COG$ of Australia Foundation — Stage 5: Pending Voice Submissions
-- Migration: sql/stage5_pending_voice_submissions_v1.sql
-- Issued: 30 Apr 2026
-- Target DB: cogsaust_TRUST (live) / cogs_mirror (local)
-- Deploy order: SQL (this file first) → PHP → HTML/JS
-- Run via phpMyAdmin against cogsaust_TRUST. Idempotent on re-run.
--
-- Purpose:
--   Unauthenticated cold-path voice submissions captured on /welcome/ before
--   the visitor joins. Linked to partner record on member creation via
--   voice_session_token. Fed into admin moderation queue via existing
--   admin/voice_submissions.php — no structural change to that pipeline.
--
-- Tables created:
--   1. pending_voice_submissions
-- =============================================================================

SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `pending_voice_submissions` (
  `id`                bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
  `session_token`     varchar(64)          NOT NULL
    COMMENT 'Random token from visitor browser — links submission to member on join',
  `ip_hash`           varchar(64)          NOT NULL
    COMMENT 'SHA-256(APP_SALT + remote_addr) — no raw IP stored',
  `text_content`      varchar(280)         NOT NULL
    COMMENT 'Visitor voice text, max 280 chars',
  `ref_source`        varchar(20)          DEFAULT NULL
    COMMENT 'UTM source from ?ref= param (fb, yt, etc.)',
  `linked_partner_id` bigint(20) UNSIGNED  DEFAULT NULL
    COMMENT 'Loose FK partners.id — set when visitor joins and submission is claimed',
  `linked_at`         datetime             DEFAULT NULL,
  `created_at`        datetime             NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_token` (`session_token`),
  KEY `idx_linked_partner` (`linked_partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unauthenticated cold-path voice submissions from /welcome/ — linked to partner on join.';

COMMIT;
EOF < /dev/null
-- ============================================================
-- Migration: lead_captures
-- Date: 2026-05-02
-- Purpose: Cold-path email + optional phone capture for the
-- /seat/ lead magnet funnel (Marketing Pivot Brief v1.0).
-- Lead magnet is the trust-building step BEFORE any $4 ask.
-- ============================================================

CREATE TABLE IF NOT EXISTS `lead_captures` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`                 VARCHAR(190) NOT NULL,
  `phone`                 VARCHAR(40)  DEFAULT NULL
                          COMMENT 'Optional, captured on /seat/inside/ Page B',
  `source`                VARCHAR(60)  DEFAULT NULL
                          COMMENT 'utm_source from URL (fb-a, fb-b, yt-a, yt-b, organic)',
  `landing_page`          VARCHAR(60)  DEFAULT NULL
                          COMMENT 'seat | seat-inside | other',
  `ip_hash`               CHAR(64)     DEFAULT NULL
                          COMMENT 'SHA-256 with APP_SALT, never raw IP',
  `user_agent_hash`       CHAR(64)     DEFAULT NULL,
  `email_sent_at`         DATETIME     DEFAULT NULL
                          COMMENT 'First nurture email send time',
  `converted_to_member_id` INT UNSIGNED DEFAULT NULL
                          COMMENT 'FK -> members.id when this lead joins for $4',
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_source` (`source`),
  KEY `idx_created` (`created_at`),
  KEY `idx_converted` (`converted_to_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
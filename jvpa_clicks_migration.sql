-- ============================================================
-- Migration: jvpa_pdf_clicks
-- Run in phpMyAdmin against cogsaust_TRUST
-- Run BEFORE deploying PHP/HTML changes
-- ============================================================
CREATE TABLE IF NOT EXISTS `jvpa_pdf_clicks` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token`       varchar(64)      NOT NULL COMMENT 'Anonymous browser token (crypto.randomUUID or fallback)',
  `page_context`        varchar(64)      NOT NULL DEFAULT 'join' COMMENT 'Which page the click came from',
  `referrer_code`       varchar(64)      DEFAULT NULL COMMENT 'Partner/invite code present in URL at time of click',
  `ip_hash`             varchar(64)      DEFAULT NULL COMMENT 'SHA-256 of IP — not stored raw',
  `user_agent_snippet`  varchar(120)     DEFAULT NULL,
  `clicked_at`          datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_session`     (`session_token`),
  KEY `idx_clicked_at`  (`clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anonymous JVPA PDF clicks from the join page, pre-auth.';

-- ============================================================
-- Migration: page_visits + funnel_events
-- Run in phpMyAdmin against cogsaust_TRUST
-- Run BEFORE deploying PHP/HTML changes
--
-- Privacy: stores hashed IP only (SHA-256), truncated UA, anonymous
-- session token. No raw PII. Aligns with the jvpa_pdf_clicks pattern.
-- ============================================================

CREATE TABLE IF NOT EXISTS `page_visits` (
  `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token`      varchar(64)      NOT NULL COMMENT 'Anonymous browser session UUID',
  `path`               varchar(64)      NOT NULL COMMENT 'Page key: index|intro|join|thank-you|welcome|skeptic',
  `ref_source`         varchar(32)      DEFAULT NULL COMMENT 'fb|yt|email|direct|other (from ?ref=)',
  `utm_campaign`       varchar(64)      DEFAULT NULL,
  `utm_content`        varchar(64)      DEFAULT NULL,
  `referrer_host`      varchar(120)     DEFAULT NULL COMMENT 'Host portion only of HTTP_REFERER',
  `partner_code`       varchar(40)      DEFAULT NULL COMMENT 'Invite code present in URL at landing time',
  `ip_hash`            varchar(64)      DEFAULT NULL COMMENT 'SHA-256 of IP — never raw',
  `user_agent_snippet` varchar(120)     DEFAULT NULL,
  `is_mobile`          tinyint(1)       NOT NULL DEFAULT 0,
  `visited_at`         datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pv_session`    (`session_token`),
  KEY `idx_pv_visited_at` (`visited_at`),
  KEY `idx_pv_path_time`  (`path`, `visited_at`),
  KEY `idx_pv_ref_time`   (`ref_source`, `visited_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anonymous page visits — first-party only, no third-party trackers.';


CREATE TABLE IF NOT EXISTS `funnel_events` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token`  varchar(64)      NOT NULL,
  `event`          varchar(48)      NOT NULL COMMENT 'See approved event vocabulary in track.php',
  `path`           varchar(64)      DEFAULT NULL COMMENT 'Page where event fired',
  `metadata`       varchar(255)     DEFAULT NULL COMMENT 'Optional small JSON or string — never PII',
  `occurred_at`    datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fe_session`     (`session_token`),
  KEY `idx_fe_event_time`  (`event`, `occurred_at`),
  KEY `idx_fe_occurred_at` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Funnel events tied to anonymous session — drives conversion analytics.';
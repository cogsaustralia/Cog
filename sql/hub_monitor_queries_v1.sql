-- =============================================================================
-- COG$ of Australia Foundation — Hub Monitor & Member Queries (v1)
-- Creates:
--   app_error_log         — permanent API error capture (super_admin only)
--   member_hub_queries    — member questions raised from hub pages
--
-- Deploy order: SQL → PHP → JS
-- Run via phpMyAdmin against cogsaust_TRUST. Idempotent.
-- =============================================================================

-- 1) app_error_log — permanent. Notify admin when table reaches 10 MB.
CREATE TABLE IF NOT EXISTS `app_error_log` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `route`            VARCHAR(120)        NOT NULL DEFAULT ''
                       COMMENT 'API route e.g. vault/hub',
  `http_status`      SMALLINT(5) UNSIGNED NOT NULL DEFAULT 500,
  `error_message`    TEXT                NOT NULL,
  `area_key`         VARCHAR(60)         DEFAULT NULL,
  `member_id`        INT(10) UNSIGNED    DEFAULT NULL,
  `request_method`   VARCHAR(10)         NOT NULL DEFAULT 'GET',
  `ip_hash`          VARCHAR(64)         DEFAULT NULL
                       COMMENT 'SHA-256 of IP — no raw IP stored',
  `ua_hash`          VARCHAR(64)         DEFAULT NULL
                       COMMENT 'SHA-256 of user-agent',
  `acknowledged`     TINYINT(1)          NOT NULL DEFAULT 0,
  `acknowledged_by`  INT(10) UNSIGNED    DEFAULT NULL,
  `acknowledged_at`  DATETIME            DEFAULT NULL,
  `created_at`       DATETIME            NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_route_status` (`route`, `http_status`),
  KEY `idx_created`      (`created_at`),
  KEY `idx_unack`        (`acknowledged`),
  KEY `idx_member`       (`member_id`),
  KEY `idx_area`         (`area_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Permanent API error log — super_admin only. Alert at 10 MB.';

-- 2) member_hub_queries — queries raised from hub pages by members
CREATE TABLE IF NOT EXISTS `member_hub_queries` (
  `id`                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`            INT(10) UNSIGNED    NOT NULL,
  `area_key`             VARCHAR(60)         NOT NULL,
  `subject`              VARCHAR(255)        NOT NULL,
  `body`                 MEDIUMTEXT          NOT NULL,
  `transparency`         ENUM('private','hub_members','public_record')
                           NOT NULL DEFAULT 'private'
                           COMMENT 'private=admin+member only; hub_members=enrolled see it; public_record=hub broadcast',
  `status`               ENUM('open','in_review','resolved','closed')
                           NOT NULL DEFAULT 'open',
  `admin_notes`          TEXT                DEFAULT NULL
                           COMMENT 'Internal — never shown to members',
  `reply_thread_id`      BIGINT(20) UNSIGNED DEFAULT NULL
                           COMMENT 'FK partner_op_threads.id — private reply',
  `reply_broadcast_id`   BIGINT(20) UNSIGNED DEFAULT NULL
                           COMMENT 'FK partner_op_threads.id — hub broadcast',
  `assigned_to_admin_id` INT(10) UNSIGNED    DEFAULT NULL,
  `created_at`           DATETIME            NOT NULL DEFAULT current_timestamp(),
  `updated_at`           DATETIME            NOT NULL DEFAULT current_timestamp()
                           ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_member`       (`member_id`),
  KEY `idx_area_status`  (`area_key`, `status`),
  KEY `idx_status`       (`status`),
  KEY `idx_created`      (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Member queries raised from management hub pages';

-- 3) v_app_error_summary — grouped for admin dashboard
DROP VIEW IF EXISTS `v_app_error_summary`;
CREATE SQL SECURITY INVOKER VIEW `v_app_error_summary` AS
SELECT
  route,
  http_status,
  LEFT(error_message, 120)   AS error_snippet,
  COUNT(*)                    AS occurrence_count,
  MIN(created_at)             AS first_seen,
  MAX(created_at)             AS last_seen,
  SUM(acknowledged = 0)       AS unacknowledged_count,
  MAX(area_key)               AS sample_area_key
FROM `app_error_log`
GROUP BY route, http_status, LEFT(error_message, 120)
ORDER BY last_seen DESC;

-- 4) v_hub_query_summary — open query counts per area
DROP VIEW IF EXISTS `v_hub_query_summary`;
CREATE SQL SECURITY INVOKER VIEW `v_hub_query_summary` AS
SELECT
  area_key,
  COUNT(*)                            AS total_queries,
  SUM(status IN ('open','in_review')) AS open_count,
  SUM(status = 'resolved')            AS resolved_count,
  MAX(created_at)                     AS last_query_at
FROM `member_hub_queries`
GROUP BY area_key;

-- =============================================================================
-- Verification
-- =============================================================================
-- SHOW TABLES WHERE Tables_in_cogsaust_TRUST LIKE 'app_error%' OR Tables_in_cogsaust_TRUST LIKE 'member_hub%' OR Tables_in_cogsaust_TRUST LIKE 'v_app%' OR Tables_in_cogsaust_TRUST LIKE 'v_hub_query%';
-- SELECT (DATA_LENGTH+INDEX_LENGTH)/1048576 AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_error_log';

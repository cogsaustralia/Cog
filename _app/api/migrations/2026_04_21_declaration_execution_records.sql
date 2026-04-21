-- ============================================================
-- Migration: 2026_04_21_declaration_execution_records
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL first, then PHP, then HTML/JS
-- Prerequisite: 2026_04_21_trustee_counterpart_records.sql
--   must already be run (one_time_tokens table must exist)
-- ============================================================

-- ------------------------------------------------------------
-- 1. deed_version_anchors
-- ------------------------------------------------------------
CREATE TABLE `deed_version_anchors` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `deed_key`       varchar(60)  NOT NULL COMMENT 'e.g. declaration_v15_1',
  `deed_title`     varchar(200) NOT NULL,
  `deed_version`   varchar(30)  NOT NULL,
  `execution_date` date         NOT NULL,
  `deed_sha256`    char(64)     NOT NULL COMMENT 'SHA-256 of the canonical executed deed PDF',
  `pdf_filename`   varchar(200) NOT NULL COMMENT 'Filename in /docs/',
  `session_id`     char(36)     DEFAULT NULL,
  `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dva_deed_key` (`deed_key`),
  KEY `idx_dva_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SHA-256 version anchors for executed deed instruments. Immutable after insert.';

-- ------------------------------------------------------------
-- 2. declaration_execution_records
-- ------------------------------------------------------------
CREATE TABLE `declaration_execution_records` (
  `record_id`               char(36)     NOT NULL,
  `session_id`              char(36)     NOT NULL,
  `capacity`                enum('declarant','caretaker_trustee') NOT NULL,
  `executor_full_name`      varchar(200) NOT NULL,
  `executor_address`        varchar(500) NOT NULL,
  `deed_key`                varchar(60)  NOT NULL,
  `deed_title`              varchar(200) NOT NULL,
  `deed_version`            varchar(30)  NOT NULL,
  `execution_date`          date         NOT NULL,
  `deed_sha256`             char(64)     NOT NULL,
  `execution_timestamp_utc` datetime(3)  NOT NULL,
  `ip_device_hash`          char(64)     NOT NULL,
  `ip_device_data`          json         NOT NULL COMMENT 'Privacy Act 1988 (Cth) restricted',
  `acceptance_flag_engaged` tinyint(1)   NOT NULL DEFAULT 0,
  `execution_method`        varchar(200) NOT NULL DEFAULT 'Electronic — Electronic Transactions Act 1999 (Cth) and Electronic Transactions Act 2000 (NSW)',
  `witness_required`        tinyint(1)   NOT NULL DEFAULT 1,
  `witness_attestation_id`  char(36)     DEFAULT NULL,
  `record_sha256`           char(64)     NOT NULL,
  `onchain_commitment_txid` varchar(200) DEFAULT NULL,
  `status`                  enum('executor_complete','witness_pending','fully_executed') NOT NULL DEFAULT 'executor_complete',
  `created_at`              datetime(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `superseded_at`           datetime(3)  DEFAULT NULL,
  PRIMARY KEY (`record_id`),
  UNIQUE KEY `uq_der_session_capacity` (`session_id`, `capacity`),
  KEY `idx_der_deed_key` (`deed_key`),
  KEY `idx_der_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Declaration execution records — one row per capacity per session. Immutable after generation.';

-- ------------------------------------------------------------
-- 3. declaration_witness_attestations
-- ------------------------------------------------------------
CREATE TABLE `declaration_witness_attestations` (
  `attestation_id`            char(36)     NOT NULL,
  `session_id`                char(36)     NOT NULL,
  `witness_full_name`         varchar(200) NOT NULL,
  `witness_dob`               date         NOT NULL COMMENT 'Privacy Act 1988 (Cth) restricted',
  `witness_address`           varchar(500) NOT NULL,
  `witness_occupation`        varchar(200) NOT NULL DEFAULT 'Independent witness',
  `attestation_method`        varchar(300) NOT NULL DEFAULT 'Electronic attestation via audio-visual link — section 14G Electronic Transactions Act 2000 (NSW)',
  `deed_key`                  varchar(60)  NOT NULL,
  `deed_sha256`               char(64)     NOT NULL,
  `attestation_timestamp_utc` datetime(3)  NOT NULL,
  `ip_device_hash`            char(64)     NOT NULL,
  `ip_device_data`            json         NOT NULL COMMENT 'Privacy Act 1988 (Cth) restricted',
  `attestation_flag_engaged`  tinyint(1)   NOT NULL DEFAULT 0,
  `attestation_text`          text         NOT NULL,
  `record_sha256`             char(64)     NOT NULL,
  `onchain_commitment_txid`   varchar(200) DEFAULT NULL,
  `created_at`                datetime(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`attestation_id`),
  UNIQUE KEY `uq_dwa_session` (`session_id`),
  KEY `idx_dwa_deed_key` (`deed_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Witness attestations for deed execution under s.14G ETA 2000 (NSW). Immutable after generation.';

-- ------------------------------------------------------------
-- 4. evidence_vault_entries — add declaration_execution and
--    witness_attestation to entry_type ENUM
-- ------------------------------------------------------------
ALTER TABLE `evidence_vault_entries`
  MODIFY COLUMN `entry_type` enum(
    'address_verification',
    'landholder_title',
    'parcel_verification',
    'identity_document',
    'zone_declaration',
    'fnac_consultation',
    'challenge',
    'override',
    'guardian_declaration',
    'kids_registration',
    'kyc_medicare_submitted',
    'kyc_medicare_verified',
    'kyc_medicare_rejected',
    'besu_attestation',
    'jvpa_accepted',
    'asx_trade_document',
    'trustee_counterpart_record',
    'declaration_execution',
    'witness_attestation'
  ) NOT NULL COMMENT 'asx_trade_document = SHA-256-hashed broker/CHESS PDF anchored in the evidence vault';

-- ============================================================
-- Verify after running:
-- SELECT table_name FROM information_schema.tables
--   WHERE table_schema = DATABASE()
--   AND table_name IN (
--     'deed_version_anchors',
--     'declaration_execution_records',
--     'declaration_witness_attestations'
--   );
-- Expected: 3 rows
--
-- SHOW COLUMNS FROM evidence_vault_entries LIKE 'entry_type';
-- Expected: enum includes declaration_execution, witness_attestation
-- ============================================================

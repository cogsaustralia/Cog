-- ============================================================
-- Migration: 2026_04_24_founding_instrument_documents
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL first, then PHP/HTML
-- ============================================================

-- ------------------------------------------------------------
-- 1. founding_instrument_documents
--    One row per version per instrument.
--    Founding versions: is_amendment = 0, version_label = 'v1.0'
--    Amendment versions: is_amendment = 1, amends_id set
-- ------------------------------------------------------------
CREATE TABLE `founding_instrument_documents` (
  `id`                               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_key`                   varchar(60)  NOT NULL COMMENT 'jvpa | declaration | sub_trust_a | sub_trust_b | sub_trust_c',
  `version_label`                    varchar(30)  NOT NULL COMMENT 'v1.0 for all founding versions; v1.1 etc for amendments',
  `instrument_title`                 varchar(300) NOT NULL,
  `is_amendment`                     tinyint(1)   NOT NULL DEFAULT 0 COMMENT '0 = founding instrument, 1 = amending deed',
  `amends_id`                        int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → founding_instrument_documents.id of the version being amended',
  `amendment_authority_type`         enum('members_poll','court_order','founding_period_correction') DEFAULT NULL COMMENT 'NULL for founding versions',
  `amendment_poll_id`                bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → community_polls.id — poll that authorised the amendment',
  `amendment_governance_direction_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → governance_directions.id',
  `amendment_description`            text DEFAULT NULL COMMENT 'Human-readable summary of what changed',
  `pdf_filename`                     varchar(200) NOT NULL COMMENT 'Filename in /docs/ on server',
  `source_path`                      varchar(500) NOT NULL COMMENT 'Full server path to PDF',
  `sha256_hash`                      char(64)     NOT NULL COMMENT 'SHA-256 of the PDF — integrity anchor',
  `file_size_bytes`                  int(10) UNSIGNED NOT NULL DEFAULT 0,
  `effective_date`                   date         NOT NULL COMMENT 'Date the instrument takes legal effect',
  `executed_at`                      datetime     DEFAULT NULL COMMENT 'Execution timestamp from execution records',
  `jvpa_version_id`                  int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → jvpa_versions.id (JVPA only)',
  `deed_version_anchor_id`           int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → deed_version_anchors.id (deeds only)',
  `evidence_vault_id`                bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → evidence_vault_entries.id',
  `is_current`                       tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1 = current operative version of this instrument',
  `superseded_at`                    datetime     DEFAULT NULL COMMENT 'Set when a later amendment takes effect',
  `recorded_at`                      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by_admin_user_id`        int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → admin_users.id',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fid_instrument_version` (`instrument_key`, `version_label`),
  KEY `idx_fid_instrument_current` (`instrument_key`, `is_current`),
  KEY `idx_fid_amends` (`amends_id`),
  KEY `idx_fid_sha256` (`sha256_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Version history of all founding instrument PDFs. One row per version. Amendments link back to the version they supersede.';

-- ------------------------------------------------------------
-- 2. evidence_vault_entries — add founding_instrument_document
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
    'witness_attestation',
    'founding_instrument_document'
  ) NOT NULL COMMENT 'asx_trade_document = SHA-256-hashed broker/CHESS PDF anchored in the evidence vault';

-- ============================================================
-- Verify after running:
-- SHOW COLUMNS FROM founding_instrument_documents LIKE 'id';
-- Expected: 1 row
-- SHOW COLUMNS FROM evidence_vault_entries LIKE 'entry_type';
-- Expected: enum includes founding_instrument_document
-- SELECT COUNT(*) FROM founding_instrument_documents;
-- Expected: 0 (seeded via admin page)
-- ============================================================

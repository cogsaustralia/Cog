-- ============================================================
-- Migration: 2026_04_21_trustee_counterpart_records
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL first, then PHP, then HTML/JS
-- ============================================================

-- ------------------------------------------------------------
-- 1. one_time_tokens
--    Stores hashed single-use tokens for privileged flows.
--    The raw token is never stored — only its SHA-256 hash.
-- ------------------------------------------------------------
CREATE TABLE `one_time_tokens` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash`  char(64)         NOT NULL COMMENT 'SHA-256 of the raw token — raw token never stored',
  `purpose`     varchar(60)      NOT NULL COMMENT 'e.g. trustee_acceptance',
  `used_at`     datetime         DEFAULT NULL COMMENT 'Set on first successful validation; NULL = unused',
  `expires_at`  datetime         NOT NULL,
  `created_at`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_one_time_tokens_hash_purpose` (`token_hash`, `purpose`),
  KEY `idx_one_time_tokens_purpose_used` (`purpose`, `used_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Single-use hashed tokens for privileged acceptance flows. Raw token delivered out-of-band.';

-- ------------------------------------------------------------
-- 2. trustee_counterpart_records
--    One row per Trustee acceptance event under JVPA cl.10.10A.
--    The founding Caretaker Trustee row is capacity_type =
--    founding_caretaker and superseded_at IS NULL.
--    Records are immutable after generation per cl.10.10A(f).
-- ------------------------------------------------------------
CREATE TABLE `trustee_counterpart_records` (
  `record_id`                      char(36)      NOT NULL COMMENT 'UUIDv4 — Trustee Counterpart Record identifier per cl.10.10A(c)(i)',
  `trustee_full_name`              varchar(200)  NOT NULL COMMENT 'cl.10.10A(c)(ii)',
  `declaration_appointment_ref`    varchar(500)  NOT NULL COMMENT 'cl.10.10A(c)(ii) — reference under the Declaration by which Trustee was appointed',
  `jvpa_version`                   varchar(20)   NOT NULL COMMENT 'cl.10.10A(c)(iii)',
  `jvpa_title`                     varchar(200)  NOT NULL COMMENT 'cl.10.10A(c)(iii)',
  `jvpa_execution_date`            date          NOT NULL COMMENT 'cl.10.10A(c)(iii) — date on face of JVPA version accepted',
  `jvpa_sha256`                    char(64)      NOT NULL COMMENT 'cl.10.10A(c)(iv) — SHA-256 of the canonical JVPA PDF',
  `acceptance_timestamp_utc`       datetime(3)   NOT NULL COMMENT 'cl.10.10A(c)(v) — UTC with millisecond precision',
  `ip_device_hash`                 char(64)      NOT NULL COMMENT 'cl.10.10A(c)(vi) — SHA-256 of IP + device metadata',
  `ip_device_data`                 json          NOT NULL COMMENT 'cl.10.10A(c)(vi) — underlying plaintext, off-chain, Privacy Act 1988 (Cth) restricted',
  `acceptance_flag_engaged`        tinyint(1)    NOT NULL COMMENT 'cl.10.10A(c)(vii) — must be 1; transaction fails if 0',
  `supremacy_acknowledgement_text` text          NOT NULL COMMENT 'cl.10.10A(c)(viii) — full text of supremacy acknowledgement as displayed',
  `record_sha256`                  char(64)      NOT NULL COMMENT 'SHA-256 of canonicalised full record — used for on-chain commitment',
  `onchain_commitment_txid`        varchar(200)  DEFAULT NULL COMMENT 'evidence_vault_entries.id ref (transitional) or Besu txid when live; NULL only until anchoring succeeds',
  `capacity_type`                  enum('founding_caretaker','successor_trustee','successor_caretaker_trustee') NOT NULL DEFAULT 'founding_caretaker',
  `created_at`                     datetime(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `superseded_at`                  datetime(3)   DEFAULT NULL COMMENT 'Always NULL — records cannot be amended per cl.10.10A(f)',
  PRIMARY KEY (`record_id`),
  -- Enforces only one active founding_caretaker record can exist
  UNIQUE KEY `uq_tcr_capacity_superseded` (`capacity_type`, `superseded_at`),
  KEY `idx_tcr_jvpa_sha256` (`jvpa_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trustee Counterpart Records under JVPA cl.10.10A. Immutable after generation. Retention: operational life of JV + 7 years post-Trustee cessation.';

-- ------------------------------------------------------------
-- 3. evidence_vault_entries — add trustee_counterpart_record
--    to entry_type ENUM.
--    Must list ALL existing values first.
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
    'trustee_counterpart_record'
  ) NOT NULL COMMENT 'asx_trade_document = SHA-256-hashed broker/CHESS PDF anchored in the evidence vault';

-- ------------------------------------------------------------
-- 4. jvpa_versions — insert v8, retire v5.0
--    agreement_hash = SHA-256 of /docs/COGS_JVPA.pdf
--    Effective date: 20 April 2026 (as confirmed by Thomas)
--    Title: as it appears on the face of the Agreement
-- ------------------------------------------------------------
UPDATE `jvpa_versions`
  SET `is_current` = 0,
      `superseded_at` = '2026-04-20 00:00:00'
  WHERE `id` = 1;

INSERT INTO `jvpa_versions`
  (`version_label`, `version_title`, `effective_date`, `agreement_hash`, `is_current`, `superseded_at`, `notes`, `created_at`)
VALUES (
  'v8',
  'COGS OF AUSTRALIA FOUNDATION JOINT VENTURE PARTICIPATION AGREEMENT',
  '2026-04-20',
  '7a4ffe9731ac837678b033aeb25bf7bcc2d178efdec09ac63ad957152047afe4',
  1,
  NULL,
  'v8 — SHA-256 of /docs/COGS_JVPA.pdf deployed 2026-04-21. Canonical PDF hash. Supersedes v5.0.',
  NOW()
);

-- ============================================================
-- Verify (run these SELECT statements after the above to confirm)
-- ============================================================
-- SELECT * FROM one_time_tokens LIMIT 1;                          -- table exists, empty
-- SELECT * FROM trustee_counterpart_records LIMIT 1;              -- table exists, empty
-- SELECT version_label, is_current, agreement_hash FROM jvpa_versions ORDER BY id;
--   expect: v5.0 is_current=0, v8 is_current=1, hash=7a4ffe97...
-- SHOW COLUMNS FROM evidence_vault_entries LIKE 'entry_type';
--   expect: enum includes trustee_counterpart_record

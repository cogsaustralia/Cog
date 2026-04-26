-- ============================================================
-- Migration: 2026_04_26_partner_acceptance_history
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL first, then PHP. Safe to run before PHP arrives —
--   the application gracefully no-ops on missing table (try/catch).
-- Prerequisite: none.
--
-- Issue
--   partner_entry_records is unique on partner_id, so re-acceptance
--   from inside the wallet (vault.php:acceptJvpa) overwrites the
--   original registration-time accepted_at, accepted_ip,
--   accepted_user_agent, and acceptance_record_hash. Only the latest
--   acceptance survives. For an audit chain that must show every
--   acceptance event, this is lossy.
--
-- Fix
--   Append-only history table, one row per acceptance event. The
--   canonical "current" view stays in partner_entry_records; this
--   table is the immutable trail.
--
--   Source values:
--     'registration'         — JvpaAcceptanceService::record at intake
--     'wallet_reaffirmation' — vault.php:acceptJvpa from inside wallet
--     'admin_correction'     — manual fix-up by admin (reserved)
--
-- Verification (after running)
--   SHOW CREATE TABLE partner_acceptance_history;
--
--   -- After application code lands and one acceptance occurs:
--   SELECT id, partner_id, source, accepted_version,
--          SUBSTRING(acceptance_record_hash, 1, 16) AS hash16,
--          accepted_at
--   FROM partner_acceptance_history
--   ORDER BY id DESC LIMIT 5;
--
-- Backfill consideration (separate decision)
--   The two existing partner_entry_records rows have NULL
--   acceptance_record_hash so there's nothing meaningful to backfill.
--   Future acceptances will populate this table from this commit
--   forward.
-- ============================================================

CREATE TABLE IF NOT EXISTS `partner_acceptance_history` (
  `id`                     bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_id`             bigint(20) UNSIGNED NOT NULL,
  `partner_number`         varchar(40) NOT NULL,
  `source`                 enum('registration','wallet_reaffirmation','admin_correction') NOT NULL DEFAULT 'wallet_reaffirmation'
                           COMMENT 'Code path that recorded this acceptance event.',
  `accepted_version`       varchar(80) NOT NULL COMMENT 'jvpa_versions.version_label at time of acceptance',
  `jvpa_title`             varchar(190) DEFAULT NULL,
  `agreement_hash`         char(64) NOT NULL COMMENT 'jvpa_versions.agreement_hash at time of acceptance',
  `acceptance_record_hash` char(64) NOT NULL COMMENT 'JvpaAcceptanceService::computeHash output for this event',
  `snft_sequence_no`       int(10) UNSIGNED DEFAULT NULL,
  `accepted_at`            datetime NOT NULL,
  `accepted_ip`            varchar(45) DEFAULT NULL,
  `accepted_user_agent`    text DEFAULT NULL,
  `evidence_vault_id`      bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK evidence_vault_entries.id if a vault entry was created',
  `created_at`             datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pah_partner`        (`partner_id`),
  KEY `idx_pah_partner_number` (`partner_number`),
  KEY `idx_pah_accepted_at`    (`accepted_at`),
  KEY `idx_pah_source`         (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only audit trail of every JVPA acceptance event. partner_entry_records keeps the latest as the canonical current row; this table preserves the full sequence.';

-- ============================================================
-- Migration: Unit Issuance Register & Unitholder Certificates
-- Date: 2026-04-25
-- Governing instruments: CJVM Hybrid Trust Declaration,
--   Sub-Trust A Deed (cls 7, 11, 13), JVPA cl 3.2
-- ============================================================

-- ------------------------------------------------------------
-- Table: unit_issuance_register
-- Legal record of each unit issued per Sub-Trust A cl.11.
-- One row per issuance event per member per unit class.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `unit_issuance_register` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `register_ref`          VARCHAR(40)  NOT NULL UNIQUE
                          COMMENT 'UIR-{CLASS}-{NNNNNN} e.g. UIR-S-000001',
  `member_id`             INT UNSIGNED NOT NULL
                          COMMENT 'FK → members.id',
  `unit_class_code`       VARCHAR(16)  NOT NULL
                          COMMENT 'S | B | kS | C | P | D | Lr | A | Lh | BP | R',
  `unit_class_name`       VARCHAR(100) NOT NULL
                          COMMENT 'Human-readable class name at time of issue',
  `cert_type`             ENUM('financial','community','governance_allocation') NOT NULL DEFAULT 'financial'
                          COMMENT 'financial=Beneficial Unit classes; community=Class C; governance_allocation=Class Lr',
  `units_issued`          DECIMAL(18,4) NOT NULL
                          COMMENT 'Quantity of units issued in this event',
  `consideration_cents`   INT          NOT NULL DEFAULT 0
                          COMMENT 'Fiat consideration in cents (0 for Class C standing allocation and Class Lr)',
  `issue_date`            DATE         NOT NULL
                          COMMENT 'Date units lawfully issued',
  `issue_trigger`         ENUM(
                            'trustee_manual',
                            'standing_poll_cl23D3',
                            'members_poll',
                            'auto_zone_allocation',
                            'trustee_resolution_rwa'
                          ) NOT NULL DEFAULT 'trustee_manual'
                          COMMENT 'Basis for issuance per Declaration',
  `gate`                  TINYINT UNSIGNED NOT NULL DEFAULT 1
                          COMMENT 'Issuance gate: 1=Gate1 (Declaration executed), 2=Gate2 (Foundation Day), 3=Gate3 (Expansion Day)',
  `kyc_verified`          TINYINT(1)   NOT NULL DEFAULT 0
                          COMMENT '1 = KYC/AML-CTF clearance recorded before issue',
  `payment_cleared`       TINYINT(1)   NOT NULL DEFAULT 0
                          COMMENT '1 = consideration received and cleared (or 0 for no-fee classes)',
  `anti_cap_checked`      TINYINT(1)   NOT NULL DEFAULT 0
                          COMMENT '1 = anti-capture cap verified < 1,000,000 before issue',
  `gate_satisfied`        TINYINT(1)   NOT NULL DEFAULT 0
                          COMMENT '1 = applicable issuance gate confirmed satisfied',
  `sha256_hash`           VARCHAR(64)  DEFAULT NULL
                          COMMENT 'SHA-256 of register_ref|member_id|unit_class_code|units_issued|issue_date|consideration_cents',
  `issued_by_admin_id`    INT UNSIGNED DEFAULT NULL
                          COMMENT 'Admin user who triggered issuance',
  `notes`                 TEXT         DEFAULT NULL,
  `certificate_sent_at`   DATETIME     DEFAULT NULL
                          COMMENT 'Timestamp certificate email was queued',
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_register_ref` (`register_ref`),
  KEY `idx_member_class` (`member_id`, `unit_class_code`),
  KEY `idx_issue_date`   (`issue_date`),
  KEY `idx_unit_class`   (`unit_class_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Formal legal register of all issued units per Sub-Trust A cl.11';

-- ------------------------------------------------------------
-- Table: unitholder_certificates
-- Certificate dispatch record per issuance event.
-- One row per certificate per issuance_id.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `unitholder_certificates` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cert_ref`          VARCHAR(40)  NOT NULL UNIQUE
                      COMMENT 'CERT-{CLASS}-{NNNNNN} e.g. CERT-S-000001',
  `issuance_id`       INT UNSIGNED NOT NULL
                      COMMENT 'FK → unit_issuance_register.id',
  `member_id`         INT UNSIGNED NOT NULL
                      COMMENT 'FK → members.id',
  `unit_class_code`   VARCHAR(16)  NOT NULL
                      COMMENT 'Mirrors unit_issuance_register.unit_class_code',
  `cert_type`         ENUM('financial','community','governance_allocation') NOT NULL DEFAULT 'financial'
                      COMMENT 'Controls which certificate template is rendered',
  `units`             DECIMAL(18,4) NOT NULL,
  `issue_date`        DATE         NOT NULL,
  `email_sent_to`     VARCHAR(255) DEFAULT NULL,
  `email_sent_at`     DATETIME     DEFAULT NULL,
  `email_queue_id`    BIGINT UNSIGNED DEFAULT NULL
                      COMMENT 'FK → email_queue.id',
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cert_ref`     (`cert_ref`),
  UNIQUE KEY `uq_issuance`     (`issuance_id`),
  KEY `idx_member_cert`        (`member_id`),
  KEY `idx_unit_class_cert`    (`unit_class_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Certificate dispatch record per unit issuance event';

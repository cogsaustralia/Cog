-- =============================================================================
-- COG$ of Australia Foundation — CRC Project Documents (Phase 3)
-- Adds hub_project_documents table and inserts CRC master + programme docs.
--
-- Each CRC project gets:
--   · One 'master' row linking to the CRC Master Strategy PDF
--   · One 'programme' row linking to the project-specific planning doc PDF
--
-- Access control is handled at the application layer:
--   vault/hub-document-access POST — commitment acknowledgement gate
--   vault/hub-document-serve  GET  — auth-gated PDF file delivery
--
-- Deploy order: SQL (this file) → PHP → JS/CSS/HTML
-- Run via phpMyAdmin against cogsaust_TRUST. Idempotent.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) hub_project_documents — planning document registry per project
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hub_project_documents` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`    bigint(20) UNSIGNED NOT NULL
                  COMMENT 'FK → hub_projects.id',
  `doc_type`      enum('master','programme') NOT NULL DEFAULT 'programme'
                  COMMENT 'master = CRC umbrella doc; programme = project-specific doc',
  `title`         varchar(255) NOT NULL,
  `version_label` varchar(30)  NOT NULL DEFAULT 'v1.0',
  `description`   text         DEFAULT NULL
                  COMMENT 'Short plain-language description shown to Member before access',
  `pdf_filename`  varchar(200) NOT NULL
                  COMMENT 'Filename under /docs/crc/ on the server',
  `sha256_hash`   char(64)     NOT NULL
                  COMMENT 'SHA-256 of the PDF — integrity anchor',
  `file_size_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `sort_order`    tinyint(3) UNSIGNED NOT NULL DEFAULT 0
                  COMMENT '0 = master first, 1 = programme second',
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp()
                  ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hpd_project` (`project_id`),
  KEY `idx_hpd_type`    (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Planning and strategy PDFs linked to hub projects. Auth-gated with commitment acknowledgement.';

-- -----------------------------------------------------------------------------
-- 2) hub_project_document_access — commitment acknowledgement log
--    Records each Member's act of commitment before a document is served.
--    One row per member per document — INSERT IGNORE means second access
--    skips the commitment gate and serves directly.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hub_project_document_access` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` int(10) UNSIGNED    NOT NULL
                COMMENT 'FK → hub_project_documents.id',
  `member_id`   int(10) UNSIGNED    NOT NULL
                COMMENT 'FK → members.id',
  `acknowledged_at` datetime NOT NULL DEFAULT current_timestamp()
                COMMENT 'Timestamp of commitment acknowledgement',
  `ip_addr`     varchar(45) DEFAULT NULL
                COMMENT 'Client IP at time of acknowledgement',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_member` (`document_id`, `member_id`),
  KEY `idx_hpda_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Records Member commitment acknowledgement before CRC document access. One row per member per document.';

-- -----------------------------------------------------------------------------
-- 3) Insert document rows
--    project_id 2 = Corridors Communications
--    project_id 3 = Corridors Energy: Electricity
--    project_id 4 = Corridors Energy: LPG
--    project_id 5 = Corridors Transport and Freight
--
--    Master PDF:  COGS_Resource_Corridors_Master_Strategy_v1_0.pdf
--    Comms PDF:   Corridors_Communications_Planning_Strategy_v1_1.pdf
--    Elec PDF:    Corridors_Energy_Electricity_Planning_Strategy_v1_1.pdf
--    LPG PDF:     Corridors_Energy_LPG_Planning_Strategy_v1_1.pdf
--    Transport PDF: Corridors_Transport_and_Freight_Planning_Strategy_v1_1.pdf
-- -----------------------------------------------------------------------------

-- Corridors Communications — master + programme
INSERT IGNORE INTO `hub_project_documents`
  (`project_id`,`doc_type`,`title`,`version_label`,`description`,
   `pdf_filename`,`sha256_hash`,`file_size_bytes`,`sort_order`)
VALUES
(2, 'master', 'COGS Resource Corridors Master Strategy and Governance', 'v1.0',
 'The umbrella strategy and governance document for all Foundation Member-benefit programmes. Defines the structural relationship between all CRC programmes, the common cryptographic substrate they share, and the three branches (Communications, Energy, Transport and Freight). This document governs cross-programme integration and applies to all four CRC projects.',
 'COGS_Resource_Corridors_Master_Strategy_v1_0.pdf',
 'a426eb82f8627735b01b5c9c5b627c086234668bf191f1ade98fe3b1e0999985',
 293257, 0),
(2, 'programme', 'Corridors Communications Planning and Strategy', 'v1.1',
 'The planning and strategy document for Corridors Communications — the Foundation\'s first CRC programme. Covers mobile (Telstra and Optus MVNO), fixed-line NBN, business wholesale data, and VoIP voice delivered through Partner Wholesale Networks (PWN). Supersedes the mobile-only Community Mobile draft.',
 'Corridors_Communications_Planning_Strategy_v1_1.pdf',
 '131e7070dbf7a2e1db301fdad3e70dcb8b6fd695fbda356128dc034d389c41d3',
 243663, 1);

-- Corridors Energy: Electricity — master + programme
INSERT IGNORE INTO `hub_project_documents`
  (`project_id`,`doc_type`,`title`,`version_label`,`description`,
   `pdf_filename`,`sha256_hash`,`file_size_bytes`,`sort_order`)
VALUES
(3, 'master', 'COGS Resource Corridors Master Strategy and Governance', 'v1.0',
 'The umbrella strategy and governance document for all Foundation Member-benefit programmes. Defines the structural relationship between all CRC programmes, the common cryptographic substrate they share, and the three branches (Communications, Energy, Transport and Freight). This document governs cross-programme integration and applies to all four CRC projects.',
 'COGS_Resource_Corridors_Master_Strategy_v1_0.pdf',
 'a426eb82f8627735b01b5c9c5b627c086234668bf191f1ade98fe3b1e0999985',
 293257, 0),
(3, 'programme', 'Corridors Energy: Electricity Planning and Strategy', 'v1.1',
 'The planning and strategy document for Corridors Energy: Electricity. Covers the Foundation\'s electricity Member-benefit programme delivered through Localvolts Pty Ltd (AER-authorised retailer). Includes the three-tier structure, 15% COG$ services fee mechanics, Sub-Trust C Community Supported tier, Localvolts AI optimisation layer, and geographic phasing from Jubullum/Drake/Tabulam through NSW to national NEM coverage.',
 'Corridors_Energy_Electricity_Planning_Strategy_v1_1.pdf',
 'aadfaa4f369de4dccfaf299a3b1ef3ad325e9bb3ef621b7a8ce670c2983242f8',
 458837, 1);

-- Corridors Energy: LPG — master + programme
INSERT IGNORE INTO `hub_project_documents`
  (`project_id`,`doc_type`,`title`,`version_label`,`description`,
   `pdf_filename`,`sha256_hash`,`file_size_bytes`,`sort_order`)
VALUES
(4, 'master', 'COGS Resource Corridors Master Strategy and Governance', 'v1.0',
 'The umbrella strategy and governance document for all Foundation Member-benefit programmes. Defines the structural relationship between all CRC programmes, the common cryptographic substrate they share, and the three branches (Communications, Energy, Transport and Freight). This document governs cross-programme integration and applies to all four CRC projects.',
 'COGS_Resource_Corridors_Master_Strategy_v1_0.pdf',
 'a426eb82f8627735b01b5c9c5b627c086234668bf191f1ade98fe3b1e0999985',
 293257, 0),
(4, 'programme', 'Corridors Energy: LPG Planning and Strategy', 'v1.1',
 'The planning and strategy document for Corridors Energy: LPG. Covers the Foundation\'s LPG Member-benefit programme delivered through a wholesale account with Supagas Pty Ltd. Includes the wholesale-account resupply model, three-tier structure, 15% COG$ services fee, Sub-Trust C Community Supported tier, and operational integration with Corridors Transport and Freight for last-mile cylinder delivery.',
 'Corridors_Energy_LPG_Planning_Strategy_v1_1.pdf',
 '46dce53119320c55eee4d548d56f11e01dd2996d4752a35aead7efdc68090d41',
 208922, 1);

-- Corridors Transport and Freight — master + programme
INSERT IGNORE INTO `hub_project_documents`
  (`project_id`,`doc_type`,`title`,`version_label`,`description`,
   `pdf_filename`,`sha256_hash`,`file_size_bytes`,`sort_order`)
VALUES
(5, 'master', 'COGS Resource Corridors Master Strategy and Governance', 'v1.0',
 'The umbrella strategy and governance document for all Foundation Member-benefit programmes. Defines the structural relationship between all CRC programmes, the common cryptographic substrate they share, and the three branches (Communications, Energy, Transport and Freight). This document governs cross-programme integration and applies to all four CRC projects.',
 'COGS_Resource_Corridors_Master_Strategy_v1_0.pdf',
 'a426eb82f8627735b01b5c9c5b627c086234668bf191f1ade98fe3b1e0999985',
 293257, 0),
(5, 'programme', 'Corridors Transport and Freight Planning and Strategy', 'v1.1',
 'The planning and strategy document for Corridors Transport and Freight. Covers Corridors Rides (rideshare-style personal transport) and Corridors Freight (heavy-load freight) under one platform. Includes the Foundation\'s BSP authorisation posture, HVNL Chain of Responsibility, COG$ and Sub-Trust C overlay, and the integrated last-mile LPG delivery coordination role.',
 'Corridors_Transport_and_Freight_Planning_Strategy_v1_1.pdf',
 '8343d5847c4496d167150381c163fa1f640a57c1b379b8f22c3a8e126d7ba8a9',
 386142, 1);

-- =============================================================================
-- Verification queries (run after migration to confirm)
-- =============================================================================
-- SELECT d.id, d.project_id, hp.title AS project_title,
--        d.doc_type, d.title, d.version_label, d.pdf_filename,
--        d.file_size_bytes, d.sort_order
--   FROM hub_project_documents d
--   JOIN hub_projects hp ON hp.id = d.project_id
--  ORDER BY d.project_id, d.sort_order;
-- Expected: 8 rows — 2 per project (master + programme), projects 2-5.
--
-- SELECT COUNT(*) FROM hub_project_document_access;
-- Expected: 0 (empty until Members start accessing docs).

-- End of phase3_crc_project_documents_v1.sql

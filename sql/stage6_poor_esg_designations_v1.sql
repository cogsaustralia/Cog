-- =============================================================================
-- COG$ of Australia Foundation — Stage 6: Poor ESG Target Designation Schema
-- Migration: sql/stage6_poor_esg_designations_v1.sql
-- Issued: May 2026
-- Target DB: cogsaust_TRUST
-- Deploy order: SQL (this file first) → PHP → HTML/JS
-- Present to Thomas for review. Thomas confirms run via phpMyAdmin BEFORE
-- any PHP referencing these tables is deployed.
-- =============================================================================

SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Poor ESG Target designations (master record per company)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `poor_esg_target_designations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name` VARCHAR(200) NOT NULL,
  `asx_code` VARCHAR(10) NOT NULL,
  `designation_status` ENUM(
    'pending_consultation',
    'fnac_review',
    'board_identified',
    'acquisition_ready',
    'active_engagement'
  ) NOT NULL DEFAULT 'pending_consultation',
  `strategy_version` VARCHAR(20) NOT NULL DEFAULT 'v1.1',
  `strategy_issued_date` DATE NOT NULL,
  `first_nations_engagement_summary` TEXT DEFAULT NULL,
  `esg_rationale_summary` TEXT DEFAULT NULL,
  `public_display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asx_code` (`asx_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Poor ESG Target designations under JVPA clause 30.3';

-- ---------------------------------------------------------------------------
-- 2. Milestones per designation (seven states per FNAC pathway)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `designation_milestones` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `designation_id` INT UNSIGNED NOT NULL,
  `milestone_key` VARCHAR(80) NOT NULL,
  `milestone_label` VARCHAR(200) NOT NULL,
  `milestone_status` ENUM('pending','in_progress','complete','blocked') NOT NULL DEFAULT 'pending',
  `completed_date` DATE DEFAULT NULL,
  `status_note` TEXT DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_designation_milestone` (`designation_id`, `milestone_key`),
  CONSTRAINT `fk_dm_designation`
    FOREIGN KEY (`designation_id`) REFERENCES `poor_esg_target_designations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Seven-state FNAC pathway milestones per Poor ESG Target designation';

-- ---------------------------------------------------------------------------
-- 3. ESG improvement objectives per designation
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `esg_improvement_objectives` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `designation_id` INT UNSIGNED NOT NULL,
  `objective_category` ENUM(
    'first_nations',
    'taxation',
    'emissions',
    'retail_pricing',
    'director_accountability'
  ) NOT NULL,
  `objective_text` TEXT NOT NULL,
  `target_agm_year` INT DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_eio_designation`
    FOREIGN KEY (`designation_id`) REFERENCES `poor_esg_target_designations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Public ESG improvement objectives per designation (Strategy Part 7)';

-- ---------------------------------------------------------------------------
-- 4. Lobbyist response register (pre-published attack–countermeasure pairs)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lobbyist_response_register` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attack_category` ENUM(
    'mis_claim',
    'activist_fund',
    'foreign_funded',
    'shareholder_value',
    'political_project',
    'anti_jobs',
    'technical_validity',
    'media_silence',
    'media_diversion',
    'advertising_attack',
    'regulatory_complaint',
    'personal_attack',
    'first_nations_consent'
  ) NOT NULL,
  `anticipated_attack_text` TEXT NOT NULL,
  `structural_countermeasure_text` TEXT NOT NULL,
  `status` ENUM('anticipated','observed','responded') NOT NULL DEFAULT 'anticipated',
  `observed_date` DATETIME DEFAULT NULL,
  `observed_source` VARCHAR(500) DEFAULT NULL,
  `response_url` VARCHAR(500) DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_first_nations_specific` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pre-published attack-response register from Strategy Part 11';

-- ---------------------------------------------------------------------------
-- 5. Reform history episodes (Fifty Years page — Strategy Part 3 / Script §5.3)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reform_history_episodes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `episode_year` INT NOT NULL,
  `episode_label` VARCHAR(200) NOT NULL,
  `prime_minister_or_premier` VARCHAR(100) NOT NULL,
  `party` VARCHAR(50) NOT NULL,
  `what_was_attempted` TEXT NOT NULL,
  `how_defeated` TEXT NOT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Five-decade reform history episodes for the Fifty Years page';

-- ---------------------------------------------------------------------------
-- 6. Extend member_voice_submissions for designation canvass
--    Note: member_voice_submissions confirmed in __65_ dump — no canvass columns present.
--    ADD COLUMN IF NOT EXISTS is MariaDB 10.6 compatible.
-- ---------------------------------------------------------------------------
ALTER TABLE `member_voice_submissions`
  ADD COLUMN IF NOT EXISTS `canvass_designation_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'FK poor_esg_target_designations.id — set when submission is a canvass response',
  ADD COLUMN IF NOT EXISTS `canvass_sentiment`
    ENUM('support','support_with_concerns','oppose','no_view') DEFAULT NULL
    COMMENT 'Member canvass sentiment on this Poor ESG Target designation';

ALTER TABLE `member_voice_submissions`
  ADD KEY IF NOT EXISTS `idx_canvass_designation` (`canvass_designation_id`);

-- ---------------------------------------------------------------------------
-- 7. Seed: two Poor ESG Target designations
-- ---------------------------------------------------------------------------
INSERT INTO `poor_esg_target_designations`
  (`company_name`, `asx_code`, `designation_status`, `strategy_version`,
   `strategy_issued_date`, `public_display_order`)
VALUES
  ('Santos Limited',        'STO', 'pending_consultation', 'v1.1', '2026-04-30', 1),
  ('Origin Energy Limited', 'ORG', 'pending_consultation', 'v1.1', '2026-04-30', 2)
ON DUPLICATE KEY UPDATE
  `strategy_version` = VALUES(`strategy_version`),
  `updated_at` = CURRENT_TIMESTAMP;

-- ---------------------------------------------------------------------------
-- 8. Seed: seven milestones per designation
--    Santos id=1, Origin id=2 (auto-increment from clean table — verify IDs after run)
--    milestone_key values are canonical — used by PHP and HTML to map status display
-- ---------------------------------------------------------------------------
INSERT INTO `designation_milestones`
  (`designation_id`, `milestone_key`, `milestone_label`, `milestone_status`, `display_order`)
VALUES
  -- Santos
  (1, 'strategy_issued',          'Strategy issued',                          'complete',  1),
  (1, 'jlalc_consultation',       'JLALC consultation',                       'in_progress', 2),
  (1, 'fnac_formation',           'FNAC formation',                           'pending',   3),
  (1, 'fnac_review',              'FNAC review',                              'pending',   4),
  (1, 'board_identified',         'Board member identified for nomination',    'pending',   5),
  (1, 'first_acquisition_mandate','First acquisition mandate issued',          'pending',   6),
  (1, 'first_agm_attendance',     'First AGM attendance',                     'pending',   7),
  -- Origin
  (2, 'strategy_issued',          'Strategy issued',                          'complete',  1),
  (2, 'jlalc_consultation',       'JLALC consultation',                       'in_progress', 2),
  (2, 'fnac_formation',           'FNAC formation',                           'pending',   3),
  (2, 'fnac_review',              'FNAC review',                              'pending',   4),
  (2, 'board_identified',         'Board member identified for nomination',    'pending',   5),
  (2, 'first_acquisition_mandate','First acquisition mandate issued',          'pending',   6),
  (2, 'first_agm_attendance',     'First AGM attendance',                     'pending',   7)
ON DUPLICATE KEY UPDATE
  `milestone_label` = VALUES(`milestone_label`),
  `updated_at` = CURRENT_TIMESTAMP;

-- ---------------------------------------------------------------------------
-- 9. Seed: reform_history_episodes
--    Six canonical episodes — text drawn from Strategy Part 3 / Script §5.3
--    NEEDS DOCX: Replace placeholder text below with verbatim docx content
--    once the three v1.1 .docx files are attached to the session.
-- ---------------------------------------------------------------------------
INSERT INTO `reform_history_episodes`
  (`episode_year`, `episode_label`, `prime_minister_or_premier`, `party`,
   `what_was_attempted`, `how_defeated`, `display_order`)
VALUES
  (1970, 'Australian Industry Development Corporation (AIDC)',
   'John Gorton', 'Liberal',
   -- NEEDS DOCX: Strategy Part 3 / Script §5.3 verbatim content
   'Federal government financing vehicle intended to support Australian resource development with domestic capital rather than foreign equity.',
   'Gutted by cabinet opposition and industry lobbying before any meaningful resource-revenue mandate could be established.',
   1),
  (1973, 'Petroleum and Minerals Authority (PMA)',
   'Gough Whitlam', 'Labor',
   -- NEEDS DOCX
   'State-owned authority to acquire equity in Australian petroleum and mineral operations, redirecting resource rents to public benefit.',
   'Senate blocked the enabling legislation three times; dissolved in the constitutional crisis of 1975.',
   2),
  (1987, 'Petroleum Resource Rent Tax (PRRT)',
   'Bob Hawke', 'Labor',
   -- NEEDS DOCX
   'Profits-based tax on offshore petroleum designed to capture a share of resource rents for the Commonwealth.',
   'Industry successfully narrowed application to offshore only; onshore resources remained outside scope; effective rate steadily reduced through deduction regimes.',
   3),
  (2010, 'Resource Super Profits Tax (RSPT)',
   'Kevin Rudd', 'Labor',
   -- NEEDS DOCX
   '40% tax on resource super-profits above a risk-free rate of return, projected to raise $12 billion over two years.',
   'Mining industry spent over $22 million in six weeks on an advertising campaign; Rudd replaced as Prime Minister before legislation reached parliament.',
   4),
  (2024, 'Queensland Resources Revenue Fund',
   'Steven Miles', 'Labor',
   -- NEEDS DOCX
   'Queensland state fund to capture a share of coal royalty windfalls for community benefit and cost-of-living relief.',
   'Defeated at the October 2024 Queensland state election; LNP government rescinded royalty increases.',
   5),
  (2026, 'Senate Inquiry into Community Benefits from Resource Extraction',
   'Senate Standing Committee', 'Cross-bench',
   -- NEEDS DOCX
   'Current federal Senate inquiry into whether Australian communities receive adequate benefit from domestic resource extraction.',
   'Ongoing — outcome pending as at May 2026.',
   6);

-- ---------------------------------------------------------------------------
-- 10. Seed: lobbyist_response_register
--     12 general rows (Strategy Part 11 §30) + 5 FN-specific (FNAC Part 9 §24)
--     NEEDS DOCX: Replace placeholder text with verbatim docx content.
--     Structure only seeded here — full text requires the .docx attachments.
-- ---------------------------------------------------------------------------

-- General attack vectors (Strategy Part 11 §30) — NEEDS DOCX for full text
INSERT INTO `lobbyist_response_register`
  (`attack_category`, `anticipated_attack_text`, `structural_countermeasure_text`,
   `display_order`, `is_first_nations_specific`)
VALUES
  ('mis_claim',
   'COGS is an unregistered managed investment scheme and operates illegally.',
   'The Foundation is a community joint venture partnership under a JVPA — not a MIS. Three independent MIS defence pillars are entrenched in the document stack. ASIC engagement is active.',
   1, 0),
  ('activist_fund',
   'COGS is an activist investment fund masquerading as a community organisation.',
   'The Foundation holds shares on the CHESS register on behalf of members under a deed of trust. AGM attendance and voting rights derive from registered shareholding — identical to any retail shareholder.',
   2, 0),
  ('foreign_funded',
   'COGS is funded by foreign interests or aligned with overseas ESG agendas.',
   'The Foundation holds only ASX-listed Australian resource company shares. All members are Australian residents. Founding governance partners are First Nations Australian communities.',
   3, 0),
  ('shareholder_value',
   'COGS will destroy shareholder value and harm Australian superannuation holders.',
   'The Foundation seeks ESG improvement objectives that are aligned with long-term sustainable value. Improved governance benefits all shareholders including super funds.',
   4, 0),
  ('political_project',
   'COGS is a front for a political party or parliamentary faction.',
   'The Foundation holds no party affiliation. No election can revoke its instrument. No MP, senator, or party official holds any governance role.',
   5, 0),
  ('anti_jobs',
   'COGS will cost Australian jobs in the resources sector.',
   'The Foundation does not seek to shut down operations. ESG improvement objectives address governance, taxation transparency, First Nations benefit-sharing, and emissions planning — not operational shutdown.',
   6, 0),
  ('technical_validity',
   'The Foundation\'s legal structure is untested and will fail regulatory scrutiny.',
   'The document stack has been prepared with legal counsel. ASIC engagement on MIS exclusion is active. The Foundation publishes its legal instruments for public inspection.',
   7, 0),
  ('media_silence',
   'Mainstream media blackout — ignore COGS; it will not gain traction.',
   'The Foundation\'s recruitment is direct-to-community via the Fair Say Relay. Media coverage is not a dependency. Member growth is the metric.',
   8, 0),
  ('media_diversion',
   'Redirect media coverage to company social licence achievements to crowd out COGS narrative.',
   'The Foundation publishes this register before the campaign runs. Members can see the playbook. Structural countermeasures are pre-positioned.',
   9, 0),
  ('advertising_attack',
   'Run targeted advertising to undermine COGS credibility with potential members.',
   'No advertising can override a CHESS-registered shareholding. The Foundation\'s instrument is structural — not dependent on narrative dominance.',
   10, 0),
  ('regulatory_complaint',
   'File complaints with ASIC, ACCC, or ATO to tie up the Foundation in process.',
   'Regulatory engagement is anticipated. The Foundation maintains a compliance posture. ASIC engagement on MIS exclusion is proactive, not reactive.',
   11, 0),
  ('personal_attack',
   'Target the Caretaker Trustee personally to destabilise governance.',
   'The Foundation\'s governance is structural, not personal. The JVPA, Trust Declaration, and Sub-Trust Deeds operate independently of any individual. Succession is provided for.',
   12, 0);

-- First Nations-specific vectors (FNAC Part 9 §24) — NEEDS DOCX for full text
INSERT INTO `lobbyist_response_register`
  (`attack_category`, `anticipated_attack_text`, `structural_countermeasure_text`,
   `display_order`, `is_first_nations_specific`)
VALUES
  ('first_nations_consent',
   'COGS does not have genuine First Nations consent — it is tokenistic engagement.',
   -- NEEDS DOCX: FNAC Part 9 §24 verbatim countermeasure
   'The FNAC framework requires written consultation with the nominating LALC before any designation is confirmed. Governance partner status is conferred by deed, not by press release.',
   13, 1),
  ('first_nations_consent',
   'The Foundation\'s First Nations governance structure duplicates existing land council functions.',
   -- NEEDS DOCX
   'The FNAC is constituted under the JVPA as a designation review body — not a land rights body, not a cultural authority, and not a replacement for LALC governance.',
   14, 1),
  ('first_nations_consent',
   'First Nations communities have not been adequately consulted on the Poor ESG Target framework.',
   -- NEEDS DOCX
   'Clause 30.3 of the JVPA requires FNAC review before any acquisition mandate is issued. Consultation is a constitutional precondition, not an afterthought.',
   15, 1),
  ('first_nations_consent',
   'COGS exploits First Nations branding for legitimacy without delivering material benefit.',
   -- NEEDS DOCX
   'Sub-Trust C is constituted exclusively for First Nations benefit. Governance partner status confers real decision-making power via FNAC nomination rights.',
   16, 1),
  ('first_nations_consent',
   'The Foundation\'s in-ground resource thesis appropriates First Nations custodianship language.',
   -- NEEDS DOCX
   'The Foundation acknowledges that in-ground minerals retain real economic value as stewardship assets. This thesis is developed in partnership with First Nations governance partners, not in substitution for their authority.',
   17, 1);

-- ---------------------------------------------------------------------------
-- 11. Seed: esg_improvement_objectives
--     Drawn from Strategy Part 7 — NEEDS DOCX for full objective text.
--     Partial seeding: category structure only. Full text to be added after
--     the three v1.1 .docx files are attached to the working session.
-- ---------------------------------------------------------------------------

-- Santos (designation_id = 1) — NEEDS DOCX for objective_text
INSERT INTO `esg_improvement_objectives`
  (`designation_id`, `objective_category`, `objective_text`, `target_agm_year`, `display_order`)
VALUES
  (1, 'first_nations',         '-- NEEDS DOCX: Strategy Part 7 Santos objective 1', 2026, 1),
  (1, 'taxation',              '-- NEEDS DOCX: Strategy Part 7 Santos objective 2', 2026, 2),
  (1, 'emissions',             '-- NEEDS DOCX: Strategy Part 7 Santos objective 3', 2026, 3),
  (1, 'retail_pricing',        '-- NEEDS DOCX: Strategy Part 7 Santos objective 4', 2026, 4),
  (1, 'director_accountability','-- NEEDS DOCX: Strategy Part 7 Santos objective 5', 2026, 5);

-- Origin (designation_id = 2) — NEEDS DOCX for objective_text
INSERT INTO `esg_improvement_objectives`
  (`designation_id`, `objective_category`, `objective_text`, `target_agm_year`, `display_order`)
VALUES
  (2, 'first_nations',         '-- NEEDS DOCX: Strategy Part 7 Origin objective 1', 2026, 1),
  (2, 'taxation',              '-- NEEDS DOCX: Strategy Part 7 Origin objective 2', 2026, 2),
  (2, 'emissions',             '-- NEEDS DOCX: Strategy Part 7 Origin objective 3', 2026, 3),
  (2, 'retail_pricing',        '-- NEEDS DOCX: Strategy Part 7 Origin objective 4', 2026, 4),
  (2, 'director_accountability','-- NEEDS DOCX: Strategy Part 7 Origin objective 5', 2026, 5);

COMMIT;

-- =============================================================================
-- POST-RUN VERIFICATION QUERIES (run after COMMIT to confirm)
-- =============================================================================
-- SELECT id, asx_code, designation_status FROM poor_esg_target_designations;
-- SELECT designation_id, milestone_key, milestone_status FROM designation_milestones ORDER BY designation_id, display_order;
-- SELECT COUNT(*) FROM lobbyist_response_register;
-- SELECT COUNT(*) FROM reform_history_episodes;
-- SHOW COLUMNS FROM member_voice_submissions LIKE 'canvass%';
-- =============================================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `address_lr_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gnaf_pid` varchar(40) NOT NULL COMMENT 'G-NAF Persistent Identifier — authoritative address anchor for this token',
  `gnaf_address` varchar(500) NOT NULL COMMENT 'Normalised address string from G-NAF (denormalised for display)',
  `zone_id` int(10) unsigned DEFAULT NULL COMMENT 'FK → affected_zones.id',
  `zone_code` varchar(40) DEFAULT NULL COMMENT 'Denormalised zone code for fast lookup',
  `units_issued` int(11) NOT NULL DEFAULT 0 COMMENT 'LR COG$ units bound to this address',
  `current_member_id` int(10) unsigned DEFAULT NULL COMMENT 'FK → snft_memberships.id — current verified resident (NULL = unoccupied)',
  `current_member_number` varchar(32) DEFAULT NULL COMMENT 'Denormalised member number of current resident',
  `resident_since` datetime DEFAULT NULL COMMENT 'When current member verified residency at this address',
  `previous_member_id` int(10) unsigned DEFAULT NULL COMMENT 'FK → snft_memberships.id — previous resident (for audit trail)',
  `previous_member_number` varchar(32) DEFAULT NULL COMMENT 'Denormalised member number of previous resident',
  `previous_resident_until` datetime DEFAULT NULL COMMENT 'When previous residency ended',
  `address_verification_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → address_verifications.id — verification event establishing current resident',
  `status` enum('active','unoccupied','disputed','suspended') NOT NULL DEFAULT 'unoccupied' COMMENT 'active = current verified resident; unoccupied = address has no current verified resident',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_address_lr_gnaf_pid` (`gnaf_pid`),
  KEY `idx_address_lr_current_member` (`current_member_id`),
  KEY `idx_address_lr_zone` (`zone_id`),
  KEY `idx_address_lr_status` (`status`),
  KEY `fk_address_lr_previous_member` (`previous_member_id`),
  KEY `fk_address_lr_verification` (`address_verification_id`),
  CONSTRAINT `fk_address_lr_current_member` FOREIGN KEY (`current_member_id`) REFERENCES `snft_memberships` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_address_lr_previous_member` FOREIGN KEY (`previous_member_id`) REFERENCES `snft_memberships` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_address_lr_verification` FOREIGN KEY (`address_verification_id`) REFERENCES `address_verifications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_address_lr_zone` FOREIGN KEY (`zone_id`) REFERENCES `affected_zones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Authoritative LR COG$ ledger. Tokens are bound to a G-NAF address, not a person. On relocation, admin reassigns current_member_id to the new verified resident. snft_memberships.lr_tokens is a cached display value synced from this table.';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `address_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(10) unsigned NOT NULL,
  `member_number` varchar(50) NOT NULL,
  `input_street` varchar(255) DEFAULT NULL,
  `input_suburb` varchar(120) DEFAULT NULL,
  `input_state` varchar(8) DEFAULT NULL,
  `input_postcode` varchar(16) DEFAULT NULL,
  `gnaf_pid` varchar(40) DEFAULT NULL COMMENT 'G-NAF Persistent Identifier',
  `gnaf_address` varchar(500) DEFAULT NULL COMMENT 'Normalised address string from G-NAF',
  `gnaf_confidence` decimal(5,2) DEFAULT NULL COMMENT 'Match confidence 0-100',
  `gnaf_match_type` varchar(40) DEFAULT NULL COMMENT 'exact, partial, fuzzy, none',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `parcel_pid` varchar(40) DEFAULT NULL COMMENT 'Cadastral parcel identifier',
  `mesh_block` varchar(20) DEFAULT NULL COMMENT 'ABS mesh block code',
  `sa1_code` varchar(20) DEFAULT NULL COMMENT 'ABS SA1 statistical area',
  `lga_code` varchar(20) DEFAULT NULL COMMENT 'Local Government Area code',
  `lga_name` varchar(120) DEFAULT NULL,
  `geocode_point` point DEFAULT NULL COMMENT 'Spatial point for zone queries (SRID 4326)',
  `zone_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to affected_zones.id if inside a zone',
  `zone_code` varchar(40) DEFAULT NULL,
  `in_affected_zone` tinyint(1) NOT NULL DEFAULT 0,
  `zone_check_method` varchar(40) DEFAULT NULL COMMENT 'spatial_query, manual_review, override',
  `nntt_overlap` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Overlaps Native Title determination area',
  `lalc_overlap` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Overlaps LALC boundary',
  `fnac_routed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flagged for FNAC consultation',
  `status` enum('pending','verified','low_confidence','disputed','failed','manual_review') NOT NULL DEFAULT 'pending',
  `verified_by` enum('system','admin','manual') DEFAULT NULL,
  `verified_by_admin_id` int(10) unsigned DEFAULT NULL,
  `verified_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `evidence_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash of full verification record',
  `chain_tx_hash` varchar(66) DEFAULT NULL COMMENT 'Besu transaction hash for attestation',
  `ledger_tx_hash` varchar(128) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_av_member` (`member_id`),
  KEY `idx_av_member_number` (`member_number`),
  KEY `idx_av_gnaf_pid` (`gnaf_pid`),
  KEY `idx_av_zone` (`zone_id`),
  KEY `idx_av_status` (`status`),
  KEY `idx_av_postcode` (`input_postcode`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_exceptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exception_key` varchar(190) NOT NULL,
  `exception_type` varchar(100) NOT NULL,
  `member_id` int(10) unsigned DEFAULT NULL,
  `token_class_id` int(10) unsigned DEFAULT NULL,
  `severity` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  `summary` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `source_name` varchar(100) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_admin_id` int(10) unsigned DEFAULT NULL,
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_exception_key` (`exception_key`),
  KEY `idx_admin_exception_status` (`status`,`severity`),
  KEY `idx_admin_exception_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `permission_key` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_role_permissions_role_perm` (`role_id`,`permission_key`),
  CONSTRAINT `fk_admin_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(64) NOT NULL,
  `display_name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system_role` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_roles_role_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(10) unsigned DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `severity` enum('info','low','medium','high','critical') NOT NULL DEFAULT 'info',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_security_events_admin_user_id` (`admin_user_id`),
  KEY `idx_admin_security_events_event_type` (`event_type`),
  CONSTRAINT `fk_admin_security_events_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(30) NOT NULL DEFAULT 'text',
  `notes` text DEFAULT NULL,
  `updated_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `value_type` varchar(32) NOT NULL DEFAULT 'string',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_settings_key` (`setting_key`),
  KEY `idx_admin_settings_type` (`setting_type`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_user_roles_user_role` (`admin_user_id`,`role_id`),
  KEY `idx_admin_user_roles_assigned_by` (`assigned_by_admin_user_id`),
  KEY `fk_admin_user_roles_role` (`role_id`),
  CONSTRAINT `fk_admin_user_roles_assigned_by` FOREIGN KEY (`assigned_by_admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_admin_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_user_roles_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `display_name` varchar(190) NOT NULL,
  `role_name` varchar(100) NOT NULL DEFAULT 'superadmin',
  `password_hash` varchar(255) NOT NULL,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_users_username` (`username`),
  UNIQUE KEY `uniq_admin_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `display_name` varchar(190) NOT NULL,
  `role` enum('super_admin','admin','viewer') NOT NULL DEFAULT 'admin',
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `affected_zones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone_code` varchar(40) NOT NULL COMMENT 'Unique zone identifier e.g. AZ-DRAKE-001',
  `zone_name` varchar(190) NOT NULL COMMENT 'Human-readable zone name',
  `zone_type` enum('project_impact','affected_zone','country_overlay','landholder_parcel','admin_context','notification') NOT NULL DEFAULT 'affected_zone',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `rationale` text DEFAULT NULL COMMENT 'Published rationale for zone declaration',
  `geometry` geometry NOT NULL COMMENT 'Zone boundary polygon in SRID 4326 (WGS84)',
  `geometry_wkt` longtext DEFAULT NULL COMMENT 'WKT backup of geometry for human inspection',
  `source_layers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of source references per spec §5 hierarchy' CHECK (json_valid(`source_layers`)),
  `fnac_consulted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = FNAC consultation completed',
  `fnac_endorsed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = FNAC endorsed this zone',
  `board_approved` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = 3-of-Board multisig approved',
  `board_signers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of admin_user IDs who signed' CHECK (json_valid(`board_signers`)),
  `effective_date` date DEFAULT NULL,
  `review_date` date DEFAULT NULL COMMENT 'Mandatory review deadline',
  `expires_at` date DEFAULT NULL COMMENT 'Zone expiry date (NULL = no expiry)',
  `status` enum('draft','proposed','active','expired','revoked') NOT NULL DEFAULT 'draft',
  `chain_tx_hash` varchar(66) DEFAULT NULL COMMENT 'Besu transaction hash for on-chain record',
  `ledger_tx_hash` varchar(128) DEFAULT NULL,
  `publication_snapshot_ref` varchar(128) DEFAULT NULL,
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_zone_version` (`zone_code`,`version`),
  KEY `idx_zone_status` (`status`),
  KEY `idx_zone_type` (`zone_type`),
  SPATIAL KEY `idx_zone_geometry` (`geometry`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` bigint(20) unsigned NOT NULL,
  `subject_type` varchar(40) NOT NULL,
  `subject_ref` varchar(120) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_announcement_read` (`announcement_id`,`subject_type`,`subject_ref`),
  KEY `idx_announcement_reads_subject` (`subject_type`,`subject_ref`),
  CONSTRAINT `fk_announcement_reads_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `audience` enum('all','snft','bnft') NOT NULL DEFAULT 'all',
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `status` enum('draft','scheduled','open','closed','archived') NOT NULL DEFAULT 'draft',
  `opens_at` datetime DEFAULT NULL,
  `closes_at` datetime DEFAULT NULL,
  `created_by` varchar(190) DEFAULT NULL,
  `updated_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_announcements_audience_id` (`audience`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_error_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `route` varchar(120) NOT NULL DEFAULT '' COMMENT 'API route e.g. vault/hub',
  `http_status` smallint(5) unsigned NOT NULL DEFAULT 500,
  `error_message` text NOT NULL,
  `area_key` varchar(60) DEFAULT NULL,
  `member_id` int(10) unsigned DEFAULT NULL,
  `request_method` varchar(10) NOT NULL DEFAULT 'GET',
  `ip_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 of IP — no raw IP stored',
  `ua_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 of user-agent',
  `acknowledged` tinyint(1) NOT NULL DEFAULT 0,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_route_status` (`route`,`http_status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_unack` (`acknowledged`),
  KEY `idx_member` (`member_id`),
  KEY `idx_area` (`area_key`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Permanent API error log — super_admin only. Alert at 10 MB.';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(10) unsigned NOT NULL,
  `token_class_id` int(10) unsigned NOT NULL,
  `request_type` enum('signup_payment','manual_approval','adjustment') NOT NULL DEFAULT 'manual_approval',
  `requested_units` int(10) unsigned NOT NULL DEFAULT 0,
  `requested_value_cents` int(10) unsigned NOT NULL DEFAULT 0,
  `request_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `reviewed_by_admin_id` int(10) unsigned DEFAULT NULL,
  `reviewed_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `compliance_status` varchar(30) NOT NULL DEFAULT 'pending',
  `mint_status` varchar(30) NOT NULL DEFAULT 'not_queued',
  `gate_notes` text DEFAULT NULL,
  `signoff_category` varchar(40) NOT NULL DEFAULT 'manual_operational_signoff',
  `signoff_status` varchar(40) NOT NULL DEFAULT 'pending_manual_signoff',
  `signoff_notes` text DEFAULT NULL,
  `signed_off_at` datetime DEFAULT NULL,
  `signed_off_by_admin_id` int(10) unsigned DEFAULT NULL,
  `signed_off_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `lock_status` varchar(30) NOT NULL DEFAULT 'not_required',
  PRIMARY KEY (`id`),
  KEY `fk_approval_created_admin` (`created_by_admin_id`),
  KEY `fk_approval_reviewed_admin` (`reviewed_by_admin_id`),
  KEY `idx_approval_member_status` (`member_id`,`request_status`),
  KEY `idx_approval_class_status` (`token_class_id`,`request_status`),
  KEY `idx_approval_reviewed` (`reviewed_at`),
  KEY `idx_approval_mint_status` (`mint_status`),
  KEY `idx_approval_signoff` (`signoff_category`,`signoff_status`),
  KEY `idx_approval_requests_member_token_status` (`member_id`,`token_class_id`,`request_status`),
  KEY `idx_approval_requests_signoff_status` (`signoff_status`),
  CONSTRAINT `fk_approval_class` FOREIGN KEY (`token_class_id`) REFERENCES `token_classes` (`id`),
  CONSTRAINT `fk_approval_created_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_approval_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_reviewed_admin` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asx_holdings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticker` varchar(10) NOT NULL COMMENT 'e.g. CBA.AX',
  `company_name` varchar(190) NOT NULL,
  `chess_hin` varchar(30) DEFAULT NULL COMMENT 'CHESS Holder Identification Number',
  `units_held` bigint(20) NOT NULL DEFAULT 0,
  `average_cost_cents` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Weighted avg cost per unit',
  `total_cost_cents` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `funded_by_stream` enum('beneficiary','donation','mixed') NOT NULL DEFAULT 'beneficiary' COMMENT 'Which stream funded this parcel (for Donation Ledger attribution)',
  `is_poor_esg_target` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Declaration cl.30.3',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asx_ticker` (`ticker`),
  KEY `idx_asx_stream` (`funded_by_stream`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asx_proxy_engagements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `holding_id` bigint(20) unsigned NOT NULL,
  `proxy_vote_instruction_id` bigint(20) unsigned DEFAULT NULL,
  `engagement_type` enum('agm_vote','egm_vote','esg_engagement','board_letter','other') NOT NULL DEFAULT 'agm_vote',
  `meeting_or_event_date` date DEFAULT NULL,
  `status` enum('draft','directed','submitted','confirmed','closed') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_asx_proxy_engagements_holding` (`holding_id`),
  KEY `fk_asx_proxy_engagements_instruction` (`proxy_vote_instruction_id`),
  CONSTRAINT `fk_asx_proxy_engagements_holding` FOREIGN KEY (`holding_id`) REFERENCES `asx_holdings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asx_proxy_engagements_instruction` FOREIGN KEY (`proxy_vote_instruction_id`) REFERENCES `proxy_vote_instructions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asx_stewardship_positions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stewardship_position_id` bigint(20) unsigned NOT NULL,
  `holding_id` bigint(20) unsigned NOT NULL,
  `proxy_eligible` tinyint(1) NOT NULL DEFAULT 1,
  `engagement_priority` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asx_stewardship_positions_pos_hold` (`stewardship_position_id`,`holding_id`),
  KEY `fk_asx_stewardship_positions_holding` (`holding_id`),
  CONSTRAINT `fk_asx_stewardship_positions_holding` FOREIGN KEY (`holding_id`) REFERENCES `asx_holdings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asx_stewardship_positions_position` FOREIGN KEY (`stewardship_position_id`) REFERENCES `stewardship_positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asx_trade_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_ref` varchar(80) NOT NULL COMMENT 'Unique ref e.g. ASXDOC-20260416-XXXXXX',
  `holding_id` bigint(20) unsigned NOT NULL COMMENT 'FK → asx_holdings.id',
  `trade_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → asx_trades.id — NULL = covers all trades on the holding',
  `document_type` enum('broker_confirmation','chess_statement','ig_statement','asx_announcement','valuation','other') NOT NULL DEFAULT 'broker_confirmation',
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL COMMENT 'Renamed file as stored on server',
  `file_size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `mime_type` varchar(100) NOT NULL DEFAULT 'application/pdf',
  `sha256_hash` varchar(64) NOT NULL COMMENT 'SHA-256 of raw file bytes — primary integrity anchor',
  `evidence_vault_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → evidence_vault_entries.id — set after vault anchoring',
  `chain_tx_hash` varchar(66) DEFAULT NULL COMMENT 'Besu tx hash when anchored on-chain',
  `ledger_tx_hash` varchar(128) DEFAULT NULL,
  `attestation_status` enum('uploaded','vault_anchored','chain_pending','chain_anchored') NOT NULL DEFAULT 'uploaded',
  `chain_handoff_id` int(10) unsigned DEFAULT NULL COMMENT 'FK → chain_handoffs.id — for legacy seed standalone attestation',
  `uploaded_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asx_doc_ref` (`document_ref`),
  UNIQUE KEY `uq_asx_doc_sha256` (`sha256_hash`),
  KEY `idx_asx_doc_holding` (`holding_id`),
  KEY `idx_asx_doc_trade` (`trade_id`),
  KEY `idx_asx_doc_attestation_status` (`attestation_status`),
  CONSTRAINT `fk_asx_doc_holding` FOREIGN KEY (`holding_id`) REFERENCES `asx_holdings` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_asx_doc_trade` FOREIGN KEY (`trade_id`) REFERENCES `asx_trades` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PDF evidence vault for ASX purchase lots. SHA-256 hash links document to on-chain attestation and any minted tokens backed by that lot.';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asx_trades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trade_ref` varchar(64) NOT NULL,
  `holding_id` bigint(20) unsigned NOT NULL COMMENT 'FK → asx_holdings',
  `trade_type` enum('buy','reinvestment','legacy_seed') NOT NULL COMMENT 'Cannot sell — entrenched',
  `units` bigint(20) NOT NULL DEFAULT 0,
  `price_cents_per_unit` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_cost_cents` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `brokerage_cents` bigint(20) NOT NULL DEFAULT 0,
  `trade_date` date NOT NULL,
  `settlement_date` date DEFAULT NULL COMMENT 'T+2',
  `funded_by` enum('member_payment','bds_reinvestment','dds_reinvestment') NOT NULL,
  `dividend_event_id` bigint(20) unsigned DEFAULT NULL COMMENT 'If reinvestment from a dividend event',
  `expense_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Brokerage expense record',
  `chess_confirmation_ref` varchar(120) DEFAULT NULL,
  `status` enum('pending','settled','failed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by_admin_id` int(10) unsigned DEFAULT NULL,
  `created_by_admin_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trade_ref` (`trade_ref`),
  KEY `fk_trade_holding` (`holding_id`),
  KEY `fk_trade_div_event` (`dividend_event_id`),
  KEY `fk_trade_expense` (`expense_id`),
  KEY `fk_trade_creator` (`created_by_admin_id`),
  CONSTRAINT `fk_trade_creator` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trade_div_event` FOREIGN KEY (`dividend_event_id`) REFERENCES `dividend_events` (`id`),
  CONSTRAINT `fk_trade_expense` FOREIGN KEY (`expense_id`) REFERENCES `trust_expenses` (`id`),
  CONSTRAINT `fk_trade_holding` FOREIGN KEY (`holding_id`) REFERENCES `asx_holdings` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_access_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(10) unsigned NOT NULL COMMENT 'FK admin_users.id',
  `username` varchar(100) NOT NULL,
  `access_type` enum('login','view_invariants','view_ledger','view_balance_sheet','view_reconciliation','export','logout','view_voice_submission','approve_voice_submission','reject_voice_submission','mark_voice_submission_used','withdraw_voice_submission_admin','delete_voice_submission_file') NOT NULL DEFAULT 'login',
  `page_or_view` varchar(190) DEFAULT NULL COMMENT 'Page URL or view name accessed',
  `ip_address` varchar(45) DEFAULT NULL,
  `session_ref` varchar(64) DEFAULT NULL COMMENT 'Admin session reference for correlation',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Immutable audit trail of auditor and counsel access to Godley views';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `audit_key` varchar(80) NOT NULL,
  `audit_type` enum('governance','execution','infrastructure','zone','full_stack') NOT NULL DEFAULT 'full_stack',
  `status` enum('opened','collecting','tested','verified','exceptions_found','remediated','closed') NOT NULL DEFAULT 'opened',
  `summary` text DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_runs_key` (`audit_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_rate_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `limit_key` char(64) NOT NULL COMMENT 'SHA-256(ip + | + action)',
  `action` varchar(40) NOT NULL COMMENT 'login | reset-password | admin-login | setup-password',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_until` datetime DEFAULT NULL COMMENT 'NULL = not locked',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_limit` (`limit_key`,`action`),
  KEY `idx_rate_locked` (`locked_until`),
  KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_recovery_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_type` varchar(40) NOT NULL COMMENT 'password_reset | member_number',
  `role_name` varchar(20) NOT NULL COMMENT 'snft | bnft',
  `auth_channel` varchar(10) NOT NULL COMMENT 'email | mobile',
  `identifier_value` varchar(190) DEFAULT NULL COMMENT 'name or member number supplied',
  `contact_value` varchar(190) NOT NULL COMMENT 'email or phone supplied',
  `outcome` varchar(20) NOT NULL COMMENT 'matched | rejected',
  `subject_ref` varchar(80) DEFAULT NULL COMMENT 'member_number or abn on match',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auth_recovery_outcome` (`outcome`),
  KEY `idx_auth_recovery_contact` (`contact_value`),
  KEY `idx_auth_recovery_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `benefit_flow_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `flow_ref` varchar(80) NOT NULL,
  `partner_id` bigint(20) unsigned DEFAULT NULL,
  `distribution_run_id` bigint(20) unsigned DEFAULT NULL,
  `flow_type` enum('beneficiary_distribution','donation_distribution','direct_sub_trust_c','reinvestment','other') NOT NULL,
  `amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_benefit_flow_records_ref` (`flow_ref`),
  KEY `fk_benefit_flow_records_partner` (`partner_id`),
  KEY `fk_benefit_flow_records_distribution_run` (`distribution_run_id`),
  CONSTRAINT `fk_benefit_flow_records_distribution_run` FOREIGN KEY (`distribution_run_id`) REFERENCES `distribution_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_benefit_flow_records_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bnft_memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `responsible_member_id` bigint(20) unsigned DEFAULT NULL COMMENT 'FK → snft_memberships.id — personal member who is the responsible person',
  `abn` varchar(11) NOT NULL,
  `legal_name` varchar(255) NOT NULL,
  `trading_name` varchar(255) DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Company, Trust, Partnership, Sole Trader, etc.',
  `contact_name` varchar(255) DEFAULT NULL,
  `position_title` varchar(100) DEFAULT NULL COMMENT 'Responsible person position (Director, Trustee, etc.)',
  `email` varchar(255) NOT NULL,
  `mobile` varchar(32) DEFAULT NULL,
  `state_code` varchar(3) DEFAULT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `suburb` varchar(100) DEFAULT NULL,
  `postcode` varchar(10) DEFAULT NULL,
  `gnaf_pid` varchar(40) DEFAULT NULL COMMENT 'G-NAF Persistent Identifier',
  `industry` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `use_case` text DEFAULT NULL COMMENT 'How the business intends to use COG$ membership',
  `reserved_tokens` int(11) NOT NULL DEFAULT 0,
  `invest_tokens` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `donation_tokens` int(11) NOT NULL DEFAULT 0,
  `pay_it_forward_tokens` int(11) NOT NULL DEFAULT 0,
  `landholder_hectares` decimal(12,2) NOT NULL DEFAULT 0.00,
  `landholder_tokens` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `rwa_tokens` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `community_tokens` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `bus_prop_tokens` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `reservation_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reservation_notice_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `reservation_notice_version` varchar(50) DEFAULT NULL,
  `reservation_notice_accepted_at` datetime DEFAULT NULL,
  `attestation_hash` varchar(128) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `wallet_status` varchar(32) NOT NULL DEFAULT 'pending_setup',
  `signup_payment_status` varchar(30) NOT NULL DEFAULT 'pending',
  `stripe_payment_url` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bnft_memberships_abn` (`abn`),
  KEY `idx_bnft_memberships_email` (`email`),
  KEY `idx_bnft_memberships_wallet_status` (`wallet_status`),
  KEY `idx_bnft_mobile` (`mobile`),
  KEY `idx_bnft_responsible_member` (`responsible_member_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `board_directors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `seat_type` enum('14.4(a)_community_adjacent','14.4(b)_professional_expertise','14.4(c)_fnac_nominated','at_large') NOT NULL,
  `phase` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT '1=founding,2=subcommittee_chairs,3=at_large',
  `full_name` varchar(200) NOT NULL,
  `address` varchar(500) NOT NULL DEFAULT '',
  `date_of_birth` date DEFAULT NULL,
  `qualification_basis` text DEFAULT NULL COMMENT 'Written finding for 14.4(a)/(b) qualification',
  `sub_committee` enum('STA','STB','STC','none') NOT NULL DEFAULT 'none',
  `sub_committee_role` enum('chair','member','none') NOT NULL DEFAULT 'none',
  `term_start_date` date DEFAULT NULL,
  `term_end_date` date DEFAULT NULL,
  `term_number` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'consecutive term count; max 3',
  `status` enum('nominee','active','resigned','removed','term_expired') NOT NULL DEFAULT 'nominee',
  `status_date` date DEFAULT NULL,
  `status_note` text DEFAULT NULL,
  `undertaking_signed_at` datetime DEFAULT NULL COMMENT 'Director undertaking executed',
  `key_ceremony_completed_at` datetime DEFAULT NULL COMMENT 'HSM key generation ceremony',
  `hsm_standard` varchar(100) NOT NULL DEFAULT '' COMMENT 'e.g. FIPS 140-3 Level 3',
  `appointing_resolution` varchar(200) NOT NULL DEFAULT '' COMMENT 'Resolution ref e.g. Inaugural-R2',
  `fnac_nomination_ref` varchar(200) NOT NULL DEFAULT '' COMMENT 'FNAC nomination document ref for 14.4(c)',
  `member_number` varchar(50) NOT NULL DEFAULT '' COMMENT 'Link to members table if director is a member',
  `evidence_hash` varchar(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 of director undertaking',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_board_directors_uuid` (`uuid`),

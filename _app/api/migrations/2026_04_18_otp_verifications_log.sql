-- Migration: otp_verifications log table + sessions otp_verified_at column
-- Purpose: support 12-hour OTP skip window that survives logout/session deletion
-- Run via phpMyAdmin on cogsaustralia.org

-- 1. Add otp_verified_at to sessions if missing (server may not have it)
ALTER TABLE `sessions`
  ADD COLUMN IF NOT EXISTS `otp_verified_at` datetime DEFAULT NULL
    COMMENT 'Timestamp when OTP was verified for this session'
  AFTER `otp_verified`;

-- 2. Persistent OTP verification log — never deleted on logout
--    One row per successful OTP verification event.
CREATE TABLE IF NOT EXISTS `otp_verifications` (
  `id`           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `principal_id` int(10) UNSIGNED    NOT NULL COMMENT 'snft_memberships.id',
  `user_type`    varchar(20)         NOT NULL DEFAULT 'snft',
  `verified_at`  datetime            NOT NULL DEFAULT current_timestamp(),
  `ip_address`   varchar(45)         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_otp_verif_principal` (`principal_id`, `verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Persistent log of OTP verification events. Used for 12-hour re-login skip window. Never deleted on logout.';

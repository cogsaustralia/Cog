-- Migration: 2026_04_19_invite_share_count
-- Add share_count to partner_invite_codes to track how many times
-- a Partner has shared their invite link (distinct from use_count which
-- tracks how many invitees actually joined).

ALTER TABLE `partner_invite_codes`
  ADD COLUMN `share_count` int(10) UNSIGNED NOT NULL DEFAULT 0
  AFTER `use_count`;

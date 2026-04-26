-- ============================================================
-- Migration: 2026_04_26_payments_payment_type_enum
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL first, then PHP (stripe-webhook.php).
-- Prerequisite: none.
--
-- Issue
--   stripe-webhook.php INSERTs four payment_type values that are NOT
--   in the live ENUM:
--       'signup_fee'      (member signup via Stripe)
--       'gift_pool'       (member gift pool top-up via Stripe)
--       'bnft_signup'     (business signup via Stripe)
--       'bnft_gift_pool'  (business gift pool top-up via Stripe)
--
--   The current ENUM is ('signup','adjustment','manual'). Every webhook
--   INSERT has been silently rejected by MariaDB strict mode and
--   swallowed by the webhook's try/catch, leaving the payments table
--   without any record of Stripe-driven transactions. The admin
--   compensated by manually creating BACKFILL-* rows.
--
-- Fix
--   Add the four missing values to the ENUM. Existing values
--   ('signup','adjustment','manual') are preserved verbatim per the
--   "list all existing values before appending" rule. Default unchanged.
--
-- Verification (after running)
--   SHOW COLUMNS FROM payments WHERE Field = 'payment_type';
--   -- Expect Type:
--   --   enum('signup','adjustment','manual','signup_fee','gift_pool',
--   --        'bnft_signup','bnft_gift_pool')
-- ============================================================

ALTER TABLE `payments`
  MODIFY COLUMN `payment_type`
  ENUM(
    'signup',
    'adjustment',
    'manual',
    'signup_fee',
    'gift_pool',
    'bnft_signup',
    'bnft_gift_pool'
  ) NOT NULL DEFAULT 'manual';

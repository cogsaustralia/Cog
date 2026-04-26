-- ============================================================
-- Migration: 2026_04_26_stripe_processed_events
-- Run against: cogsaust_TRUST via phpMyAdmin
-- Deploy order: SQL first, then PHP (stripe-webhook.php).
-- Prerequisite: none.
--
-- Purpose
--   Idempotency log for Stripe webhook deliveries. Stripe retries
--   events on connection drop, timeout, or any non-2xx response.
--   Without an event-id store, the gift_pool path in stripe-webhook.php
--   double-INSERTs to `payments` and `payment_allocations`, double-fires
--   AccountingHooks::onPaymentConfirmed, and queues duplicate confirmation
--   emails to the member and admin on every retry.
--
--   This table provides at-most-once semantics: an INSERT IGNORE at the
--   top of the webhook either wins (rowCount=1, proceed) or loses
--   (rowCount=0, exit early as duplicate).
--
-- Pruning
--   Stripe's retry window is 3 days. Rows older than 30 days are safe
--   to delete. No cron is wired up — manual prune is fine for the
--   current event volume.
-- ============================================================

CREATE TABLE IF NOT EXISTS `stripe_processed_events` (
  `event_id`    varchar(80) NOT NULL COMMENT 'Stripe event["id"] e.g. evt_3TJaqr...',
  `event_type`  varchar(60) NOT NULL COMMENT 'Stripe event["type"] e.g. checkout.session.completed',
  `received_at` datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_spe_received_at` (`received_at`),
  KEY `idx_spe_event_type`  (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Idempotency log of processed Stripe webhook events. Prevents duplicate processing on Stripe retries. Pruning: rows older than 30 days are safe to delete (Stripe retry window is 3 days).';

-- ============================================================
-- Verification (run after creating the table)
-- ============================================================
-- SHOW CREATE TABLE `stripe_processed_events`\G
-- DESCRIBE `stripe_processed_events`;

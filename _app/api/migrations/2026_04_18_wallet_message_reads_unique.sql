-- Migration: ensure wallet_message_reads has UNIQUE constraint for ON DUPLICATE KEY support
-- Safe to run on a server that already has the constraint — ADD UNIQUE IF NOT EXISTS is not
-- standard MariaDB syntax, so we use the ignore-duplicate approach via a conditional.

-- Add the unique key only if it doesn't already exist
SET @exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wallet_message_reads'
    AND CONSTRAINT_NAME = 'uq_wallet_message_read'
    AND CONSTRAINT_TYPE = 'UNIQUE'
);

-- Create a procedure to conditionally add the key, then drop it
DROP PROCEDURE IF EXISTS _add_wmr_unique;
DELIMITER $$
CREATE PROCEDURE _add_wmr_unique()
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'wallet_message_reads'
        AND CONSTRAINT_NAME = 'uq_wallet_message_read'
        AND CONSTRAINT_TYPE = 'UNIQUE') = 0 THEN
    ALTER TABLE `wallet_message_reads`
      ADD UNIQUE KEY `uq_wallet_message_read` (`message_id`, `member_id`);
  END IF;
END$$
DELIMITER ;
CALL _add_wmr_unique();
DROP PROCEDURE IF EXISTS _add_wmr_unique;

-- Add idx_wmr_message for fast per-notice read-count queries (used by admin tracking)
ALTER TABLE `wallet_message_reads`
  ADD KEY IF NOT EXISTS `idx_wmr_message` (`message_id`, `read_at`);

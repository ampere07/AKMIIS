-- pending_payments table for Laravel CBMS
-- This table tracks payment transactions from Xendit gateway

CREATE TABLE IF NOT EXISTS `pending_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_no` VARCHAR(50) NOT NULL COMMENT 'Customer account number',
  `reference_no` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique payment reference (AccountNo-RandomHex)',
  `amount` DECIMAL(10, 2) NOT NULL COMMENT 'Payment amount in PHP',
  `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING' COMMENT 'Payment status: PENDING, QUEUED, PROCESSING, PAID, FAILED, API_RETRY',
  `payment_date` DATETIME NOT NULL COMMENT 'Timestamp when payment was initiated',
  `provider` VARCHAR(50) NULL COMMENT 'Payment provider: XENDIT, MAYA',
  `plan` VARCHAR(100) NULL COMMENT 'Customer service plan',
  `payment_id` VARCHAR(255) NULL COMMENT 'Gateway payment/invoice ID',
  `payment_method_id` VARCHAR(255) NULL COMMENT 'Payment method identifier from gateway',
  `json_payload` TEXT NULL COMMENT 'Original payment request payload',
  `payment_url` TEXT NULL COMMENT 'Gateway payment URL for customer',
  `callback_payload` LONGTEXT NULL COMMENT 'Webhook callback data from gateway',
  `reconnect_status` VARCHAR(50) NULL COMMENT 'Reconnection API status: success, failed',
  `last_attempt_at` TIMESTAMP NULL COMMENT 'Last processing attempt timestamp',
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_account_no` (`account_no`),
  INDEX `idx_status` (`status`),
  INDEX `idx_reference_no` (`reference_no`),
  INDEX `idx_status_payment_date` (`status`, `payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Xendit payment tracking table';

-- Status flow explanation:
-- PENDING    -> Initial state when payment link is created
-- QUEUED     -> Webhook confirmed payment is successful, ready for processing
-- PROCESSING -> Worker is currently processing this payment
-- PAID       -> Successfully processed and account updated
-- FAILED     -> Payment failed or invalid
-- API_RETRY  -> Billing API failed, will retry on next worker run

-- ============================================================
-- Agent Incentive History Table
-- ------------------------------------------------------------
-- Records every Job Order that has already been counted toward an
-- agent quota incentive award. This is the idempotency ledger used by
-- the AgentIncentiveService cron: a Job Order present here is NEVER
-- counted again, so re-running the cron can never double-pay incentives.
--
-- Ready to copy/paste directly into phpMyAdmin (MySQL / MariaDB).
-- ============================================================

CREATE TABLE IF NOT EXISTS `agent_incentive_history` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`        INT NOT NULL,                                   -- agent_balance.agent_id / users.id
    `job_order_id`    BIGINT UNSIGNED NOT NULL,                       -- job_orders.id that was counted
    `quota_reached`   INT NOT NULL DEFAULT 0,                         -- the quota value satisfied when this JO was processed
    `batch_number`    INT NOT NULL DEFAULT 0,                         -- per-agent incrementing quota cycle number this JO belonged to
    `incentive_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,           -- incentive amount awarded for the cycle this JO belonged to
    `organization_id` BIGINT NULL DEFAULT NULL,                       -- copied from agent_balance for multi-tenant reporting
    `processed_at`    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,       -- when the cron processed/awarded this JO
    `created_at`      TIMESTAMP NULL DEFAULT NULL,
    `updated_at`      TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),

    -- HARD GUARANTEE: a Job Order can be recorded only once, ever.
    -- This is the ultimate guard against duplicate counting / double incentives,
    -- independent of any application-level checks.
    UNIQUE KEY `uq_aih_job_order_id` (`job_order_id`),

    -- Performance indexes for the cron lookups and reporting.
    KEY `idx_aih_agent_id` (`agent_id`),
    KEY `idx_aih_agent_job` (`agent_id`, `job_order_id`),
    KEY `idx_aih_agent_batch` (`agent_id`, `batch_number`),
    KEY `idx_aih_organization_id` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

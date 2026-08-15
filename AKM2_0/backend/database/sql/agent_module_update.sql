-- ===========================================================================
--  AKM — Agent module database update
--
--  Everything the Agent module needs that is not already in the database:
--  payout approval, weekly/monthly achievements, weekly referral invoices,
--  and job-order settlement with the referring agent.
--
--  Just copy and paste the whole thing into phpMyAdmin's SQL tab and run it.
--
--  IF YOU SEE "Duplicate column name" — that is fine, it only means that
--  column is already there. Every ALTER is its own statement on purpose, so
--  one that is already applied does not stop the rest from running. Keep
--  going; the check at the bottom tells you whether anything is genuinely
--  missing.
--
--  Run this OR `php artisan migrate`, not both by halves. The last section
--  marks the migrations as done so `migrate` afterwards does nothing.
-- ===========================================================================

SET NAMES utf8mb4;


-- ===========================================================================
--  1. PAYOUT APPROVAL
--  A payout is recorded as Pending and moves no money. Approving it is what
--  applies it to the agent's balance.
-- ===========================================================================

ALTER TABLE `agent_commission_history` ADD COLUMN `status`        VARCHAR(20)  NULL DEFAULT 'Pending';
ALTER TABLE `agent_commission_history` ADD COLUMN `approve_by`    VARCHAR(255) NULL DEFAULT NULL;
-- The job orders a payout settles, stored when it is raised so approval marks
-- exactly those as paid.
ALTER TABLE `agent_commission_history` ADD COLUMN `job_order_ids` TEXT         NULL DEFAULT NULL;

ALTER TABLE `agent_bonus_history` ADD COLUMN `status`     VARCHAR(20)  NULL DEFAULT 'Pending';
ALTER TABLE `agent_bonus_history` ADD COLUMN `approve_by` VARCHAR(255) NULL DEFAULT NULL;

-- Everything recorded before this change already moved the agent's balance at
-- the time it was saved, so it is approved by definition. Left Pending, those
-- rows could be approved later and move the same money a second time.
UPDATE `agent_commission_history` SET `status` = 'Approved' WHERE `status` IS NULL;
UPDATE `agent_bonus_history`      SET `status` = 'Approved' WHERE `status` IS NULL;


-- ===========================================================================
--  2. ACHIEVEMENT CLAIMS
--  Turns a once-ever milestone into a reward that repeats weekly and monthly.
-- ===========================================================================

-- Safety net for a database that never got the July achievement migration.
-- Does nothing where the table already exists.
CREATE TABLE IF NOT EXISTS `agent_achievement_claims` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`   BIGINT UNSIGNED NOT NULL,
    `milestone`  INT NOT NULL,
    `amount`     DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `agent_achievement_claims_agent_id_index` (`agent_id`),
    CONSTRAINT `agent_achievement_claims_agent_id_foreign`
        FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 'weekly' / 'monthly' / 'lifetime' (the retired once-ever milestone).
ALTER TABLE `agent_achievement_claims` ADD COLUMN `period_type`   VARCHAR(20) NULL DEFAULT NULL;
-- '2026-W33', '2026-08', or an anchored cycle key like 'w@20260812-100000'.
ALTER TABLE `agent_achievement_claims` ADD COLUMN `period_key`    VARCHAR(20) NULL DEFAULT NULL;
-- The span the reward was earned over. cycle_end is the moment of the claim,
-- which is where the next cycle starts — claiming early ends the cycle there
-- and begins a fresh one, so the agent carries on instead of waiting out the
-- rest of the week.
ALTER TABLE `agent_achievement_claims` ADD COLUMN `cycle_start`   TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `agent_achievement_claims` ADD COLUMN `cycle_end`     TIMESTAMP NULL DEFAULT NULL;
-- The referrals this reward was paid for, as a JSON array of job order ids.
-- A job order listed here is skipped by every later count for the same tier,
-- so backdating its installation date cannot make it earn the reward twice.
ALTER TABLE `agent_achievement_claims` ADD COLUMN `job_order_ids` LONGTEXT NULL DEFAULT NULL;

-- "Duplicate key name" here is the same harmless case as a duplicate column.
ALTER TABLE `agent_achievement_claims` ADD INDEX `agent_achievement_claims_period_type_index` (`period_type`);
ALTER TABLE `agent_achievement_claims` ADD INDEX `agent_achievement_claims_period_key_index`  (`period_key`);
-- Read on every dashboard load to find the agent's current cycle anchor.
ALTER TABLE `agent_achievement_claims` ADD INDEX `claim_anchor_index` (`agent_id`, `period_type`, `cycle_end`);

-- Anything claimed before this change belongs to the retired lifetime
-- milestone. Left unlabelled it would block a weekly or monthly claim whose
-- target happened to match.
UPDATE `agent_achievement_claims`
   SET `period_type` = 'lifetime',
       `period_key`  = 'lifetime'
 WHERE `period_type` IS NULL;


-- ===========================================================================
--  3. ACHIEVEMENT PERIODS
--  The closing record of each cycle, per agent per tier. Progress is counted
--  from the referrals inside the current cycle rather than held as a running
--  total, so a new week starts at zero on its own. This table records where
--  the last one finished, and states outright that nothing carried over.
--
--  The unique key is what makes closing safe to attempt repeatedly — from a
--  dashboard load, from the scheduled command, or from both at once.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `agent_achievement_periods` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`        BIGINT UNSIGNED NOT NULL,

    -- 'weekly' / 'monthly', and the period itself: '2026-W33' / '2026-08'.
    `period_type`     VARCHAR(20) NOT NULL,
    `period_key`      VARCHAR(20) NOT NULL,
    `period_start`    DATE NULL DEFAULT NULL,
    `period_end`      DATE NULL DEFAULT NULL,

    -- What the tier asked for, and what the agent reached before it closed.
    `target`          INT NOT NULL DEFAULT 0,
    `onboarded`       INT NOT NULL DEFAULT 0,
    `reached`         TINYINT(1) NOT NULL DEFAULT 0,

    -- Whether the reward was taken while the period was still open.
    `claimed`         TINYINT(1) NOT NULL DEFAULT 0,
    `claim_id`        BIGINT UNSIGNED NULL DEFAULT NULL,
    `reward_paid`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Always zero: progress does not follow the agent into the next period.
    -- Recorded explicitly so the ledger states it rather than leaving it to be
    -- inferred from a missing figure.
    `carried_over`    INT NOT NULL DEFAULT 0,

    -- 'period_ended' | 'claimed_early'. Without it a short cycle in the ledger
    -- reads as a bug rather than as an early claim.
    `closed_reason`   VARCHAR(20) NULL DEFAULT NULL,

    `closed_at`       TIMESTAMP NULL DEFAULT NULL,
    `closed_by`       VARCHAR(255) NULL DEFAULT NULL,
    `organization_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at`      TIMESTAMP NULL DEFAULT NULL,
    `updated_at`      TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `agent_achievement_periods_agent_id_index` (`agent_id`),
    KEY `agent_achievement_periods_organization_id_index` (`organization_id`),

    -- One closure per agent per tier per period.
    UNIQUE KEY `agent_period_unique` (`agent_id`, `period_type`, `period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For a database where this table predates the early-claim work.
ALTER TABLE `agent_achievement_periods` ADD COLUMN `closed_reason` VARCHAR(20) NULL DEFAULT NULL;


-- ===========================================================================
--  4. AGENT INVOICES
--  One invoice per owner per billing week, where an owner is a team or a solo
--  agent.
--
--  `owner_key` ('team:5' / 'solo:201') looks redundant beside team_id and
--  agent_id, but it is what makes the uniqueness work: MySQL treats NULLs as
--  distinct in a unique index, so a key built from the nullable columns would
--  let a team invoice with a NULL agent_id repeat unnoticed.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `agent_invoices` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Never reused, even if an invoice is deleted from the UI.
    `invoice_number`   VARCHAR(40) NOT NULL,

    -- 'team' or 'solo'.
    `invoice_type`     VARCHAR(10) NOT NULL,

    -- 'team:5' / 'solo:201' — the owner this invoice belongs to.
    `owner_key`        VARCHAR(40) NOT NULL,

    `team_id`          BIGINT UNSIGNED NULL DEFAULT NULL,
    `agent_id`         BIGINT UNSIGNED NULL DEFAULT NULL,

    -- Names as they were when the invoice was raised, so a later rename does
    -- not rewrite history on an already-issued document.
    `team_name`        VARCHAR(255) NULL DEFAULT NULL,
    `agent_name`       VARCHAR(255) NULL DEFAULT NULL,

    `period_start`     DATE NOT NULL,
    `period_end`       DATE NOT NULL,
    `invoice_date`     DATE NOT NULL,

    `total_customers`  INT NOT NULL DEFAULT 0,
    `unit_price`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `installation_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `commission`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `subtotal`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    `pdf_path`         VARCHAR(255) NULL DEFAULT NULL,

    -- 'Generated' | 'Sent' | 'Paid' | 'Cancelled'
    `status`           VARCHAR(20) NOT NULL DEFAULT 'Generated',

    `organization_id`  BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_by`       VARCHAR(255) NULL DEFAULT NULL,
    `updated_by`       VARCHAR(255) NULL DEFAULT NULL,
    `created_at`       TIMESTAMP NULL DEFAULT NULL,
    `updated_at`       TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `agent_invoices_invoice_number_unique` (`invoice_number`),

    -- One invoice per owner per billing week. This is what makes a repeated
    -- run harmless: the second attempt is refused here rather than relying on
    -- the schedule firing exactly once.
    UNIQUE KEY `agent_invoice_owner_period_unique` (`owner_key`, `period_start`),

    KEY `agent_invoices_invoice_type_index` (`invoice_type`),
    KEY `agent_invoices_owner_key_index` (`owner_key`),
    KEY `agent_invoices_team_id_index` (`team_id`),
    KEY `agent_invoices_agent_id_index` (`agent_id`),
    KEY `agent_invoices_status_index` (`status`),
    KEY `agent_invoices_organization_id_index` (`organization_id`),

    -- The listing sorts newest-first and filters by owner.
    KEY `agent_invoice_owner_date_index` (`owner_key`, `invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `agent_invoice_customers` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    `agent_invoice_id`     BIGINT UNSIGNED NOT NULL,
    -- The referred customer, as their application record.
    `application_id`       BIGINT UNSIGNED NOT NULL,
    -- The installed job order the referral was billed on, if known.
    `job_order_id`         BIGINT UNSIGNED NULL DEFAULT NULL,

    -- Repeated from the invoice so the uniqueness below can be enforced by the
    -- database rather than by a query.
    `owner_key`            VARCHAR(40) NOT NULL,

    `customer_name`        VARCHAR(255) NOT NULL,
    -- Which agent in the team actually referred them.
    `referred_by_agent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `referred_by_name`     VARCHAR(255) NULL DEFAULT NULL,
    -- The raw "Referred By" value, kept for tracing a mismatch.
    `referred_by_raw`      VARCHAR(255) NULL DEFAULT NULL,

    `installed_date`       DATE NULL DEFAULT NULL,
    `unit_price`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity`             INT NOT NULL DEFAULT 1,
    `total`                DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    `created_at`           TIMESTAMP NULL DEFAULT NULL,
    `updated_at`           TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (`id`),

    -- A customer appears once on an invoice...
    UNIQUE KEY `agent_invoice_customer_unique` (`agent_invoice_id`, `application_id`),

    -- ...and once for an owner, ever. This is the duplicate prevention the
    -- database enforces: a customer already billed for this team or agent
    -- cannot be written onto a later invoice for them, whatever the caller
    -- believes.
    UNIQUE KEY `agent_invoice_owner_customer_unique` (`owner_key`, `application_id`),

    KEY `agent_invoice_customers_job_order_id_index` (`job_order_id`),
    KEY `agent_invoice_customers_owner_key_index` (`owner_key`),
    KEY `agent_invoice_customers_referred_by_agent_id_index` (`referred_by_agent_id`),

    CONSTRAINT `agent_invoice_customers_agent_invoice_id_foreign`
        FOREIGN KEY (`agent_invoice_id`) REFERENCES `agent_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  5. JOB ORDER SETTLEMENT
--  Approving a job order settles it with the referring agent: it is marked
--  Paid, the commission is credited, and the rates used are written onto the
--  row.
--
--  The rates are snapshots on purpose. An administrator may change either
--  setting later, and a job order approved last month must keep the figure it
--  was actually settled at — otherwise a change to the current rate would
--  silently restate money already paid.
--
--  `agent_paid_at` is what stops a second approval paying twice: it is written
--  in the same transaction as the credit, so a row carrying it has been paid.
-- ===========================================================================

-- What one referral was worth toward the quota incentive at approval.
ALTER TABLE `job_orders` ADD COLUMN `incentive_value`  DECIMAL(10,2) NULL DEFAULT NULL;
-- What it paid in commission at approval.
ALTER TABLE `job_orders` ADD COLUMN `commission_value` DECIMAL(10,2) NULL DEFAULT NULL;
-- When the agent was credited, and which agent.
ALTER TABLE `job_orders` ADD COLUMN `agent_paid_at`    TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `job_orders` ADD COLUMN `agent_paid_to`    BIGINT UNSIGNED NULL DEFAULT NULL;

-- Read on every approval and on every incentive cron pass.
ALTER TABLE `job_orders` ADD INDEX `job_orders_agent_paid_index` (`agent_paid_to`, `agent_paid_at`);


-- ===========================================================================
--  6. AGENT COMMISSION EARNINGS
--
--  `agent_balance` already has a `commission` column, but that is the RATE one
--  referral pays — the figure the payout screens read to work out what a job
--  order is worth. It is a setting, not a running total, so earnings cannot go
--  into it.
--
--    commission        what one referral pays    (a setting)
--    commission_value  what the agent has earned  (a balance)
-- ===========================================================================

ALTER TABLE `agent_balance` ADD COLUMN `commission_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00;

-- Columns the agent balance screens read that an older database may not have.
-- Expect "Duplicate column name" on both if you are already up to date.
ALTER TABLE `agent_balance` ADD COLUMN `achievement`     DECIMAL(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `agent_balance` ADD COLUMN `organization_id` BIGINT UNSIGNED NULL DEFAULT NULL;


-- ===========================================================================
--  7. TELL LARAVEL THESE ARE DONE
--  Otherwise `php artisan migrate` would try to create these tables again.
--  INSERT IGNORE, so running this twice cannot duplicate a row.
-- ===========================================================================

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_08_11_000001_add_approval_status_to_agent_payout_tables',    99),
  ('2026_08_11_000002_add_period_to_agent_achievement_claims',        99),
  ('2026_08_12_000001_create_agent_achievement_periods_table',        99),
  ('2026_08_12_000002_add_cycle_bounds_to_agent_achievements',        99),
  ('2026_08_12_000003_add_job_order_ids_to_agent_achievement_claims', 99),
  ('2026_08_13_000001_create_agent_invoices_tables',                  99),
  ('2026_08_14_000001_add_agent_payment_columns_to_job_orders',       99),
  ('2026_08_14_000002_add_commission_value_to_agent_balance',         99);


-- ===========================================================================
--  8. CHECK
--  Run this last. Every row should say OK. Anything saying MISSING did not
--  apply — scroll up, find that statement, and run it on its own to see why.
-- ===========================================================================

SELECT 'agent_commission_history.status' AS `column_or_table`,
       IF(COUNT(*) > 0, 'OK', 'MISSING') AS `result`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_commission_history' AND COLUMN_NAME = 'status'
UNION ALL
SELECT 'agent_commission_history.approve_by', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_commission_history' AND COLUMN_NAME = 'approve_by'
UNION ALL
SELECT 'agent_commission_history.job_order_ids', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_commission_history' AND COLUMN_NAME = 'job_order_ids'
UNION ALL
SELECT 'agent_bonus_history.status', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_bonus_history' AND COLUMN_NAME = 'status'
UNION ALL
SELECT 'agent_bonus_history.approve_by', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_bonus_history' AND COLUMN_NAME = 'approve_by'
UNION ALL
SELECT 'agent_achievement_claims.period_type', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_claims' AND COLUMN_NAME = 'period_type'
UNION ALL
SELECT 'agent_achievement_claims.period_key', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_claims' AND COLUMN_NAME = 'period_key'
UNION ALL
SELECT 'agent_achievement_claims.cycle_start', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_claims' AND COLUMN_NAME = 'cycle_start'
UNION ALL
SELECT 'agent_achievement_claims.cycle_end', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_claims' AND COLUMN_NAME = 'cycle_end'
UNION ALL
SELECT 'agent_achievement_claims.job_order_ids', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_claims' AND COLUMN_NAME = 'job_order_ids'
UNION ALL
SELECT 'agent_achievement_periods (table)', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_periods'
UNION ALL
SELECT 'agent_achievement_periods.closed_reason', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_achievement_periods' AND COLUMN_NAME = 'closed_reason'
UNION ALL
SELECT 'agent_invoices (table)', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_invoices'
UNION ALL
SELECT 'agent_invoice_customers (table)', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_invoice_customers'
UNION ALL
SELECT 'job_orders.incentive_value', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'incentive_value'
UNION ALL
SELECT 'job_orders.commission_value', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'commission_value'
UNION ALL
SELECT 'job_orders.agent_paid_at', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'agent_paid_at'
UNION ALL
SELECT 'job_orders.agent_paid_to', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'agent_paid_to'
UNION ALL
SELECT 'agent_balance.commission_value', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_balance' AND COLUMN_NAME = 'commission_value'
UNION ALL
SELECT 'agent_balance.achievement', IF(COUNT(*) > 0, 'OK', 'MISSING')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_balance' AND COLUMN_NAME = 'achievement';

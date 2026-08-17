-- =============================================================================
-- AKMIIS — Tools Suite (SmartOLT Tool + Mikrotik Radius Tool)
-- Raw MySQL migration. Additive only: no DROP, no TRUNCATE, no column removal,
-- no data rewrite. Safe to run on a live production database and safe to re-run.
--
-- Run order: section 1, then 2, then 3. Section 4 is a verification report.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. Idempotent index helper
--
-- MySQL has no CREATE INDEX IF NOT EXISTS. This procedure adds an index only
-- when it is absent, so re-running the whole script is a no-op rather than an
-- "1061 Duplicate key name" failure.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS akmiis_add_index_if_missing;

DELIMITER //
CREATE PROCEDURE akmiis_add_index_if_missing(
    IN p_table   VARCHAR(64),
    IN p_index   VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_index_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;

    IF v_table_exists > 0 THEN
        SELECT COUNT(*) INTO v_index_exists
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index;

        IF v_index_exists = 0 THEN
            SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
            SELECT CONCAT('ADDED  ', p_table, '.', p_index) AS result;
        ELSE
            SELECT CONCAT('EXISTS ', p_table, '.', p_index) AS result;
        END IF;
    ELSE
        SELECT CONCAT('SKIP   table ', p_table, ' not found') AS result;
    END IF;
END //
DELIMITER ;


-- -----------------------------------------------------------------------------
-- 1. tool_jobs — stepwise progress for the SmartOLT background operations
--
-- The SmartOLT sweeps run over thousands of ONUs across many HTTP round trips,
-- far more than one request can finish. Each step advances a row here and
-- returns, so progress survives a page reload, and SmartOltReconciliationService
-- ::startJob() claims with SELECT ... FOR UPDATE over the active rows so two
-- operators cannot start overlapping runs.
--
-- Equivalent to migration 2026_08_17_000010_create_tool_jobs_table.php — run
-- either this block or `php artisan migrate`, not both.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tool_jobs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tool`            VARCHAR(50)     NOT NULL,
    `type`            VARCHAR(50)     NOT NULL,
    `status`          VARCHAR(20)     NOT NULL DEFAULT 'pending',
    `current`         INT UNSIGNED    NOT NULL DEFAULT 0,
    `total`           INT UNSIGNED    NOT NULL DEFAULT 0,
    `message`         TEXT            NULL,
    `context`         LONGTEXT        NULL,
    `summary`         LONGTEXT        NULL,
    `user_id`         BIGINT UNSIGNED NULL,
    `organization_id` BIGINT          NULL,
    `created_at`      TIMESTAMP       NULL DEFAULT NULL,
    `updated_at`      TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `tool_jobs_tool_status_index` (`tool`, `status`),
    KEY `tool_jobs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 2. Supporting indexes
--
-- Each one backs a specific query the tools run on every load. Without them the
-- sweeps table-scan, which on a live subscriber base is the difference between
-- a two-second audit and a two-minute one.
-- -----------------------------------------------------------------------------

-- SmartOltReconciliationService::buildSafetyMap() and the alignment serial match
-- both look ONUs up by modem serial.
CALL akmiis_add_index_if_missing('technical_details', 'technical_details_router_modem_sn_index', '`router_modem_sn`');
CALL akmiis_add_index_if_missing('job_orders',        'job_orders_modem_router_sn_index',       '`modem_router_sn`');

-- RadiusReconciliationService::resolvePlanLabel() runs SELECT DISTINCT desired_plan
-- to recover the priced label for a bare RADIUS group.
CALL akmiis_add_index_if_missing('customers', 'customers_desired_plan_index', '`desired_plan`');

-- getLogs() filters on resource_type and orders by created_at DESC. The existing
-- single-column indexes cannot serve both halves; this composite can.
CALL akmiis_add_index_if_missing('activity_logs', 'activity_logs_resource_type_created_at_index', '`resource_type`, `created_at`');

-- The undo engine looks an entry up by (log_id, resource_type).
CALL akmiis_add_index_if_missing('activity_logs', 'activity_logs_log_id_resource_type_index', '`log_id`, `resource_type`');

DROP PROCEDURE IF EXISTS akmiis_add_index_if_missing;


-- -----------------------------------------------------------------------------
-- 3. activity_logs.log_id — PRIMARY KEY / AUTO_INCREMENT
--
-- READ BEFORE RUNNING. The Laravel migration that created activity_logs declares
-- log_id as a plain unsignedBigInteger with no primary key and no auto-increment.
-- The live table almost certainly does have both (ActivityLog::create() is used
-- across the application and works), which means production drifted from the
-- migration at some point.
--
-- The undo engine addresses entries by log_id, so it needs that column to be a
-- unique, auto-assigned identifier. Section 4 reports the actual state. Only run
-- the statement below if that report shows log_id is NOT auto_increment — and
-- take a backup first, because rewriting a primary key on a large log table
-- locks it for the duration.
--
-- ALTER TABLE `activity_logs`
--     MODIFY `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
--     ADD PRIMARY KEY (`log_id`);
-- -----------------------------------------------------------------------------


-- -----------------------------------------------------------------------------
-- 4. Verification — run after the sections above and read the output
-- -----------------------------------------------------------------------------

-- 4a. Is log_id already a proper auto-increment primary key?
SELECT
    COLUMN_NAME,
    COLUMN_KEY,
    EXTRA,
    CASE
        WHEN EXTRA LIKE '%auto_increment%' AND COLUMN_KEY = 'PRI'
            THEN 'OK — undo will work'
        ELSE 'ACTION REQUIRED — see section 3'
    END AS verdict
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'activity_logs'
  AND COLUMN_NAME  = 'log_id';

-- 4b. Did every index land?
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND INDEX_NAME IN (
      'technical_details_router_modem_sn_index',
      'job_orders_modem_router_sn_index',
      'customers_desired_plan_index',
      'activity_logs_resource_type_created_at_index',
      'activity_logs_log_id_resource_type_index',
      'tool_jobs_tool_status_index',
      'tool_jobs_created_at_index'
  )
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- 4c. Does tool_jobs exist with the expected shape?
SELECT COUNT(*) AS tool_jobs_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tool_jobs';
-- Expect: 12

-- 4d. Are there RADIUS servers for the tool to target?
SELECT id, ssl_type, ip, port, organization_id
FROM radius_config
ORDER BY id;

-- 4e. Is SmartOLT configured?
SELECT id, sub_domain, CASE WHEN token IS NULL OR token = '' THEN 'MISSING' ELSE 'SET' END AS token_state
FROM smart_olt
LIMIT 1;

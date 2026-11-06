USE ira_assets_recovered;

-- Add only missing monitoring columns; this migration does not change assignments, lifecycle status, or history.
SET @admon_col_1_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_dns_hostname');
SET @admon_col_1_sql := IF(@admon_col_1_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_dns_hostname VARCHAR(255) NULL');
PREPARE admon_col_1_stmt FROM @admon_col_1_sql;
EXECUTE admon_col_1_stmt;
DEALLOCATE PREPARE admon_col_1_stmt;
SET @admon_col_2_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_organizational_unit');
SET @admon_col_2_sql := IF(@admon_col_2_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_organizational_unit VARCHAR(1024) NULL');
PREPARE admon_col_2_stmt FROM @admon_col_2_sql;
EXECUTE admon_col_2_stmt;
DEALLOCATE PREPARE admon_col_2_stmt;
SET @admon_col_3_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_last_logon_at');
SET @admon_col_3_sql := IF(@admon_col_3_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_last_logon_at DATETIME(6) NULL');
PREPARE admon_col_3_stmt FROM @admon_col_3_sql;
EXECUTE admon_col_3_stmt;
DEALLOCATE PREPARE admon_col_3_stmt;
SET @admon_col_4_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_account_created_at');
SET @admon_col_4_sql := IF(@admon_col_4_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_account_created_at DATETIME(6) NULL');
PREPARE admon_col_4_stmt FROM @admon_col_4_sql;
EXECUTE admon_col_4_stmt;
DEALLOCATE PREPARE admon_col_4_stmt;
SET @admon_col_5_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_account_modified_at');
SET @admon_col_5_sql := IF(@admon_col_5_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_account_modified_at DATETIME(6) NULL');
PREPARE admon_col_5_stmt FROM @admon_col_5_sql;
EXECUTE admon_col_5_stmt;
DEALLOCATE PREPARE admon_col_5_stmt;
SET @admon_col_6_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'endpoint_status');
SET @admon_col_6_sql := IF(@admon_col_6_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN endpoint_status ENUM(\'online\',\'offline\',\'unreachable\',\'unknown\') NOT NULL DEFAULT \'unknown\'');
PREPARE admon_col_6_stmt FROM @admon_col_6_sql;
EXECUTE admon_col_6_stmt;
DEALLOCATE PREPARE admon_col_6_stmt;
SET @admon_col_7_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'endpoint_checked_at');
SET @admon_col_7_sql := IF(@admon_col_7_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN endpoint_checked_at DATETIME(6) NULL');
PREPARE admon_col_7_stmt FROM @admon_col_7_sql;
EXECUTE admon_col_7_stmt;
DEALLOCATE PREPARE admon_col_7_stmt;
SET @admon_col_8_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'last_successful_connectivity_at');
SET @admon_col_8_sql := IF(@admon_col_8_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN last_successful_connectivity_at DATETIME(6) NULL');
PREPARE admon_col_8_stmt FROM @admon_col_8_sql;
EXECUTE admon_col_8_stmt;
DEALLOCATE PREPARE admon_col_8_stmt;
SET @admon_col_9_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'hardware_last_scanned_at');
SET @admon_col_9_sql := IF(@admon_col_9_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN hardware_last_scanned_at DATETIME(6) NULL');
PREPARE admon_col_9_stmt FROM @admon_col_9_sql;
EXECUTE admon_col_9_stmt;
DEALLOCATE PREPARE admon_col_9_stmt;
SET @admon_col_10_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'endpoint_check_reason');
SET @admon_col_10_sql := IF(@admon_col_10_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN endpoint_check_reason VARCHAR(500) NULL');
PREPARE admon_col_10_stmt FROM @admon_col_10_sql;
EXECUTE admon_col_10_stmt;
DEALLOCATE PREPARE admon_col_10_stmt;
SET @admon_col_11_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_sync_error');
SET @admon_col_11_sql := IF(@admon_col_11_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_sync_error VARCHAR(500) NULL');
PREPARE admon_col_11_stmt FROM @admon_col_11_sql;
EXECUTE admon_col_11_stmt;
DEALLOCATE PREPARE admon_col_11_stmt;
SET @admon_col_12_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'inventory_source');
SET @admon_col_12_sql := IF(@admon_col_12_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN inventory_source ENUM(\'manual\',\'active_directory\',\'other\') NOT NULL DEFAULT \'manual\'');
PREPARE admon_col_12_stmt FROM @admon_col_12_sql;
EXECUTE admon_col_12_stmt;
DEALLOCATE PREPARE admon_col_12_stmt;
SET @admon_col_13_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_scope_id');
SET @admon_col_13_sql := IF(@admon_col_13_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_scope_id VARCHAR(1024) NULL');
PREPARE admon_col_13_stmt FROM @admon_col_13_sql;
EXECUTE admon_col_13_stmt;
DEALLOCATE PREPARE admon_col_13_stmt;
SET @admon_col_14_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_last_seen_at');
SET @admon_col_14_sql := IF(@admon_col_14_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_last_seen_at DATETIME(6) NULL');
PREPARE admon_col_14_stmt FROM @admon_col_14_sql;
EXECUTE admon_col_14_stmt;
DEALLOCATE PREPARE admon_col_14_stmt;
SET @admon_col_15_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_missing_since');
SET @admon_col_15_sql := IF(@admon_col_15_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_missing_since DATETIME(6) NULL');
PREPARE admon_col_15_stmt FROM @admon_col_15_sql;
EXECUTE admon_col_15_stmt;
DEALLOCATE PREPARE admon_col_15_stmt;
UPDATE laptops SET inventory_source = 'active_directory' WHERE ad_object_guid IS NOT NULL AND inventory_source = 'manual';
SET @admon_idx1 := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='laptops' AND index_name='idx_laptops_endpoint_status');
SET @admon_idx1_sql := IF(@admon_idx1>0,'SELECT 1','CREATE INDEX idx_laptops_endpoint_status ON laptops (inventory_source, endpoint_status)');
PREPARE admon_idx1_stmt FROM @admon_idx1_sql; EXECUTE admon_idx1_stmt; DEALLOCATE PREPARE admon_idx1_stmt;
SET @admon_idx2 := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='laptops' AND index_name='idx_laptops_ad_scope_last_seen');
SET @admon_idx2_sql := IF(@admon_idx2>0,'SELECT 1','CREATE INDEX idx_laptops_ad_scope_last_seen ON laptops (inventory_source, ad_scope_id, ad_last_seen_at)');
PREPARE admon_idx2_stmt FROM @admon_idx2_sql; EXECUTE admon_idx2_stmt; DEALLOCATE PREPARE admon_idx2_stmt;

CREATE TABLE IF NOT EXISTS ad_sync_runs (
    run_id CHAR(36) PRIMARY KEY,
    scope_id VARCHAR(1024) NOT NULL,
    status ENUM('running','successful','partial','failed') NOT NULL DEFAULT 'running',
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    received_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    summary JSON NULL,
    INDEX idx_ad_sync_runs_started (started_at),
    INDEX idx_ad_sync_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_sync_run_devices (
    run_id CHAR(36) NOT NULL,
    ad_object_guid CHAR(36) NOT NULL,
    PRIMARY KEY (run_id, ad_object_guid),
    INDEX idx_ad_sync_run_devices_guid (ad_object_guid),
    CONSTRAINT fk_ad_sync_run_device_run FOREIGN KEY (run_id) REFERENCES ad_sync_runs(run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_sync_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    requested_by INT NULL,
    scope_id VARCHAR(1024) NOT NULL,
    status ENUM('queued','running','successful','partial','failed') NOT NULL DEFAULT 'queued',
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    run_id CHAR(36) NULL,
    summary JSON NULL,
    error_message VARCHAR(500) NULL,
    INDEX idx_ad_sync_requests_queue (status, requested_at),
    CONSTRAINT fk_ad_sync_request_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_sync_agent_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    agent_name VARCHAR(150) NULL,
    last_poll_at DATETIME(6) NULL,
    last_sync_at DATETIME(6) NULL,
    last_error VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO ad_sync_agent_state (id) VALUES (1) ON DUPLICATE KEY UPDATE id = VALUES(id);

USE ira_assets_recovered;

-- Check each column through information_schema so this works on MySQL and MariaDB.
SET @ad_laptop_col_1_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'manufacturer_serial');
SET @ad_laptop_col_1_sql := IF(@ad_laptop_col_1_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN manufacturer_serial VARCHAR(120) NULL AFTER serial_number');
PREPARE ad_laptop_col_1_stmt FROM @ad_laptop_col_1_sql;
EXECUTE ad_laptop_col_1_stmt;
DEALLOCATE PREPARE ad_laptop_col_1_stmt;
SET @ad_laptop_col_2_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_object_guid');
SET @ad_laptop_col_2_sql := IF(@ad_laptop_col_2_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_object_guid CHAR(36) NULL AFTER manufacturer_serial');
PREPARE ad_laptop_col_2_stmt FROM @ad_laptop_col_2_sql;
EXECUTE ad_laptop_col_2_stmt;
DEALLOCATE PREPARE ad_laptop_col_2_stmt;
SET @ad_laptop_col_3_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_computer_name');
SET @ad_laptop_col_3_sql := IF(@ad_laptop_col_3_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_computer_name VARCHAR(63) NULL AFTER ad_object_guid');
PREPARE ad_laptop_col_3_stmt FROM @ad_laptop_col_3_sql;
EXECUTE ad_laptop_col_3_stmt;
DEALLOCATE PREPARE ad_laptop_col_3_stmt;
SET @ad_laptop_col_4_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_distinguished_name');
SET @ad_laptop_col_4_sql := IF(@ad_laptop_col_4_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_distinguished_name VARCHAR(1024) NULL AFTER ad_computer_name');
PREPARE ad_laptop_col_4_stmt FROM @ad_laptop_col_4_sql;
EXECUTE ad_laptop_col_4_stmt;
DEALLOCATE PREPARE ad_laptop_col_4_stmt;
SET @ad_laptop_col_5_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_enabled');
SET @ad_laptop_col_5_sql := IF(@ad_laptop_col_5_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ad_distinguished_name');
PREPARE ad_laptop_col_5_stmt FROM @ad_laptop_col_5_sql;
EXECUTE ad_laptop_col_5_stmt;
DEALLOCATE PREPARE ad_laptop_col_5_stmt;
SET @ad_laptop_col_6_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_operating_system');
SET @ad_laptop_col_6_sql := IF(@ad_laptop_col_6_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_operating_system VARCHAR(255) NULL AFTER ad_enabled');
PREPARE ad_laptop_col_6_stmt FROM @ad_laptop_col_6_sql;
EXECUTE ad_laptop_col_6_stmt;
DEALLOCATE PREPARE ad_laptop_col_6_stmt;
SET @ad_laptop_col_7_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_operating_system_version');
SET @ad_laptop_col_7_sql := IF(@ad_laptop_col_7_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_operating_system_version VARCHAR(100) NULL AFTER ad_operating_system');
PREPARE ad_laptop_col_7_stmt FROM @ad_laptop_col_7_sql;
EXECUTE ad_laptop_col_7_stmt;
DEALLOCATE PREPARE ad_laptop_col_7_stmt;
SET @ad_laptop_col_8_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'ad_last_synced_at');
SET @ad_laptop_col_8_sql := IF(@ad_laptop_col_8_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN ad_last_synced_at DATETIME(6) NULL AFTER ad_operating_system_version');
PREPARE ad_laptop_col_8_stmt FROM @ad_laptop_col_8_sql;
EXECUTE ad_laptop_col_8_stmt;
DEALLOCATE PREPARE ad_laptop_col_8_stmt;
SET @ad_laptop_col_9_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'laptops' AND column_name = 'processor_description');
SET @ad_laptop_col_9_sql := IF(@ad_laptop_col_9_exists > 0, 'SELECT 1', 'ALTER TABLE laptops ADD COLUMN processor_description VARCHAR(255) NULL AFTER processor_tier');
PREPARE ad_laptop_col_9_stmt FROM @ad_laptop_col_9_sql;
EXECUTE ad_laptop_col_9_stmt;
DEALLOCATE PREPARE ad_laptop_col_9_stmt;

-- These nullable integer columns must be able to represent unknown hardware values.
ALTER TABLE laptops
    MODIFY COLUMN ram_gb TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN storage_gb SMALLINT UNSIGNED NULL DEFAULT NULL;

-- Preserve legacy serial values as manufacturer data only when that field is currently empty.
-- Do not invent manufacturer serial numbers. The IRA-LAP value is an internal inventory identifier.
UPDATE laptops
SET manufacturer_serial = NULLIF(serial_number, '')
WHERE manufacturer_serial IS NULL
  AND serial_number NOT LIKE 'IRA-LAP-%'
  AND serial_number NOT LIKE 'IRA-OLD-%';

-- Run only for rows not already assigned their deterministic inventory serial. The temporary
-- namespace avoids unique-key collisions while legacy serials are migrated; later reruns leave
-- every existing IRA-LAP inventory serial (including a collision suffix) untouched.
UPDATE laptops
SET serial_number = CONCAT('IRA-OLD-', LPAD(id, 6, '0'))
WHERE serial_number NOT LIKE 'IRA-LAP-%'
  AND serial_number <> CONCAT('IRA-OLD-', LPAD(id, 6, '0'));

UPDATE laptops
SET serial_number = CONCAT('IRA-LAP-', LPAD(id, 6, '0'))
WHERE serial_number LIKE 'IRA-OLD-%';

-- Add the unique AD identity index only when an equivalent one is absent.
SET @ad_guid_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics s
    WHERE s.table_schema = DATABASE()
      AND s.table_name = 'laptops'
      AND s.column_name = 'ad_object_guid'
      AND s.seq_in_index = 1
      AND s.non_unique = 0
      AND (SELECT COUNT(*) FROM information_schema.statistics s2
           WHERE s2.table_schema = s.table_schema
             AND s2.table_name = s.table_name
             AND s2.index_name = s.index_name) = 1
);
SET @ad_guid_index_sql := IF(
    @ad_guid_unique_exists > 0,
    'SELECT 1',
    'CREATE UNIQUE INDEX uq_laptops_ad_object_guid ON laptops (ad_object_guid)'
);
PREPARE ad_guid_index_stmt FROM @ad_guid_index_sql;
EXECUTE ad_guid_index_stmt;
DEALLOCATE PREPARE ad_guid_index_stmt;

CREATE TABLE IF NOT EXISTS ad_sync_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_ip VARCHAR(45) NOT NULL,
    received_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    summary JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_sync_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repair an older/partial event table by adding its sequence key when absent.
SET @ad_events_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'id'
);
SET @ad_events_primary_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND constraint_type = 'PRIMARY KEY'
);
SET @ad_events_id_sql := IF(
    @ad_events_id_exists > 0,
    'SELECT 1',
    IF(@ad_events_primary_exists > 0,
       'ALTER TABLE ad_sync_events ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT UNIQUE FIRST',
       'ALTER TABLE ad_sync_events ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST')
);
PREPARE ad_events_id_stmt FROM @ad_events_id_sql;
EXECUTE ad_events_id_stmt;
DEALLOCATE PREPARE ad_events_id_stmt;

-- Add absent fields without dropping existing synchronization history.
SET @ad_event_col_1_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'source_ip');
SET @ad_event_col_1_sql := IF(@ad_event_col_1_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN source_ip VARCHAR(45) NOT NULL DEFAULT ''unknown''');
PREPARE ad_event_col_1_stmt FROM @ad_event_col_1_sql;
EXECUTE ad_event_col_1_stmt;
DEALLOCATE PREPARE ad_event_col_1_stmt;
SET @ad_event_col_2_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'received_count');
SET @ad_event_col_2_sql := IF(@ad_event_col_2_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN received_count INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE ad_event_col_2_stmt FROM @ad_event_col_2_sql;
EXECUTE ad_event_col_2_stmt;
DEALLOCATE PREPARE ad_event_col_2_stmt;
SET @ad_event_col_3_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'created_count');
SET @ad_event_col_3_sql := IF(@ad_event_col_3_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN created_count INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE ad_event_col_3_stmt FROM @ad_event_col_3_sql;
EXECUTE ad_event_col_3_stmt;
DEALLOCATE PREPARE ad_event_col_3_stmt;
SET @ad_event_col_4_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'updated_count');
SET @ad_event_col_4_sql := IF(@ad_event_col_4_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN updated_count INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE ad_event_col_4_stmt FROM @ad_event_col_4_sql;
EXECUTE ad_event_col_4_stmt;
DEALLOCATE PREPARE ad_event_col_4_stmt;
SET @ad_event_col_5_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'failed_count');
SET @ad_event_col_5_sql := IF(@ad_event_col_5_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN failed_count INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE ad_event_col_5_stmt FROM @ad_event_col_5_sql;
EXECUTE ad_event_col_5_stmt;
DEALLOCATE PREPARE ad_event_col_5_stmt;
SET @ad_event_col_6_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'summary');
SET @ad_event_col_6_sql := IF(@ad_event_col_6_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN summary JSON NULL');
PREPARE ad_event_col_6_stmt FROM @ad_event_col_6_sql;
EXECUTE ad_event_col_6_stmt;
DEALLOCATE PREPARE ad_event_col_6_stmt;
SET @ad_event_col_7_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events' AND column_name = 'created_at');
SET @ad_event_col_7_sql := IF(@ad_event_col_7_exists > 0, 'SELECT 1', 'ALTER TABLE ad_sync_events ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
PREPARE ad_event_col_7_stmt FROM @ad_event_col_7_sql;
EXECUTE ad_event_col_7_stmt;
DEALLOCATE PREPARE ad_event_col_7_stmt;
UPDATE ad_sync_events
SET summary = CASE
    WHEN summary IS NULL THEN JSON_OBJECT()
    WHEN JSON_VALID(summary) = 0 THEN JSON_OBJECT('legacy_summary', CAST(summary AS CHAR))
    ELSE summary
END
WHERE summary IS NULL OR JSON_VALID(summary) = 0;
ALTER TABLE ad_sync_events MODIFY COLUMN summary JSON NOT NULL;

SET @ad_events_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ad_sync_events'
      AND column_name = 'created_at' AND seq_in_index = 1
);
SET @ad_events_index_sql := IF(
    @ad_events_index_exists > 0,
    'SELECT 1',
    'CREATE INDEX idx_ad_sync_events_created_at ON ad_sync_events (created_at)'
);
PREPARE ad_events_index_stmt FROM @ad_events_index_sql;
EXECUTE ad_events_index_stmt;
DEALLOCATE PREPARE ad_events_index_stmt;

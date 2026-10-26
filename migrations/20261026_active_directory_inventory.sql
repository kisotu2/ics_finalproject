USE ira_assets_recovered;

ALTER TABLE laptops
    ADD COLUMN manufacturer_serial VARCHAR(120) NULL AFTER serial_number,
    ADD COLUMN ad_object_guid CHAR(36) NULL AFTER manufacturer_serial,
    ADD COLUMN ad_computer_name VARCHAR(63) NULL AFTER ad_object_guid,
    ADD COLUMN ad_distinguished_name VARCHAR(1024) NULL AFTER ad_computer_name,
    ADD COLUMN ad_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ad_distinguished_name,
    ADD COLUMN ad_operating_system VARCHAR(255) NULL AFTER ad_enabled,
    ADD COLUMN ad_operating_system_version VARCHAR(100) NULL AFTER ad_operating_system,
    ADD COLUMN ad_last_synced_at DATETIME(6) NULL AFTER ad_operating_system_version,
    ADD COLUMN processor_description VARCHAR(255) NULL AFTER processor_tier;

ALTER TABLE laptops
    MODIFY COLUMN ram_gb TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN storage_gb SMALLINT UNSIGNED NULL DEFAULT NULL;

UPDATE laptops
SET manufacturer_serial = NULLIF(serial_number, '')
WHERE manufacturer_serial IS NULL
  AND serial_number NOT LIKE 'IRA-LAP-%';

UPDATE laptops
SET serial_number = CONCAT('IRA-OLD-', LPAD(id, 6, '0'));

UPDATE laptops
SET serial_number = CONCAT('IRA-LAP-', LPAD(id, 6, '0'));

CREATE UNIQUE INDEX uq_laptops_ad_object_guid ON laptops (ad_object_guid);

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

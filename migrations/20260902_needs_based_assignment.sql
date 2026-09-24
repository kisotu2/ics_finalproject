USE ira_assets;

ALTER TABLE users ADD COLUMN IF NOT EXISTS organisation_rank VARCHAR(100) NULL AFTER department;
ALTER TABLE laptops ADD COLUMN IF NOT EXISTS processor_tier ENUM('Standard','Performance','Workstation') NOT NULL DEFAULT 'Standard' AFTER department;
ALTER TABLE laptops ADD COLUMN IF NOT EXISTS ram_gb TINYINT UNSIGNED NOT NULL DEFAULT 8 AFTER processor_tier;
ALTER TABLE laptops ADD COLUMN IF NOT EXISTS storage_gb SMALLINT UNSIGNED NOT NULL DEFAULT 256 AFTER ram_gb;

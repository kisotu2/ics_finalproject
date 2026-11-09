USE ira_assets_recovered;

ALTER TABLE laptops
    ADD COLUMN weight_kg DECIMAL(5,2) NULL AFTER storage_gb,
    ADD COLUMN screen_size_inches DECIMAL(4,1) NULL AFTER weight_kg;

CREATE TABLE IF NOT EXISTS laptop_asset_tag_pool (
    asset_tag VARCHAR(80) PRIMARY KEY,
    is_assigned TINYINT(1) NOT NULL DEFAULT 0,
    laptop_id INT NULL UNIQUE,
    assigned_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_laptop_asset_tag_pool_available (is_assigned, asset_tag),
    CONSTRAINT fk_laptop_asset_tag_pool_laptop
        FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO laptop_asset_tag_pool (asset_tag)
SELECT CONCAT('IRA-LAP-', LPAD(tag_number, 3, '0'))
FROM (
    SELECT ones.digit + (tens.digit * 10) + (hundreds.digit * 100) + 1 AS tag_number
    FROM (
        SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    ) AS ones
    CROSS JOIN (
        SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    ) AS tens
    CROSS JOIN (
        SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    ) AS hundreds
) AS available_numbers
WHERE tag_number <= 500;

UPDATE laptop_asset_tag_pool AS tag
JOIN laptops AS laptop ON laptop.asset_tag = tag.asset_tag
SET tag.is_assigned = 1,
    tag.laptop_id = laptop.id,
    tag.assigned_at = laptop.created_at;

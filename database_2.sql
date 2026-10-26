
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS software_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    software_id INT NOT NULL,
    user_id INT NULL,
    admin_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_software_history_software (software_id),
    INDEX idx_software_history_date (action_date),
    CONSTRAINT fk_software_history_software FOREIGN KEY (software_id) REFERENCES softwares(id) ON DELETE CASCADE,
    CONSTRAINT fk_software_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_software_history_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approved_areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    radius_meters INT UNSIGNED NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_locations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    accuracy_meters DECIMAL(8,2) NULL,
    captured_at DATETIME NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'browser',
    consent_status ENUM('granted','denied','unknown') NOT NULL DEFAULT 'unknown',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_location_laptop_time (laptop_id, captured_at),
    CONSTRAINT fk_device_location_laptop FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_location_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    location_id BIGINT NULL,
    message TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location_alert_laptop_status (laptop_id, status),
    CONSTRAINT fk_location_alert_laptop FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    CONSTRAINT fk_location_alert_location FOREIGN KEY (location_id) REFERENCES device_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS laptop_accessories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    user_id INT NOT NULL,
    mouse_given TINYINT(1) NOT NULL DEFAULT 0,
    charger_given TINYINT(1) NOT NULL DEFAULT 0,
    bag_given TINYINT(1) NOT NULL DEFAULT 0,
    mouse_returned TINYINT(1) NOT NULL DEFAULT 0,
    charger_returned TINYINT(1) NOT NULL DEFAULT 0,
    bag_returned TINYINT(1) NOT NULL DEFAULT 0,
    return_condition VARCHAR(30) NULL,
    returned_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_laptop_accessories_laptop_user (laptop_id, user_id),
    CONSTRAINT fk_accessories_laptop FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    CONSTRAINT fk_accessories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS returns (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    user_id INT NOT NULL,
    charger TINYINT(1) NOT NULL DEFAULT 0,
    bag TINYINT(1) NOT NULL DEFAULT 0,
    mouse TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_returns_laptop FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    CONSTRAINT fk_returns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_approvals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    INDEX idx_asset_approval_user_status (user_id, status),
    CONSTRAINT fk_asset_approval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT NOT NULL,
    user_id INT NULL,
    admin_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset_history_asset (asset_id),
    CONSTRAINT fk_asset_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_history_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Optional synthetic demo data is available separately at datasets/demo_seed.sql.
-- Do not import it unless you intentionally want demo accounts and demo asset records.


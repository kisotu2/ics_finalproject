CREATE DATABASE IF NOT EXISTS ira_assets CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ira_assets;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(100) NULL UNIQUE,
    employee_number VARCHAR(100) NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    department VARCHAR(150) NULL,
    organisation_rank VARCHAR(100) NULL,
    directory_source VARCHAR(50) NOT NULL DEFAULT 'manual',
    password VARCHAR(255) NOT NULL,
    role ENUM('user','admin','super_admin') NOT NULL DEFAULT 'user',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    otp_code VARCHAR(16) NULL,
    otp_expiry DATETIME NULL,
    otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS laptops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(80) NOT NULL UNIQUE,
    serial_number VARCHAR(120) NOT NULL UNIQUE,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(120) NOT NULL,
    department VARCHAR(120) NULL,
    processor_tier ENUM('Standard','Performance','Workstation') NOT NULL DEFAULT 'Standard',
    ram_gb TINYINT UNSIGNED NOT NULL DEFAULT 8,
    storage_gb SMALLINT UNSIGNED NOT NULL DEFAULT 256,
    assigned_to INT NULL,
    status ENUM('Available','Assigned','Maintenance','Retired','Disposed') NOT NULL DEFAULT 'Available',
    purchase_date DATE NULL,
    warranty_expiry DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_laptop_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS softwares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    software_name VARCHAR(150) NOT NULL,
    vendor VARCHAR(150) NOT NULL DEFAULT '',
    version VARCHAR(100) NULL,
    license_type ENUM('Perpetual','Subscription','Free','Trial','Other') NOT NULL DEFAULT 'Subscription',
    license_key VARCHAR(255) NULL,
    total_licenses INT UNSIGNED NOT NULL DEFAULT 1,
    purchase_date DATE NULL,
    expiry_date DATE NULL,
    cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    status ENUM('Active','Suspended','Retired') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_software_vendor_version (software_name, vendor, version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS software_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    software_id INT NOT NULL,
    user_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    revoked_by INT NULL,
    notes VARCHAR(500) NULL,
    INDEX idx_assignment_software_active (software_id, revoked_at),
    INDEX idx_assignment_user_active (user_id, revoked_at),
    CONSTRAINT fk_assignment_software FOREIGN KEY (software_id) REFERENCES softwares(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_issuer FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    reported_by INT NULL,
    issue_description TEXT NOT NULL,
    repair_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    repaired_at DATETIME NULL,
    status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_usage_daily (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    usage_date DATE NOT NULL,
    active_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    crash_count INT NOT NULL DEFAULT 0,
    battery_health_percent TINYINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usage_laptop_day (laptop_id, usage_date),
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_risk_predictions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    risk_score DECIMAL(5,4) NOT NULL,
    risk_level ENUM('Low','Medium','High') NOT NULL,
    model_version VARCHAR(80) NOT NULL,
    factors JSON NOT NULL,
    predicted_at DATETIME NOT NULL,
    INDEX idx_risk_laptop_time (laptop_id, predicted_at),
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS laptop_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    user_id INT NULL,
    admin_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_laptop_history_laptop (laptop_id), INDEX idx_laptop_history_date (action_date),
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS software_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    software_id INT NOT NULL,
    user_id INT NULL,
    admin_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_software_history_software (software_id), INDEX idx_software_history_date (action_date),
    FOREIGN KEY (software_id) REFERENCES softwares(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity_type, entity_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

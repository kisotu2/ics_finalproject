USE ira_assets;

-- Run this upgrade once for an existing installation before using Software licences.
ALTER TABLE users ADD COLUMN IF NOT EXISTS otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS vendor VARCHAR(150) NOT NULL DEFAULT '';
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS version VARCHAR(100) NULL;
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS license_type ENUM('Perpetual','Subscription','Free','Trial','Other') NOT NULL DEFAULT 'Subscription';
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS purchase_date DATE NULL;
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS expiry_date DATE NULL;
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS cost DECIMAL(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS notes TEXT NULL;
ALTER TABLE softwares ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

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

CREATE TABLE IF NOT EXISTS software_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    software_id INT NOT NULL,
    user_id INT NULL,
    admin_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_software_history_software (software_id),
    INDEX idx_software_history_date (action_date),
    FOREIGN KEY (software_id) REFERENCES softwares(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

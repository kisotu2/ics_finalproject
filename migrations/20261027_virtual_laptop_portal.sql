USE ira_assets_recovered;

ALTER TABLE laptops
    ADD COLUMN responsible_admin_id INT NULL AFTER assigned_to,
    ADD COLUMN virtual_power_state ENUM('on','off') NOT NULL DEFAULT 'off' AFTER responsible_admin_id,
    ADD CONSTRAINT fk_laptop_responsible_admin
        FOREIGN KEY (responsible_admin_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS laptop_issues (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(24) NOT NULL UNIQUE,
    laptop_id INT NOT NULL,
    reported_by INT NULL,
    assigned_admin_id INT NULL,
    category VARCHAR(60) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL,
    source ENUM('manual','automatic') NOT NULL DEFAULT 'manual',
    status ENUM('pending_admin_review','approved_for_maintenance','in_maintenance','awaiting_information','rejected','duplicate','resolved') NOT NULL DEFAULT 'pending_admin_review',
    is_usable TINYINT(1) NOT NULL DEFAULT 1,
    occurred_at DATETIME NULL,
    duplicate_of BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_laptop_issues_laptop_status (laptop_id, status),
    INDEX idx_laptop_issues_admin_status (assigned_admin_id, status),
    INDEX idx_laptop_issues_reporter (reported_by, created_at),
    CONSTRAINT fk_laptop_issue_laptop FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE,
    CONSTRAINT fk_laptop_issue_reporter FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_laptop_issue_admin FOREIGN KEY (assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_laptop_issue_duplicate FOREIGN KEY (duplicate_of) REFERENCES laptop_issues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS laptop_issue_updates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    issue_id BIGINT NOT NULL,
    actor_id INT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_laptop_issue_updates_issue (issue_id, created_at),
    CONSTRAINT fk_laptop_issue_update_issue FOREIGN KEY (issue_id) REFERENCES laptop_issues(id) ON DELETE CASCADE,
    CONSTRAINT fk_laptop_issue_update_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    issue_id BIGINT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(500) NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_notifications_unread (user_id, read_at, created_at),
    CONSTRAINT fk_user_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_notification_issue FOREIGN KEY (issue_id) REFERENCES laptop_issues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE maintenance_records
    ADD COLUMN source_issue_id BIGINT NULL,
    ADD UNIQUE KEY uq_maintenance_source_issue (source_issue_id),
    ADD CONSTRAINT fk_maintenance_source_issue
        FOREIGN KEY (source_issue_id) REFERENCES laptop_issues(id) ON DELETE SET NULL;

USE ira_assets_recovered;

CREATE TABLE IF NOT EXISTS software_expiry_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    software_id INT NOT NULL,
    expiry_date DATE NOT NULL,
    recipient_email VARCHAR(190) NOT NULL,
    notified_on DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_software_expiry_notification (software_id, expiry_date, recipient_email, notified_on),
    CONSTRAINT fk_software_expiry_notification_software
        FOREIGN KEY (software_id) REFERENCES softwares(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

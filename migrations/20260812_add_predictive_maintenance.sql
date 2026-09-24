USE ira_assets;

CREATE TABLE IF NOT EXISTS device_usage_daily (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT NOT NULL,
    usage_date DATE NOT NULL,
    active_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    crash_count INT NOT NULL DEFAULT 0,
    battery_health_percent TINYINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usage_laptop_day (laptop_id, usage_date),
    FOREIGN KEY (laptop_id) REFERENCES laptops(id) ON DELETE CASCADE
);

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
);

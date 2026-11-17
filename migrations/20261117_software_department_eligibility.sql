USE ira_assets_recovered;

CREATE TABLE IF NOT EXISTS software_department_eligibility (
    software_id INT NOT NULL,
    department VARCHAR(150) NOT NULL,
    PRIMARY KEY (software_id, department),
    INDEX idx_software_department_department (department),
    CONSTRAINT fk_software_department_software
        FOREIGN KEY (software_id) REFERENCES softwares(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO software_department_eligibility (software_id, department)
SELECT DISTINCT sa.software_id, TRIM(u.department)
FROM software_assignments sa
JOIN users u ON u.id = sa.user_id
WHERE u.department IS NOT NULL
  AND TRIM(u.department) <> '';

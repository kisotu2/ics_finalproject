USE ira_assets_recovered;

ALTER TABLE laptops
    ADD COLUMN virtual_power_started_at DATETIME NULL AFTER virtual_power_state;

ALTER TABLE laptop_history
    ADD COLUMN duration_seconds BIGINT UNSIGNED NULL AFTER action_type;

UPDATE laptops
SET virtual_power_started_at = CURRENT_TIMESTAMP
WHERE virtual_power_state = 'on'
  AND virtual_power_started_at IS NULL;

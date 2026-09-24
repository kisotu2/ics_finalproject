<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/backend/ml_prediction.php';

$asset = [
    'repair_count' => 4,
    'open_repair_count' => 1,
    'asset_age_years' => 3.5,
    'warranty_days_remaining' => 120,
    'active_hours_30d' => 500,
    'crash_count_30d' => 8,
    'battery_health_percent' => 65
];

$result = predictMaintenance($asset);

header('Content-Type: application/json');

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);
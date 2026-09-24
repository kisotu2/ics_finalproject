<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
require_once __DIR__ . '/backend/maintenance_risk.php';

$laptopId = 10;

$result = maintenance_risk($laptopId);

header('Content-Type: application/json');

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);
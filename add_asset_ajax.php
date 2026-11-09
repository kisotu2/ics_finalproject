<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['super_admin']);
require_once __DIR__ . '/backend/laptop_inventory.php';
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'message' => '', 'id' => null];
$categoryInput = $_POST['category'] ?? '';
$category = is_string($categoryInput) ? $categoryInput : '';
$tables = ['Laptop' => 'laptops', 'Phone' => 'phones', 'Desktop' => 'desktops'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'POST is required.';
    echo json_encode($response);
    exit;
}

if (!isset($tables[$category])) {
    http_response_code(422);
    $response['message'] = 'Choose a valid asset category.';
    echo json_encode($response);
    exit;
}

$readPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};
$assetTag = $readPostString('asset_tag');
$serial = $readPostString('serial_number');
$brand = $readPostString('brand');
$model = $readPostString('model');
$status = $readPostString('status');
$allowedStatuses = $category === 'Laptop'
    ? ['Available', 'Maintenance', 'Retired', 'Disposed']
    : ['Active', 'Faulty', 'Retired'];

if (
    ($category !== 'Laptop' && ($assetTag === '' || mb_strlen($assetTag, 'UTF-8') > 80))
    || $serial === ''
    || mb_strlen($serial, 'UTF-8') > 120
    || $brand === ''
    || mb_strlen($brand, 'UTF-8') > 100
    || $model === ''
    || mb_strlen($model, 'UTF-8') > 120
    || !in_array($status, $allowedStatuses, true)
) {
    http_response_code(422);
    $response['message'] = 'Provide valid asset details and status.';
    echo json_encode($response);
    exit;
}

$table = $tables[$category];
$transactionStarted = false;
try {
    $conn->begin_transaction();
    $transactionStarted = true;
    if ($category === 'Laptop') {
        $assetTag = next_laptop_asset_tag($conn);
    }

    $check = $conn->prepare("SELECT id FROM $table WHERE asset_tag = ? OR serial_number = ? LIMIT 1 FOR UPDATE");
    $check->bind_param('ss', $assetTag, $serial);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        http_response_code(409);
        $response['message'] = 'An asset with that tag or serial number already exists.';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO $table (asset_tag, serial_number, brand, model, status, assigned_to)
         VALUES (?, ?, ?, ?, ?, NULL)"
    );
    $stmt->bind_param('sssss', $assetTag, $serial, $brand, $model, $status);
    $stmt->execute();
    $response['success'] = true;
    $response['message'] = 'Asset added successfully.';
    $response['id'] = $stmt->insert_id;
    if ($category === 'Laptop') {
        audit($conn, 'laptop_registered', 'laptop', (int) $stmt->insert_id, ['asset_tag' => $assetTag]);
    }
    $stmt->close();
    $conn->commit();
    $transactionStarted = false;
    echo json_encode($response);
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    if ((int) $exception->getCode() === 1062) {
        http_response_code(409);
        $response['message'] = 'An asset with that tag or serial number already exists. Please check the details and try again.';
        echo json_encode($response);
        exit;
    }

    error_log('Asset registration failed: ' . $exception->getMessage());
    http_response_code(500);
    $response['success'] = false;
    $response['id'] = null;
    $response['message'] = 'The asset could not be registered.';
    echo json_encode($response);
}

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_login();
require_once dirname(__DIR__) . '/backend/laptop_issues.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!laptop_portal_schema_ready($conn)) {
    http_response_code(503);
    echo json_encode(['error' => 'The virtual laptop portal migration has not been applied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST is required.']);
    exit;
}

verify_csrf();

$laptopId = filter_var($_POST['laptop_id'] ?? null, FILTER_VALIDATE_INT);
$state = $_POST['state'] ?? '';
$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'super_admin'], true);

if (!$laptopId || !in_array($state, ['on', 'off'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid laptop and virtual power state.']);
    exit;
}

if ($isAdmin) {
    $stmt = $conn->prepare('SELECT id, virtual_power_state FROM laptops WHERE id = ?');
    $stmt->bind_param('i', $laptopId);
} else {
    $stmt = $conn->prepare('SELECT id, virtual_power_state FROM laptops WHERE id = ? AND assigned_to = ?');
    $stmt->bind_param('ii', $laptopId, $user['id']);
}
$stmt->execute();
$laptop = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$laptop) {
    http_response_code(404);
    echo json_encode(['error' => 'Laptop not found or you do not have access.']);
    exit;
}

try {
    $conn->begin_transaction();

    $update = $conn->prepare('UPDATE laptops SET virtual_power_state = ? WHERE id = ?');
    $update->bind_param('si', $state, $laptopId);
    $update->execute();
    $update->close();

    $action = $state === 'on' ? 'Virtual interface powered on' : 'Virtual interface powered off';
    $userId = $user['id'];
    if ($isAdmin) {
        $history = $conn->prepare(
            'INSERT INTO laptop_history (laptop_id, user_id, admin_id, action_type) VALUES (?, ?, ?, ?)'
        );
        $history->bind_param('iiis', $laptopId, $userId, $userId, $action);
    } else {
        $history = $conn->prepare(
            'INSERT INTO laptop_history (laptop_id, user_id, admin_id, action_type) VALUES (?, ?, NULL, ?)'
        );
        $history->bind_param('iis', $laptopId, $userId, $action);
    }
    $history->execute();
    $history->close();

    audit($conn, 'virtual_power_state_changed', 'laptop', $laptopId, [
        'state' => $state,
        'physical_device_affected' => false,
    ]);

    $conn->commit();
    echo json_encode(['state' => $state]);
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Virtual laptop state update failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The virtual device state could not be saved.']);
}

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/backend/laptop_issues.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in to change the virtual display state.']);
    exit;
}

if (!laptop_portal_schema_ready($conn)) {
    http_response_code(503);
    echo json_encode(['error' => 'Apply the laptop portal and virtual power session history migrations before using this feature.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST is required.']);
    exit;
}

$sessionToken = $_SESSION['csrf'] ?? '';
$postedToken = $_POST['csrf'] ?? '';
if (
    !is_string($sessionToken)
    || !is_string($postedToken)
    || $sessionToken === ''
    || $postedToken === ''
    || !hash_equals($sessionToken, $postedToken)
) {
    http_response_code(419);
    echo json_encode(['error' => 'Refresh the page and try again.']);
    exit;
}

$laptopId = filter_var($_POST['laptop_id'] ?? null, FILTER_VALIDATE_INT);
$state = $_POST['state'] ?? '';
$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'super_admin'], true);

if (!$laptopId || !in_array($state, ['on', 'off'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid laptop and virtual power state.']);
    exit;
}

$transactionStarted = false;
try {
    $conn->begin_transaction();
    $transactionStarted = true;

    if ($isAdmin) {
        $stmt = $conn->prepare(
            'SELECT id, virtual_power_state, virtual_power_started_at,
                    IF(virtual_power_state = "on" AND virtual_power_started_at IS NOT NULL,
                       GREATEST(TIMESTAMPDIFF(SECOND, virtual_power_started_at, NOW()), 0), 0) AS elapsed_seconds
             FROM laptops WHERE id = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $laptopId);
    } else {
        $stmt = $conn->prepare(
            'SELECT id, virtual_power_state, virtual_power_started_at,
                    IF(virtual_power_state = "on" AND virtual_power_started_at IS NOT NULL,
                       GREATEST(TIMESTAMPDIFF(SECOND, virtual_power_started_at, NOW()), 0), 0) AS elapsed_seconds
             FROM laptops WHERE id = ? AND assigned_to = ? FOR UPDATE'
        );
        $stmt->bind_param('ii', $laptopId, $user['id']);
    }
    $stmt->execute();
    $laptop = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$laptop) {
        $conn->rollback();
        $transactionStarted = false;
        http_response_code(404);
        echo json_encode(['error' => 'Laptop not found or you do not have access.']);
        exit;
    }

    $elapsedSeconds = (int) $laptop['elapsed_seconds'];
    $transitioned = $laptop['virtual_power_state'] !== $state;
    if ($transitioned) {
        if ($state === 'on') {
            $update = $conn->prepare(
                'UPDATE laptops
                 SET virtual_power_state = "on", virtual_power_started_at = NOW()
                 WHERE id = ?'
            );
            $update->bind_param('i', $laptopId);
            $elapsedSeconds = 0;
        } else {
            $update = $conn->prepare(
                'UPDATE laptops
                 SET virtual_power_state = "off", virtual_power_started_at = NULL
                 WHERE id = ?'
            );
            $update->bind_param('i', $laptopId);
        }
        $update->execute();
        $update->close();

        $action = $state === 'on' ? 'Virtual interface powered on' : 'Virtual interface powered off';
        $durationSeconds = $state === 'off' ? $elapsedSeconds : null;
        if ($isAdmin) {
            $history = $conn->prepare(
                'INSERT INTO laptop_history
                    (laptop_id, user_id, admin_id, action_type, duration_seconds)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $history->bind_param('iiisi', $laptopId, $user['id'], $user['id'], $action, $durationSeconds);
        } else {
            $history = $conn->prepare(
                'INSERT INTO laptop_history
                    (laptop_id, user_id, admin_id, action_type, duration_seconds)
                 VALUES (?, ?, NULL, ?, ?)'
            );
            $history->bind_param('iisi', $laptopId, $user['id'], $action, $durationSeconds);
        }
        $history->execute();
        $history->close();

        audit($conn, 'virtual_power_state_changed', 'laptop', $laptopId, [
            'state' => $state,
            'physical_device_affected' => false,
            'duration_seconds' => $durationSeconds,
        ]);
    }

    if ($laptop['virtual_power_state'] === $state && $state === 'off') {
        $elapsedSeconds = 0;
    }

    $conn->commit();
    $transactionStarted = false;
    echo json_encode([
        'state' => $state,
        'elapsed_seconds' => $elapsedSeconds,
        'transitioned' => $transitioned,
    ]);
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('Virtual laptop state update failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The virtual device state could not be saved.']);
}

<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['super_admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php', true, 302);
    exit;
}
verify_csrf();
$userId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = (string) ($_POST['status'] ?? '');
if (!$userId || !in_array($status, ['active', 'inactive'], true) || $userId === (int) $_SESSION['user_id']) {
    flash('Choose a valid user and status. You cannot deactivate your own account.', 'error');
} else {
    try {
        $conn->begin_transaction();
        $check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role <> 'super_admin' FOR UPDATE");
        $check->bind_param('i', $userId);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$target) {
            throw new RuntimeException('The selected account cannot be changed.');
        }
        if ($status === 'inactive') {
            $assigned = $conn->prepare('SELECT COUNT(*) AS total FROM laptops WHERE assigned_to = ?');
            $assigned->bind_param('i', $userId);
            $assigned->execute();
            $hasAssignedLaptops = (int) $assigned->get_result()->fetch_assoc()['total'] > 0;
            $assigned->close();
            if ($hasAssignedLaptops) {
                throw new RuntimeException('Return the user’s assigned laptop before deactivating this account.');
            }
        }
        $update = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND role <> 'super_admin'");
        $update->bind_param('si', $status, $userId);
        $update->execute();
        $changed = $update->affected_rows === 1;
        $update->close();
        if (!$changed) {
            throw new RuntimeException('The account already has that status or cannot be changed.');
        }
        audit($conn, 'user_status_changed', 'user', $userId, ['status' => $status]);
        $conn->commit();
        flash('User status updated.');
    } catch (Throwable $exception) {
        $conn->rollback();
        flash($exception->getMessage(), 'error');
    }
}
header('Location: users.php');
exit;

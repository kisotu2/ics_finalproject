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
$role = (string) ($_POST['role'] ?? '');
if (!$userId || !in_array($role, ['admin', 'user'], true)) {
    flash('Choose a valid user and role.', 'error');
} else {
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ? AND role <> 'super_admin'");
    $stmt->bind_param('si', $role, $userId);
    $stmt->execute();
    if ($stmt->affected_rows === 1) {
        audit($conn, 'user_role_changed', 'user', $userId, ['role' => $role]);
        flash('User role updated.');
    } else {
        flash('No eligible user was changed.', 'error');
    }
    $stmt->close();
}
header('Location: users.php');
exit;

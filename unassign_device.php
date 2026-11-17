<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

$error = '';
$laptopId = filter_var($_GET['laptop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $laptopId = filter_var($_POST['laptop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $returnStatus = (string) ($_POST['return_status'] ?? '');
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $allowedStatuses = ['Available', 'Maintenance', 'Retired', 'Disposed'];

    if (!$laptopId || !in_array($returnStatus, $allowedStatuses, true) || mb_strlen($notes, 'UTF-8') > 2000) {
        $error = 'Select a valid assigned laptop and return status. Notes must be 2,000 characters or fewer.';
    } elseif ($returnStatus === 'Maintenance' && $notes === '') {
        $error = 'Describe the fault before returning a laptop for maintenance.';
    } else {
        try {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT id, asset_tag, assigned_to FROM laptops WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $laptopId);
            $lock->execute();
            $laptop = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$laptop || $laptop['assigned_to'] === null) {
                throw new RuntimeException('This laptop is no longer assigned and cannot be returned here.');
            }
            $userId = (int) $laptop['assigned_to'];

            $open = $conn->prepare("SELECT COUNT(*) AS total FROM maintenance_records WHERE laptop_id = ? AND status IN ('open','in_progress')");
            $open->bind_param('i', $laptopId);
            $open->execute();
            $hasOpenMaintenance = (int) $open->get_result()->fetch_assoc()['total'] > 0;
            $open->close();
            if ($returnStatus === 'Available' && $hasOpenMaintenance) {
                throw new RuntimeException('Resolve all open maintenance work before returning this laptop to available inventory.');
            }
            if (in_array($returnStatus, ['Retired', 'Disposed'], true) && $hasOpenMaintenance) {
                throw new RuntimeException('Resolve all open maintenance work before retiring or disposing of this laptop.');
            }

            $update = $conn->prepare('UPDATE laptops SET assigned_to = NULL, status = ? WHERE id = ? AND assigned_to = ?');
            $update->bind_param('sii', $returnStatus, $laptopId, $userId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException('The laptop assignment changed before the return could be saved.');
            }
            $update->close();

            if ($returnStatus === 'Maintenance') {
                $description = 'Returned with fault: ' . $notes;
                $adminId = (int) $_SESSION['user_id'];
                $record = $conn->prepare("INSERT INTO maintenance_records (laptop_id, reported_by, issue_description, status) VALUES (?, ?, ?, 'open')");
                $record->bind_param('iis', $laptopId, $adminId, $description);
                $record->execute();
                $record->close();
            }

            $adminId = (int) $_SESSION['user_id'];
            $action = 'Asset returned - ' . $returnStatus;
            $history = $conn->prepare('INSERT INTO laptop_history (laptop_id, user_id, admin_id, action_type) VALUES (?, ?, ?, ?)');
            $history->bind_param('iiis', $laptopId, $userId, $adminId, $action);
            $history->execute();
            $history->close();
            audit($conn, 'asset_returned', 'laptop', $laptopId, ['previous_user_id' => $userId, 'status' => $returnStatus]);

            $conn->commit();
            flash($laptop['asset_tag'] . ' returned successfully. Status: ' . $returnStatus . '.');
            header('Location: unassign_device.php');
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        }
    }
}

$result = $conn->query("SELECT l.id, l.asset_tag, l.brand, l.model, l.status, l.assigned_to, u.full_name FROM laptops l JOIN users u ON u.id = l.assigned_to ORDER BY l.asset_tag");
layout_start('Return laptop');
?>
<section class="hero">
    <div><div class="eyebrow">ASSIGNMENT MANAGEMENT</div><h1>Return a laptop</h1><p>Record the return before assigning another device to the employee.</p></div>
    <a class="button secondary" href="dashboard.php">Back to dashboard</a>
</section>
<?php if ($error !== ''): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="panel">
    <?php if ($result->num_rows === 0): ?>
        <p>No currently assigned laptops need to be returned.</p>
    <?php else: ?>
        <div class="table-wrapper"><table><thead><tr><th>Asset</th><th>Assigned user</th><th>Current status</th><th>Return details</th></tr></thead><tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr><td><strong><?= e($row['asset_tag']) ?></strong><br><?= e($row['brand'] . ' ' . $row['model']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['status']) ?></td><td>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="laptop_id" value="<?= (int) $row['id'] ?>">
                    <label>Returned condition
                        <select name="return_status" required>
                            <option value="Available">Good — Available</option>
                            <option value="Maintenance">Faulty — Send to maintenance</option>
                            <option value="Retired">Retire</option>
                            <option value="Disposed">Dispose</option>
                        </select>
                    </label>
                    <label>Notes <span class="muted">(required for maintenance)</span>
                        <input name="notes" maxlength="2000" value="<?= $laptopId === (int) $row['id'] ? e((string) ($_POST['notes'] ?? '')) : '' ?>">
                    </label>
                    <button type="submit">Record return</button>
                </form>
            </td></tr>
        <?php endwhile; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>

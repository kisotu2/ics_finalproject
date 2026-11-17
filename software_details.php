<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
require_once __DIR__ . '/backend/asset_dates.php';
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) { header('Location: software_dashboard.php'); exit; }

$eligibilityTable = $conn->query(
    "SELECT COUNT(*) AS total
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'software_department_eligibility'"
)->fetch_assoc();
if ((int) ($eligibilityTable['total'] ?? 0) !== 1) {
    layout_start('Software details');
    ?>
    <section class="panel">
        <h1>Department eligibility upgrade required</h1>
        <p>Apply <code>migrations/20261117_software_department_eligibility.sql</code> before configuring department access for this software.</p>
        <a class="button secondary" href="software_dashboard.php">Back to inventory</a>
    </section>
    <?php
    layout_end();
    exit;
}
$departmentResult = $conn->query(
    "SELECT DISTINCT TRIM(department) AS department
     FROM users
     WHERE role = 'user' AND department IS NOT NULL AND TRIM(department) <> ''
     ORDER BY department"
);
$departmentOptions = $departmentResult->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_departments') {
        $postedDepartments = $_POST['eligible_departments'] ?? [];
        $eligibleDepartments = is_array($postedDepartments)
            ? array_values(array_unique(array_filter(
                array_map(static fn ($department): string => is_string($department) ? trim($department) : '', $postedDepartments),
                static fn (string $department): bool => $department !== ''
            )))
            : [];
        $allowedDepartments = array_column($departmentOptions, 'department');
        if (array_diff($eligibleDepartments, $allowedDepartments)) {
            flash('Choose only departments with registered users.', 'error');
        } else {
            try {
                $conn->begin_transaction();
                $remove = $conn->prepare('DELETE FROM software_department_eligibility WHERE software_id = ?');
                $remove->bind_param('i', $id);
                $remove->execute();
                $save = $conn->prepare(
                    'INSERT INTO software_department_eligibility (software_id, department) VALUES (?, ?)'
                );
                foreach ($eligibleDepartments as $department) {
                    $save->bind_param('is', $id, $department);
                    $save->execute();
                }
                audit($conn, 'software_departments_updated', 'software', $id, [
                    'eligible_departments' => $eligibleDepartments,
                ]);
                $conn->commit();
                flash('Software department eligibility updated.');
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Software department update failed: ' . $exception->getMessage());
                flash('Software department eligibility could not be updated.', 'error');
            }
        }
        header('Location: software_details.php?id=' . $id);
        exit;
    }

    if ($action === 'update_license') {
        $expiryInput = $_POST['expiry_date'] ?? '';
        $status = $_POST['status'] ?? '';
        if (!is_string($expiryInput) || !is_string($status) || !in_array($status, ['Active', 'Suspended', 'Retired'], true)) {
            flash('Provide a valid licence expiry date and status.', 'error');
            header('Location: software_details.php?id=' . $id);
            exit;
        }

        try {
            $expiryDate = validate_calendar_date($expiryInput, 'Expiry date');
            $update = $conn->prepare('UPDATE softwares SET expiry_date = ?, status = ? WHERE id = ?');
            $update->bind_param('ssi', $expiryDate, $status, $id);
            $update->execute();
            $update->close();
            audit($conn, 'software_expiry_updated', 'software', $id, [
                'expiry_date' => $expiryDate,
                'status' => $status,
            ]);
            flash('Licence expiry and status updated.');
        } catch (InvalidArgumentException $exception) {
            flash($exception->getMessage(), 'error');
        }
        header('Location: software_details.php?id=' . $id);
        exit;
    }

    if ($action !== 'revoke') {
        flash('Unknown software licence action.', 'error');
        header('Location: software_details.php?id=' . $id);
        exit;
    }

    $assignmentId = (int) ($_POST['assignment_id'] ?? 0); $adminId = (int) $_SESSION['user_id'];
    $assignment = $conn->prepare('SELECT software_id, user_id FROM software_assignments WHERE id=? AND software_id=? AND revoked_at IS NULL');
    $assignment->bind_param('ii', $assignmentId, $id); $assignment->execute(); $row = $assignment->get_result()->fetch_assoc();
    if (!$row) { flash('That assignment is no longer active.', 'error'); }
    else { $stmt = $conn->prepare('UPDATE software_assignments SET revoked_at=NOW(), revoked_by=? WHERE id=? AND revoked_at IS NULL'); $stmt->bind_param('ii', $adminId, $assignmentId); $stmt->execute();
        $history = $conn->prepare("INSERT INTO software_history (software_id,user_id,admin_id,action_type) VALUES (?,?,?,'Licence revoked')"); $history->bind_param('iii', $id, $row['user_id'], $adminId); $history->execute(); audit($conn, 'software_revoked', 'software', $id, ['assignment_id' => $assignmentId]); flash('Licence assignment revoked.'); }
    header('Location: software_details.php?id=' . $id); exit;
}
$stmt = $conn->prepare('SELECT s.*, COUNT(sa.id) used_licenses FROM softwares s LEFT JOIN software_assignments sa ON sa.software_id=s.id AND sa.revoked_at IS NULL WHERE s.id=? GROUP BY s.id'); $stmt->bind_param('i', $id); $stmt->execute(); $software = $stmt->get_result()->fetch_assoc();
if (!$software) { http_response_code(404); exit('Software record not found.'); }
$eligibleStmt = $conn->prepare('SELECT department FROM software_department_eligibility WHERE software_id = ? ORDER BY department');
$eligibleStmt->bind_param('i', $id);
$eligibleStmt->execute();
$eligibleDepartments = array_column($eligibleStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'department');
$eligibleStmt->close();
$assignments = $conn->prepare('SELECT sa.id, sa.assigned_date, u.full_name, u.email, issuer.full_name AS assigned_by_name FROM software_assignments sa JOIN users u ON u.id=sa.user_id LEFT JOIN users issuer ON issuer.id=sa.assigned_by WHERE sa.software_id=? AND sa.revoked_at IS NULL ORDER BY u.full_name'); $assignments->bind_param('i', $id); $assignments->execute(); $assignments = $assignments->get_result();
$available = max(0, (int) $software['total_licenses'] - (int) $software['used_licenses']);
layout_start('Software details');
?>
<section class="hero"><div><div class="eyebrow">SOFTWARE LICENCE</div><h1><?= e($software['software_name']) ?></h1><p><?= e(trim($software['vendor'] . ' ' . ($software['version'] ?? ''))) ?: 'Software inventory record' ?></p></div><a class="button secondary" href="software_dashboard.php">Back to inventory</a></section>
<section class="grid"><article class="card metric"><b><?= e((string) $software['total_licenses']) ?></b><span>Total seats</span></article><article class="card metric"><b><?= e((string) $software['used_licenses']) ?></b><span>Assigned seats</span></article><article class="card metric"><b><?= e((string) $available) ?></b><span>Available seats</span></article><article class="card metric"><b><?= e($software['expiry_date'] ?: 'N/A') ?></b><span>Licence expiry</span></article></section>
<section class="split" style="margin-top:24px"><div class="panel"><h2>Licence information</h2><p><strong>Type:</strong> <?= e($software['license_type']) ?></p><p><strong>Status:</strong> <?= e($software['status']) ?></p><p><strong>Purchase date:</strong> <?= e($software['purchase_date'] ?: 'Not recorded') ?></p><p><strong>Cost:</strong> <?= e(number_format((float) $software['cost'], 2)) ?></p><hr><h3>Update renewal details</h3><p class="muted">Expiry reminders go to active administrators daily from seven days before expiry until this record is renewed or deactivated.</p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_license"><label>Expiry date<input type="date" name="expiry_date" value="<?= e($software['expiry_date'] ?? '') ?>"></label><label>Status<select name="status"><?php foreach (['Active', 'Suspended', 'Retired'] as $status): ?><option value="<?= e($status) ?>" <?= $software['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button type="submit">Update licence</button></form></div><div class="panel"><h2>Notes</h2><p class="muted"><?= e($software['notes'] ?: 'No notes recorded for this software.') ?></p></div></section>
<section class="panel" style="margin-top:24px"><div class="panel-header"><div><h2>Eligible departments</h2><span>Only users in these departments can receive this licence.</span></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_departments"><fieldset style="grid-column:1/-1"><legend>Departments</legend><?php if ($departmentOptions): ?><?php foreach ($departmentOptions as $department): ?><label style="display:inline-flex;align-items:center;gap:8px;margin:0 16px 8px 0"><input type="checkbox" name="eligible_departments[]" value="<?= e($department['department']) ?>" <?= in_array($department['department'], $eligibleDepartments, true) ? 'checked' : '' ?>><?= e($department['department']) ?></label><?php endforeach; ?><?php else: ?><p class="muted">No user departments are configured yet.</p><?php endif; ?></fieldset><button type="submit">Save eligible departments</button></form></section>
<section class="panel" style="margin-top:24px"><div class="panel-header"><div><h2>Assigned users</h2><span>Revoke access when a licence is no longer required.</span></div></div><div class="table-wrapper"><table><thead><tr><th>User</th><th>Assigned on</th><th>Assigned by</th><th></th></tr></thead><tbody><?php while ($assignment = $assignments->fetch_assoc()): ?><tr><td><strong><?= e($assignment['full_name']) ?></strong><br><small><?= e($assignment['email']) ?></small></td><td><?= e($assignment['assigned_date']) ?></td><td><?= e($assignment['assigned_by_name'] ?: 'System') ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="assignment_id" value="<?= $assignment['id'] ?>"><button class="danger" type="submit">Revoke</button></form></td></tr><?php endwhile; ?></tbody></table></div></section>
<?php layout_end();

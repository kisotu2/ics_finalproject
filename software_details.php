<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) { header('Location: software_dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
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
$assignments = $conn->prepare('SELECT sa.id, sa.assigned_date, u.full_name, u.email, issuer.full_name AS assigned_by_name FROM software_assignments sa JOIN users u ON u.id=sa.user_id LEFT JOIN users issuer ON issuer.id=sa.assigned_by WHERE sa.software_id=? AND sa.revoked_at IS NULL ORDER BY u.full_name'); $assignments->bind_param('i', $id); $assignments->execute(); $assignments = $assignments->get_result();
$available = max(0, (int) $software['total_licenses'] - (int) $software['used_licenses']);
layout_start('Software details');
?>
<section class="hero"><div><div class="eyebrow">SOFTWARE LICENCE</div><h1><?= e($software['software_name']) ?></h1><p><?= e(trim($software['vendor'] . ' ' . ($software['version'] ?? ''))) ?: 'Software inventory record' ?></p></div><a class="button secondary" href="software_dashboard.php">Back to inventory</a></section>
<section class="grid"><article class="card metric"><b><?= e((string) $software['total_licenses']) ?></b><span>Total seats</span></article><article class="card metric"><b><?= e((string) $software['used_licenses']) ?></b><span>Assigned seats</span></article><article class="card metric"><b><?= e((string) $available) ?></b><span>Available seats</span></article><article class="card metric"><b><?= e($software['expiry_date'] ?: 'N/A') ?></b><span>Licence expiry</span></article></section>
<section class="split" style="margin-top:24px"><div class="panel"><h2>Licence information</h2><p><strong>Type:</strong> <?= e($software['license_type']) ?></p><p><strong>Status:</strong> <?= e($software['status']) ?></p><p><strong>Purchase date:</strong> <?= e($software['purchase_date'] ?: 'Not recorded') ?></p><p><strong>Cost:</strong> <?= e(number_format((float) $software['cost'], 2)) ?></p></div><div class="panel"><h2>Notes</h2><p class="muted"><?= e($software['notes'] ?: 'No notes recorded for this software.') ?></p></div></section>
<section class="panel" style="margin-top:24px"><div class="panel-header"><div><h2>Assigned users</h2><span>Revoke access when a licence is no longer required.</span></div></div><div class="table-wrapper"><table><thead><tr><th>User</th><th>Assigned on</th><th>Assigned by</th><th></th></tr></thead><tbody><?php while ($assignment = $assignments->fetch_assoc()): ?><tr><td><strong><?= e($assignment['full_name']) ?></strong><br><small><?= e($assignment['email']) ?></small></td><td><?= e($assignment['assigned_date']) ?></td><td><?= e($assignment['assigned_by_name'] ?: 'System') ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="assignment_id" value="<?= $assignment['id'] ?>"><button class="danger" type="submit">Revoke</button></form></td></tr><?php endwhile; ?></tbody></table></div></section>
<?php layout_end();

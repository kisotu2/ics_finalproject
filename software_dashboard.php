<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['software_name'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $version = trim($_POST['version'] ?? '') ?: null;
        $type = $_POST['license_type'] ?? 'Subscription';
        $total = max(1, (int) ($_POST['total_licenses'] ?? 1));
        $purchase = $_POST['purchase_date'] ?: null;
        $expiry = $_POST['expiry_date'] ?: null;
        $cost = max(0, (float) ($_POST['cost'] ?? 0));
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $allowed = ['Perpetual', 'Subscription', 'Free', 'Trial', 'Other'];
        if ($name === '' || !in_array($type, $allowed, true)) {
            flash('Enter a software name and a valid licence type.', 'error');
        } else {
            try {
                $stmt = $conn->prepare('INSERT INTO softwares (software_name,vendor,version,license_type,total_licenses,purchase_date,expiry_date,cost,notes) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('ssssissds', $name, $vendor, $version, $type, $total, $purchase, $expiry, $cost, $notes);
                $stmt->execute();
                audit($conn, 'software_created', 'software', $conn->insert_id, ['name' => $name]);
                flash('Software licence record created.');
            } catch (mysqli_sql_exception $exception) { flash('A matching software, vendor, and version record already exists.', 'error'); }
        }
    }
    if ($action === 'assign') {
        $softwareId = (int) ($_POST['software_id'] ?? 0); $userId = (int) ($_POST['user_id'] ?? 0); $adminId = (int) $_SESSION['user_id'];
        $conn->begin_transaction();
        try {
            $check = $conn->prepare('SELECT s.id FROM softwares s WHERE s.id=? AND s.status="Active" AND (s.expiry_date IS NULL OR s.expiry_date >= CURDATE()) AND (SELECT COUNT(*) FROM software_assignments sa WHERE sa.software_id=s.id AND sa.revoked_at IS NULL) < s.total_licenses FOR UPDATE');
            $check->bind_param('i', $softwareId); $check->execute();
            if (!$check->get_result()->fetch_assoc()) throw new RuntimeException('No active licence seat is available.');
            $duplicate = $conn->prepare('SELECT id FROM software_assignments WHERE software_id=? AND user_id=? AND revoked_at IS NULL');
            $duplicate->bind_param('ii', $softwareId, $userId); $duplicate->execute();
            if ($duplicate->get_result()->fetch_assoc()) throw new RuntimeException('This user already has an active assignment for this software.');
            $stmt = $conn->prepare('INSERT INTO software_assignments (software_id,user_id,assigned_by) VALUES (?,?,?)');
            $stmt->bind_param('iii', $softwareId, $userId, $adminId); $stmt->execute();
            $history = $conn->prepare("INSERT INTO software_history (software_id,user_id,admin_id,action_type) VALUES (?,?,?,'Licence assigned')");
            $history->bind_param('iii', $softwareId, $userId, $adminId); $history->execute();
            $conn->commit(); audit($conn, 'software_assigned', 'software', $softwareId, ['user_id' => $userId]); flash('Licence assigned.');
        } catch (Throwable $exception) { $conn->rollback(); flash($exception->getMessage(), 'error'); }
    }
    header('Location: software_dashboard.php'); exit;
}

$today = date('Y-m-d');
$summary = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(status='Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())),0) active, COALESCE(SUM(expiry_date < CURDATE()),0) expired, COALESCE(SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)),0) expiring FROM softwares")->fetch_assoc();
$softwares = $conn->query("SELECT s.*, COUNT(sa.id) used_licenses FROM softwares s LEFT JOIN software_assignments sa ON sa.software_id=s.id AND sa.revoked_at IS NULL GROUP BY s.id ORDER BY s.software_name, s.vendor");
$users = $conn->query("SELECT id, full_name, email FROM users WHERE status='active' AND role='user' ORDER BY full_name");
layout_start('Software licences');
?>
<section class="hero"><div><div class="eyebrow">LICENCE MANAGEMENT</div><h1>Software inventory</h1><p>Track subscriptions, licence capacity, renewals, and user access from one workspace.</p></div><a class="button secondary" href="software_reports.php">Open reports</a></section>
<section class="grid"><?php foreach (['total' => 'Software products', 'active' => 'Active licences', 'expiring' => 'Expiring in 30 days', 'expired' => 'Expired licences'] as $key => $label): ?><article class="card metric"><b><?= e((string) $summary[$key]) ?></b><span><?= e($label) ?></span></article><?php endforeach; ?></section>
<section class="split" style="margin-top:24px"><div class="panel"><div class="panel-header"><div><h2>Add software</h2><span>Create an inventory record and licence pool.</span></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="create"><label>Software name<input name="software_name" required></label><label>Vendor<input name="vendor"></label><label>Version<input name="version"></label><label>Licence type<select name="license_type"><option>Subscription</option><option>Perpetual</option><option>Free</option><option>Trial</option><option>Other</option></select></label><label>Licence seats<input type="number" name="total_licenses" min="1" value="1" required></label><label>Purchase date<input type="date" name="purchase_date"></label><label>Expiry date<input type="date" name="expiry_date"></label><label>Cost<input type="number" name="cost" min="0" step="0.01" value="0"></label><label style="grid-column:1/-1">Notes<textarea name="notes" rows="2"></textarea></label><button type="submit">Add software</button></form></div>
<div class="panel"><div class="panel-header"><div><h2>Assign licence</h2><span>Only active, unexpired products with free seats can be assigned.</span></div></div><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="assign"><label>Software<select name="software_id" required><option value="">Choose software</option><?php $softwares->data_seek(0); while ($software = $softwares->fetch_assoc()): $available = (int) $software['total_licenses'] - (int) $software['used_licenses']; if ($software['status'] === 'Active' && $available > 0 && (!$software['expiry_date'] || $software['expiry_date'] >= $today)): ?><option value="<?= $software['id'] ?>"><?= e($software['software_name'] . ($software['version'] ? ' ' . $software['version'] : '') . ' (' . $available . ' free)') ?></option><?php endif; endwhile; ?></select></label><label>User<select name="user_id" required><option value="">Choose user</option><?php while ($user = $users->fetch_assoc()): ?><option value="<?= $user['id'] ?>"><?= e($user['full_name'] . ' - ' . $user['email']) ?></option><?php endwhile; ?></select></label><button type="submit">Assign licence</button></form></div></section>
<section class="panel" style="margin-top:24px"><div class="panel-header"><div><h2>Licence register</h2><span>Current capacity and renewal state.</span></div></div><div class="table-wrapper"><table><thead><tr><th>Software</th><th>Type</th><th>Seats</th><th>Expiry</th><th>Status</th><th></th></tr></thead><tbody><?php $softwares->data_seek(0); while ($software = $softwares->fetch_assoc()): $available = (int) $software['total_licenses'] - (int) $software['used_licenses']; $expired = $software['expiry_date'] && $software['expiry_date'] < $today; ?><tr><td><strong><?= e($software['software_name']) ?></strong><br><small><?= e(trim($software['vendor'] . ' ' . ($software['version'] ?? ''))) ?></small></td><td><?= e($software['license_type']) ?></td><td><?= e((string) $software['used_licenses']) ?> used / <?= e((string) $software['total_licenses']) ?><br><small><?= e((string) $available) ?> available</small></td><td><?= e($software['expiry_date'] ?: 'No expiry') ?></td><td><span class="status-badge <?= $expired || $software['status'] !== 'Active' ? 'status-inactive' : 'status-active' ?>"><?= e($expired ? 'Expired' : $software['status']) ?></span></td><td><a class="button secondary" href="software_details.php?id=<?= $software['id'] ?>">Details</a></td></tr><?php endwhile; ?></tbody></table></div></section>
<?php layout_end();

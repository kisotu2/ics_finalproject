<?php
require __DIR__.'/bootstrap.php'; require_once __DIR__.'/backend/laptop_issues.php'; require_login();
$admin=in_array($_SESSION['role'],['admin','super_admin'],true);
$portalReady = laptop_portal_schema_ready($conn);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_login(['admin','super_admin']);
     verify_csrf(); $action=$_POST['action']??'';
    if ($action === 'assign') {
        flash('Use the needs-based laptop assignment page to select a suitable laptop.', 'error');
        header('Location: laptop_assignment.php'); exit;
    }
    if ($action === 'asset') {
        flash('Laptop inventory is registered through Active Directory sync.', 'error');
        header('Location: directory_sync.php');
        exit;
    }
    if ($action === 'responsible_admin') {
        if (!$portalReady) {
            flash('Apply the virtual laptop portal migration before assigning a responsible administrator.', 'error');
            header('Location: dashboard.php');
            exit;
        }
        $asset = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);
        $responsibleAdminInput = trim((string) ($_POST['responsible_admin_id'] ?? ''));
        $responsibleAdmin = $responsibleAdminInput === ''
            ? null
            : filter_var($responsibleAdminInput, FILTER_VALIDATE_INT);
        if (!$asset || $responsibleAdmin === false) {
            flash('Invalid laptop or administrator selection.', 'error');
            header('Location: dashboard.php');
            exit;
        }
        if ($responsibleAdmin !== null) {
            $check = $conn->prepare(
                "SELECT id FROM users
                 WHERE id = ? AND role IN ('admin','super_admin') AND status = 'active'"
            );
            $check->bind_param('i', $responsibleAdmin);
            $check->execute();
            $validAdmin = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$validAdmin) {
                flash('Choose an active administrator.', 'error');
                header('Location: dashboard.php');
                exit;
            }
        }

        if ($responsibleAdmin === null) {
            $updateAdmin = $conn->prepare('UPDATE laptops SET responsible_admin_id = NULL WHERE id = ?');
            $updateAdmin->bind_param('i', $asset);
        } else {
            $updateAdmin = $conn->prepare('UPDATE laptops SET responsible_admin_id = ? WHERE id = ?');
            $updateAdmin->bind_param('ii', $responsibleAdmin, $asset);
        }
        $updateAdmin->execute();
        $updateAdmin->close();
        audit($conn, 'laptop_responsible_admin_changed', 'laptop', $asset, [
            'responsible_admin_id' => $responsibleAdmin,
        ]);
        flash('Responsible administrator updated.');
        header('Location: dashboard.php');
        exit;
    }
    if ($action === 'assign') {

    $asset = (int)$_POST['asset_id'];
    $user  = (int)$_POST['user_id'];

    $s = $conn->prepare("
        UPDATE laptops
        SET assigned_to = ?, status = 'Assigned'
        WHERE id = ? AND status = 'Available'
    ");

    $s->bind_param('ii', $user, $asset);
    $s->execute();

    if ($s->affected_rows) {

        audit(
            $conn,
            'asset_assigned',
            'laptop',
            $asset,
            ['assigned_to' => $user]
        );

        $history = $conn->prepare("
            INSERT INTO laptop_history
            (laptop_id, user_id, admin_id, action_type, action_date)
            VALUES (?, ?, ?, 'Asset Assigned', NOW())
        ");

        $history->bind_param(
            'iii',
            $asset,
            $user,
            $_SESSION['user_id']
        );

        $history->execute();

        flash('Asset assigned.');

    } else {

        flash(
            'Asset must be available to assign.',
            'error'
        );
    }
}
    if ($action === 'status') {

    $asset  = (int)$_POST['asset_id'];
    $status = $_POST['status'];

    $allowedStatuses = [
        'Available',
        'Maintenance',
        'Retired',
        'Disposed'
    ];

    if (in_array($status, $allowedStatuses, true)) {

        // Get current assignment/user before changing status
        $current = $conn->prepare("
            SELECT assigned_to
            FROM laptops
            WHERE id = ?
        ");

        $current->bind_param('i', $asset);
        $current->execute();

        $currentAsset = $current->get_result()->fetch_assoc();

        $assignedUser = $currentAsset['assigned_to'] ?? null;

        $s = $conn->prepare("
            UPDATE laptops
            SET status = ?,
                assigned_to = IF(? = 'Available', NULL, assigned_to)
            WHERE id = ?
        ");

        $s->bind_param(
            'ssi',
            $status,
            $status,
            $asset
        );

        $s->execute();

        audit(
            $conn,
            'asset_status_changed',
            'laptop',
            $asset,
            ['status' => $status]
        );

        $actionType = 'Status Changed to ' . $status;

        $history = $conn->prepare("
            INSERT INTO laptop_history
            (laptop_id, user_id, admin_id, action_type, action_date)
            VALUES (?, ?, ?, ?, NOW())
        ");

        $history->bind_param(
            'iiis',
            $asset,
            $assignedUser,
            $_SESSION['user_id'],
            $actionType
        );

        $history->execute();

        flash('Asset status updated.');
    }
}
    header('Location: dashboard.php');exit;
}
$mine=(int)$_SESSION['user_id'];
if ($admin) {

    $metrics = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Available') AS available,
            SUM(status = 'Assigned') AS assigned,
            SUM(status = 'Maintenance') AS maintenance
        FROM laptops
    ")->fetch_assoc();

    // Get selected filter
    $filter = $_GET['filter'] ?? 'total';

    $allowedFilters = [
        'total',
        'available',
        'assigned',
        'maintenance'
    ];

    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'total';
    }

    // Build filter condition
    $where = '';

    if ($filter === 'available') {
        $where = "WHERE l.status = 'Available'";
    } elseif ($filter === 'assigned') {
        $where = "WHERE l.status = 'Assigned'";
    } elseif ($filter === 'maintenance') {
        $where = "WHERE l.status = 'Maintenance'";
    }

    $assets = $conn->query("
        SELECT
            l.*,
            u.full_name,
            " . ($portalReady ? 'responsible.full_name' : 'NULL') . " AS responsible_admin_name,
            (
                SELECT COUNT(*)
                FROM maintenance_records m
                WHERE m.laptop_id = l.id
                AND m.status != 'resolved'
            ) AS open_repairs
            " . ($portalReady ? ",
            (SELECT COUNT(*) FROM laptop_issues i
             WHERE i.laptop_id = l.id
               AND i.status NOT IN ('rejected','duplicate','resolved')) AS open_issues" : ",
            0 AS open_issues") . "
        FROM laptops l
        LEFT JOIN users u ON u.id = l.assigned_to
        " . ($portalReady ? 'LEFT JOIN users responsible ON responsible.id = l.responsible_admin_id' : '') . "
        $where
        ORDER BY l.created_at DESC
        LIMIT 100
    ");

    $users = $conn->query("
        SELECT id, full_name
        FROM users
        WHERE role = 'user'
        AND status = 'active'
        ORDER BY full_name
    ");
    $adminUsers = $conn->query("
        SELECT id, full_name
        FROM users
        WHERE role IN ('admin','super_admin')
          AND status = 'active'
        ORDER BY full_name
    ");

} else {

    $metrics = [
        'total' => $conn->query("
            SELECT COUNT(*) n
            FROM laptops
            WHERE assigned_to = $mine
        ")->fetch_assoc()['n'],

        'available' => 0,
        'assigned' => 0,
        'maintenance' => 0
    ];

    $s = $conn->prepare("
        SELECT l.*,
               " . ($portalReady
                    ? "(SELECT COUNT(*) FROM laptop_issues i
                        WHERE i.laptop_id = l.id
                          AND i.status NOT IN ('rejected','duplicate','resolved'))"
                    : '0') . " AS open_issues
        FROM laptops l
        WHERE l.assigned_to = ?
    ");

    $s->bind_param('i', $mine);
    $s->execute();

    $assets = $s->get_result();
}
layout_start('Dashboard'); ?><div class="hero">
    <div>
        <h1>Welcome, <?=e($_SESSION['name'])?></h1>
        <p class="muted">
        <?= $admin ? 'Manage inventory, assignments and maintenance from one place.' : 'Your assigned equipment and its current status, all in one place.' ?>
        </p>
            </div>
            <?php if($admin):?>
            <a class="button" href="directory_sync.php">Sync laptop inventory</a>
        <?php else: ?>
            <a class="button" href="my_laptop.php">Open My Laptop</a>
        <?php endif; ?>
                </div>
                <section class="grid">
    <?php foreach([
        'total' => 'Total assets',
        'available' => 'Available',
        'assigned' => 'Assigned',
        'maintenance' => 'In maintenance'
    ] as $k => $label): ?>

        <a
            href="dashboard.php?filter=<?=e($k)?>"
            class="card metric"
            style="text-decoration:none; color:inherit; cursor:pointer;"
        >
            <b><?=e((string)($metrics[$k] ?? 0))?></b>
            <span><?=e($label)?></span>
        </a>

    <?php endforeach; ?>
</section>
<?php if($admin): ?>
    <section class="split" style="margin-top:24px">
        <div class="panel">
            <h2>Inventory and assignment</h2>
            <p class="muted">Refresh device details from directory sync, or find the right laptop for an employee.</p>
            <a class="button" href="directory_sync.php">Directory sync</a>
            <a class="button secondary" href="laptop_assignment.php">Laptop assignment</a>
        </div>
        <div class="panel">
            <h2>Maintenance intelligence</h2>
            <p>Review new employee reports before approving work, then track repair status in maintenance.</p>
            <a class="button" href="laptop_issues.php">Review issues</a>
            <a class="button secondary" href="maintenance.php">Manage maintenance</a>
        </div>
    </section>
<?php endif; ?>
<section class="panel dashboard-register" style="margin-top:24px">
    <div class="panel-header">
        <div>
            <h2><?= $admin ? 'Asset register' : 'My assigned laptops' ?></h2>
            <span><?= $admin ? 'Current inventory and assignment status.' : 'Only devices assigned to your account are listed.' ?></span>
        </div>
        <?php if (!$admin): ?><a class="button secondary" href="my_laptop.php">Open device portal</a><?php endif; ?>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Tag</th><th>Device</th><th>Status</th><th>Assigned to</th><?php if($admin):?><th>Responsible admin</th><th>Risk</th><th>Actions</th><?php else: ?><th>Open issues</th><th>Device portal</th><?php endif;?></tr>
            </thead>
            <tbody>
            <?php $hasAssets = false; while($a=$assets->fetch_assoc()): $hasAssets = true; ?>
                <tr>
                    <td><b><?=e($a['asset_tag'])?></b><br><small>Inventory: <?=e($a['serial_number'])?><br>Manufacturer: <?=e($a['manufacturer_serial']??'Not reported')?></small></td>
                    <td><?=e($a['brand'].' '.$a['model'])?><br><small><?=e($a['department']??'')?></small></td>
                    <td><?=e($a['status'])?></td>
                    <td><?=e($a['full_name']??($_SESSION['name']??'Unassigned'))?></td>
                    <?php if($admin):?>
                        <td>
                            <span><?=e($a['responsible_admin_name'] ?? 'Not assigned')?></span>
                            <?php if ($portalReady): ?>
                            <form method="post" class="responsible-admin-form">
                                <input type="hidden" name="csrf" value="<?=csrf()?>">
                                <input type="hidden" name="action" value="responsible_admin">
                                <input type="hidden" name="asset_id" value="<?=(int)$a['id']?>">
                                <select name="responsible_admin_id" aria-label="Responsible administrator for <?=e($a['asset_tag'])?>">
                                    <option value="">No responsible admin</option>
                                    <?php $adminUsers->data_seek(0); while($adminUser=$adminUsers->fetch_assoc()): ?>
                                        <option value="<?=(int)$adminUser['id']?>" <?=((int)($a['responsible_admin_id']??0)===(int)$adminUser['id'])?'selected':''?>><?=e($adminUser['full_name'])?></option>
                                    <?php endwhile; ?>
                                </select>
                                <button class="secondary" type="submit">Save</button>
                            </form>
                            <?php else: ?>
                                <small class="muted">Apply the portal migration to assign.</small>
                            <?php endif; ?>
                        </td>
                        <td><?=($a['open_repairs']??0)>0?'Needs attention':'Normal'?></td>
                        <td>
                            <?php if($a['status']==='Available'):?>
                                <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="asset_id" value="<?=$a['id']?>"><select name="user_id" required><option value="">Assign to…</option><?php $users->data_seek(0);while($u=$users->fetch_assoc()):?><option value="<?=$u['id']?>"><?=e($u['full_name'])?></option><?php endwhile;?></select><button>Assign</button></form>
                            <?php endif;?>
                            <a class="button secondary" href="my_laptop.php?id=<?=(int)$a['id']?>">View device</a>
                            <form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="status"><input type="hidden" name="asset_id" value="<?=$a['id']?>"><select name="status"><option>Available</option><option>Maintenance</option><option>Retired</option><option>Disposed</option></select><button class="secondary">Update</button></form>
                        </td>
                    <?php else: ?>
                        <td><?= (int) ($a['open_issues'] ?? 0) ?></td>
                        <td><a class="button secondary" href="my_laptop.php?id=<?=(int)$a['id']?>">Open device</a> <a class="button" href="report_laptop_issue.php?laptop_id=<?=(int)$a['id']?>">Report issue</a></td>
                    <?php endif;?>
                </tr>
            <?php endwhile;?>
            <?php if (!$hasAssets): ?><tr><td colspan="<?= $admin ? 7 : 6 ?>" class="empty-table"><?= $admin ? 'No laptops are registered yet.' : 'No laptops are assigned to your account.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php layout_end();

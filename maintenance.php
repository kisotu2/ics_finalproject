<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require __DIR__ . '/bootstrap.php';

require_login(['admin', 'super_admin']);
require_once __DIR__ . '/maintenance_risk.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';
    $asset = (int)($_POST['asset_id'] ?? 0);
    $issue = trim($_POST['issue'] ?? '');
    $cost = (float)($_POST['cost'] ?? 0);

    if ($action === 'usage') {
        $asset = (int) ($_POST['asset_id'] ?? 0);
        $date = $_POST['usage_date'] ?? '';
        $hours = max(0, min(24, (float) ($_POST['active_hours'] ?? 0)));
        $crashes = max(0, (int) ($_POST['crash_count'] ?? 0));
        $battery = $_POST['battery_health_percent'] === '' ? null : max(0, min(100, (int) $_POST['battery_health_percent']));
        if ($asset && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            $stmt = $conn->prepare('INSERT INTO device_usage_daily (laptop_id,usage_date,active_hours,crash_count,battery_health_percent) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE active_hours=VALUES(active_hours),crash_count=VALUES(crash_count),battery_health_percent=VALUES(battery_health_percent)');
            $stmt->bind_param('isdii', $asset, $date, $hours, $crashes, $battery);
            $stmt->execute();
            audit($conn, 'usage_metrics_recorded', 'laptop', $asset, ['usage_date' => $date]);
            flash('Usage data recorded. The risk score has been updated.');
        }
    } elseif ($asset && $issue !== '') {
        $user = (int)$_SESSION['user_id'];

        $stmt = $conn->prepare(
            "INSERT INTO maintenance_records
            (laptop_id, reported_by, issue_description, repair_cost, status)
            VALUES (?, ?, ?, ?, 'open')"
        );

        $stmt->bind_param('iisd', $asset, $user, $issue, $cost);
        $stmt->execute();

        $stmt = $conn->prepare(
            "UPDATE laptops SET status = 'Maintenance' WHERE id = ?"
        );

        $stmt->bind_param('i', $asset);
        $stmt->execute();

        audit(
            $conn,
            'maintenance_reported',
            'maintenance_record',
            $conn->insert_id,
            ['asset_id' => $asset]
        );

        flash('Maintenance record created and asset marked for maintenance.');
    }

    header('Location: maintenance.php');
    exit;
}

$assets = $conn->query("
    SELECT id, asset_tag, brand, model
    FROM laptops
    WHERE status NOT IN ('Retired', 'Disposed')
    ORDER BY asset_tag
");

$risks = $conn->query("
    SELECT
        l.id,
        l.asset_tag,
        l.brand,
        l.model,
        l.status,
        l.warranty_expiry,
        COUNT(m.id) AS repairs,
        COALESCE(SUM(m.status != 'resolved'), 0) AS open_repairs,

        CASE
            WHEN l.warranty_expiry IS NOT NULL
                 AND l.warranty_expiry < CURDATE()
                THEN 'Warranty expired'

            WHEN COALESCE(SUM(m.status != 'resolved'), 0) > 0
                THEN 'Repair required'

            WHEN COUNT(m.id) >= 3
                THEN 'Repeated repairs'

            ELSE 'Normal'
        END AS risk

    FROM laptops l

    LEFT JOIN maintenance_records m
        ON m.laptop_id = l.id

    GROUP BY
        l.id,
        l.asset_tag,
        l.brand,
        l.model,
        l.status,
        l.warranty_expiry

    ORDER BY
        CASE
            WHEN l.warranty_expiry IS NOT NULL
                 AND l.warranty_expiry < CURDATE()
                THEN 1

            WHEN COALESCE(SUM(m.status != 'resolved'), 0) > 0
                THEN 2

            WHEN COUNT(m.id) >= 3
                THEN 3

            ELSE 4
        END,
        repairs DESC
");

layout_start('Maintenance');
?>

<div class="hero">
    <div>
        <h1>Maintenance and lifecycle</h1>
        <p class="muted">
            A logistic-regression model estimates failure risk from repair history,
            warranty and asset age, plus the last 30 days of recorded usage.
        </p>
    </div>
</div>

<section class="split">

    <div class="panel">
        <h2>Report maintenance issue</h2>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >

            <label>
                Asset

                <select name="asset_id" required>
                    <option value="">Choose asset</option>

                    <?php while ($a = $assets->fetch_assoc()): ?>
                        <option value="<?= $a['id'] ?>">
                            <?= e($a['asset_tag'] . ' — ' . $a['brand'] . ' ' . $a['model']) ?>
                        </option>
                    <?php endwhile; ?>

                </select>
            </label>

            <label>
                Issue description
                <textarea name="issue" required></textarea>
            </label>

            <label>
                Estimated repair cost
                <input
                    name="cost"
                    type="number"
                    step="0.01"
                    min="0"
                    value="0"
                >
            </label>

            <button type="submit">
                Create maintenance record
            </button>

        </form>
    </div>


    <div class="panel">
        <h2>Record daily usage</h2>
        <p class="muted">Enter observed daily values until a device-management agent supplies them automatically.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="usage">
        <label>Asset<select name="asset_id" required><option value="">Choose asset</option><?php $assets->data_seek(0); while ($a = $assets->fetch_assoc()): ?><option value="<?= $a['id'] ?>"><?= e($a['asset_tag']) ?></option><?php endwhile; ?></select></label>
        <label>Date<input name="usage_date" type="date" value="<?= date('Y-m-d') ?>" required></label><label>Active hours<input name="active_hours" type="number" min="0" max="24" step="0.1" required></label><label>System crashes<input name="crash_count" type="number" min="0" value="0" required></label><label>Battery health (%)<input name="battery_health_percent" type="number" min="0" max="100"></label><button type="submit">Save usage</button></form>
    </div>

</section>


<section style="margin-top:24px">

    <h2>Asset risk register</h2>

    <table>

        <thead>
            <tr>
                <th>Asset</th>
                <th>Status</th>
                <th>Warranty</th>
                <th>Repair records</th>
                <th>Open repairs</th>
                <th>30-day usage</th>
                <th>Predicted failure risk</th>
            </tr>
        </thead>

        <tbody>

            <?php while ($r = $risks->fetch_assoc()): $prediction = maintenance_risk($r); ?>

                <tr>

                    <td>
                        <b><?= e($r['asset_tag']) ?></b><br>
                        <?= e($r['brand'] . ' ' . $r['model']) ?>
                    </td>

                    <td>
                        <?= e($r['status']) ?>
                    </td>

                    <td>
                        <?= e($r['warranty_expiry'] ?? 'Not recorded') ?>
                    </td>

                    <td>
                        <?= $r['repairs'] ?>
                    </td>

                    <td>
                        <?= $r['open_repairs'] ?>
                    </td>

                <?= e((string)($r['active_hours_30d'] ?? 0)) ?> hrs,
                <?= e((string)($r['crash_count_30d'] ?? 0)) ?> crashes
                    <td class="<?= $prediction['level'] === 'Low' ? '' : 'danger' ?>">
                        <b><?= e($prediction['level']) ?></b> — <?= number_format($prediction['score'] * 100, 1) ?>%<br>
                        <small><?= e($prediction['version']) ?></small>
                    </td>

                </tr>

            <?php endwhile; ?>

        </tbody>

    </table>

</section>

<?php layout_end(); ?>

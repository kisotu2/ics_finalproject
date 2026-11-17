<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
require_once __DIR__ . '/backend/asset_dates.php';

$eligibilityTable = $conn->query(
    "SELECT COUNT(*) AS total
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'software_department_eligibility'"
)->fetch_assoc();
if ((int) ($eligibilityTable['total'] ?? 0) !== 1) {
    layout_start('Software licences');
    ?>
    <section class="panel">
        <h1>Department licence recommendations require an upgrade</h1>
        <p>Back up the database, then apply <code>migrations/20261117_software_department_eligibility.sql</code> to configure software eligibility by department.</p>
        <a class="button secondary" href="dashboard.php">Back to dashboard</a>
    </section>
    <?php
    layout_end();
    exit;
}

$departmentsResult = $conn->query(
    "SELECT DISTINCT TRIM(department) AS department
     FROM users
     WHERE role = 'user' AND status = 'active'
       AND department IS NOT NULL AND TRIM(department) <> ''
     ORDER BY department"
);
$departments = $departmentsResult->fetch_all(MYSQLI_ASSOC);
$departmentNames = array_column($departments, 'department');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $actionInput = $_POST['action'] ?? '';
    $action = is_string($actionInput) ? $actionInput : '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['software_name'] ?? ''));
        $vendor = trim((string) ($_POST['vendor'] ?? ''));
        $version = trim((string) ($_POST['version'] ?? '')) ?: null;
        $type = (string) ($_POST['license_type'] ?? 'Subscription');
        $total = filter_var($_POST['total_licenses'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $costInput = $_POST['cost'] ?? '0';
        $cost = is_numeric($costInput) ? (float) $costInput : -1;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $allowedTypes = ['Perpetual', 'Subscription', 'Free', 'Trial', 'Other'];
        $postedDepartments = $_POST['eligible_departments'] ?? [];
        $eligibleDepartments = is_array($postedDepartments)
            ? array_values(array_unique(array_filter(
                array_map(static fn ($department): string => is_string($department) ? trim($department) : '', $postedDepartments),
                static fn (string $department): bool => $department !== ''
            )))
            : [];
        try {
            $purchase = validate_purchase_date($_POST['purchase_date'] ?? null);
            $expiry = validate_calendar_date($_POST['expiry_date'] ?? null, 'Expiry date');
        } catch (InvalidArgumentException $exception) {
            flash($exception->getMessage(), 'error');
            header('Location: software_dashboard.php');
            exit;
        }

        if (
            $name === '' || mb_strlen($name, 'UTF-8') > 150 ||
            mb_strlen($vendor, 'UTF-8') > 150 ||
            ($version !== null && mb_strlen($version, 'UTF-8') > 100) ||
            !in_array($type, $allowedTypes, true) ||
            $total === false || $cost < 0 ||
            !$eligibleDepartments ||
            array_diff($eligibleDepartments, $departmentNames)
        ) {
            flash('Enter valid software details, at least one licence seat, and one or more active departments.', 'error');
        } else {
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare(
                    'INSERT INTO softwares
                        (software_name, vendor, version, license_type, total_licenses, purchase_date, expiry_date, cost, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssssissds', $name, $vendor, $version, $type, $total, $purchase, $expiry, $cost, $notes);
                $stmt->execute();
                $softwareId = (int) $conn->insert_id;
                $eligibility = $conn->prepare(
                    'INSERT INTO software_department_eligibility (software_id, department) VALUES (?, ?)'
                );
                foreach ($eligibleDepartments as $department) {
                    $eligibility->bind_param('is', $softwareId, $department);
                    $eligibility->execute();
                }
                audit($conn, 'software_created', 'software', $softwareId, [
                    'name' => $name,
                    'eligible_departments' => $eligibleDepartments,
                ]);
                $conn->commit();
                flash('Software licence record created with department eligibility.');
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ((int) $exception->getCode() === 1062) {
                    flash('A matching software, vendor, and version record already exists.', 'error');
                } else {
                    error_log('Software licence creation failed: ' . $exception->getMessage());
                    flash('The software licence record could not be created.', 'error');
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Software licence creation failed: ' . $exception->getMessage());
                flash('The software licence record could not be created.', 'error');
            }
        }
    } elseif ($action === 'assign') {
        $softwareId = filter_var($_POST['software_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $adminId = (int) $_SESSION['user_id'];

        if ($softwareId === false || $userId === false) {
            flash('Choose a valid software licence and user.', 'error');
        } else {
            $conn->begin_transaction();
            try {
                $check = $conn->prepare(
                    'SELECT s.id
                     FROM softwares s
                     WHERE s.id = ? AND s.status = "Active"
                       AND (s.expiry_date IS NULL OR s.expiry_date >= CURDATE())
                       AND (SELECT COUNT(*) FROM software_assignments sa
                            WHERE sa.software_id = s.id AND sa.revoked_at IS NULL) < s.total_licenses
                     FOR UPDATE'
                );
                $check->bind_param('i', $softwareId);
                $check->execute();
                if (!$check->get_result()->fetch_assoc()) {
                    throw new RuntimeException('No active licence seat is available.');
                }
                $recipient = $conn->prepare(
                    "SELECT u.id
                     FROM users u
                     JOIN software_department_eligibility eligible
                       ON eligible.software_id = ? AND eligible.department = TRIM(u.department)
                     WHERE u.id = ? AND u.status = 'active' AND u.role = 'user'
                     LIMIT 1"
                );
                $recipient->bind_param('ii', $softwareId, $userId);
                $recipient->execute();
                if (!$recipient->get_result()->fetch_assoc()) {
                    throw new RuntimeException('This user is not eligible for the selected software department.');
                }
                $duplicate = $conn->prepare(
                    'SELECT id FROM software_assignments
                     WHERE software_id = ? AND user_id = ? AND revoked_at IS NULL'
                );
                $duplicate->bind_param('ii', $softwareId, $userId);
                $duplicate->execute();
                if ($duplicate->get_result()->fetch_assoc()) {
                    throw new RuntimeException('This user already has an active assignment for this software.');
                }
                $stmt = $conn->prepare(
                    'INSERT INTO software_assignments (software_id, user_id, assigned_by) VALUES (?, ?, ?)'
                );
                $stmt->bind_param('iii', $softwareId, $userId, $adminId);
                $stmt->execute();
                $history = $conn->prepare(
                    "INSERT INTO software_history (software_id, user_id, admin_id, action_type)
                     VALUES (?, ?, ?, 'Licence assigned')"
                );
                $history->bind_param('iii', $softwareId, $userId, $adminId);
                $history->execute();
                audit($conn, 'software_assigned', 'software', $softwareId, ['user_id' => $userId]);
                $conn->commit();
                flash('Licence assigned.');
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Software licence assignment failed: ' . $exception->getMessage());
                flash('The licence assignment could not be saved.', 'error');
            } catch (RuntimeException $exception) {
                $conn->rollback();
                flash($exception->getMessage(), 'error');
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Software licence assignment failed: ' . $exception->getMessage());
                flash('The licence assignment could not be saved.', 'error');
            }
        }
    } elseif ($action !== '') {
        flash('Unknown software licence action.', 'error');
    }

    header('Location: software_dashboard.php');
    exit;
}

$today = date('Y-m-d');
$summary = $conn->query(
    "SELECT COUNT(*) total,
            COALESCE(SUM(status = 'Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())), 0) active,
            COALESCE(SUM(expiry_date < CURDATE()), 0) expired,
            COALESCE(SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) expiring
     FROM softwares"
)->fetch_assoc();
$allowedFilters = ['all', 'active', 'expiring', 'expired'];
$filterInput = $_GET['filter'] ?? 'all';
$softwareFilter = is_string($filterInput) ? $filterInput : 'all';
if (!in_array($softwareFilter, $allowedFilters, true)) {
    $softwareFilter = 'all';
}
$filterSql = match ($softwareFilter) {
    'active' => "WHERE s.status = 'Active' AND (s.expiry_date IS NULL OR s.expiry_date >= CURDATE())",
    'expiring' => 'WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)',
    'expired' => 'WHERE s.expiry_date < CURDATE()',
    default => '',
};
$softwares = $conn->query(
    "SELECT s.*, COUNT(sa.id) AS used_licenses
     FROM softwares s
     LEFT JOIN software_assignments sa ON sa.software_id = s.id AND sa.revoked_at IS NULL
     $filterSql
     GROUP BY s.id
     ORDER BY s.software_name, s.vendor"
);
$allSoftwares = $conn->query(
    "SELECT s.id, s.software_name, s.vendor, s.version, s.status, s.expiry_date,
            s.total_licenses, COUNT(sa.id) AS used_licenses
     FROM softwares s
     LEFT JOIN software_assignments sa ON sa.software_id = s.id AND sa.revoked_at IS NULL
     GROUP BY s.id
     ORDER BY s.software_name, s.vendor"
)->fetch_all(MYSQLI_ASSOC);
$assignableSoftwares = array_values(array_filter(
    $allSoftwares,
    static fn (array $software): bool =>
        $software['status'] === 'Active' &&
        (!$software['expiry_date'] || $software['expiry_date'] >= $today) &&
        (int) $software['total_licenses'] > (int) $software['used_licenses']
));
$selectedSoftwareId = filter_var(
    $_GET['software_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$selectedSoftwareId = $selectedSoftwareId === false ? null : $selectedSoftwareId;
$selectedSoftware = null;
$eligibleDepartments = [];
$eligibleUsers = [];
if ($selectedSoftwareId !== null) {
    foreach ($assignableSoftwares as $software) {
        if ((int) $software['id'] === $selectedSoftwareId) {
            $selectedSoftware = $software;
            break;
        }
    }
    if ($selectedSoftware) {
        $eligibleStmt = $conn->prepare(
            'SELECT department
             FROM software_department_eligibility
             WHERE software_id = ?
             ORDER BY department'
        );
        $eligibleStmt->bind_param('i', $selectedSoftwareId);
        $eligibleStmt->execute();
        $eligibleDepartments = $eligibleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $eligibleStmt->close();
        $recipientsStmt = $conn->prepare(
            "SELECT u.id, u.full_name, u.email, TRIM(u.department) AS department
             FROM users u
             JOIN software_department_eligibility eligible
               ON eligible.software_id = ? AND eligible.department = TRIM(u.department)
             WHERE u.status = 'active' AND u.role = 'user'
             ORDER BY u.full_name"
        );
        $recipientsStmt->bind_param('i', $selectedSoftwareId);
        $recipientsStmt->execute();
        $eligibleUsers = $recipientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recipientsStmt->close();
    }
}

$recommendDepartmentInput = $_GET['department'] ?? '';
$recommendDepartment = is_string($recommendDepartmentInput) ? trim($recommendDepartmentInput) : '';
if (!in_array($recommendDepartment, $departmentNames, true)) {
    $recommendDepartment = '';
}
$recommendations = [];
if ($recommendDepartment !== '') {
    $recommendStmt = $conn->prepare(
        "SELECT s.id, s.software_name, s.vendor, s.version, s.license_type,
                s.total_licenses - COUNT(sa.id) AS available_seats
         FROM software_department_eligibility eligible
         JOIN softwares s ON s.id = eligible.software_id
         LEFT JOIN software_assignments sa ON sa.software_id = s.id AND sa.revoked_at IS NULL
         WHERE eligible.department = ? AND s.status = 'Active'
           AND (s.expiry_date IS NULL OR s.expiry_date >= CURDATE())
         GROUP BY s.id
         HAVING available_seats > 0
         ORDER BY s.software_name, s.vendor"
    );
    $recommendStmt->bind_param('s', $recommendDepartment);
    $recommendStmt->execute();
    $recommendations = $recommendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recommendStmt->close();
}

layout_start('Software licences');
?>
<section class="hero">
    <div>
        <div class="eyebrow">LICENCE MANAGEMENT</div>
        <h1>Software inventory</h1>
        <p>Track subscriptions, licence capacity, renewals, and department-specific user access.</p>
    </div>
    <a class="button secondary" href="software_reports.php">Open reports</a>
</section>

<section class="grid">
    <?php foreach ([
        'all' => ['Software products', $summary['total']],
        'active' => ['Active licences', $summary['active']],
        'expiring' => ['Expiring in 30 days', $summary['expiring']],
        'expired' => ['Expired licences', $summary['expired']],
    ] as $key => [$label, $count]): ?>
        <a class="card metric<?= $softwareFilter === $key ? ' is-active' : '' ?>"
           href="software_dashboard.php?filter=<?= e($key) ?>"
           <?= $softwareFilter === $key ? 'aria-current="true"' : '' ?>>
            <b><?= e((string) $count) ?></b>
            <span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<section class="split" style="margin-top:24px">
    <div class="panel">
        <div class="panel-header">
            <div><h2>Add software</h2><span>Choose which departments should receive this licence.</span></div>
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Software name<input name="software_name" maxlength="150" required></label>
            <label>Vendor<input name="vendor" maxlength="150"></label>
            <label>Version<input name="version" maxlength="100"></label>
            <label>Licence type<select name="license_type"><option>Subscription</option><option>Perpetual</option><option>Free</option><option>Trial</option><option>Other</option></select></label>
            <label>Licence seats<input type="number" name="total_licenses" min="1" value="1" required></label>
            <label>Purchase date<input type="date" name="purchase_date" max="<?= e($today) ?>"></label>
            <label>Expiry date<input type="date" name="expiry_date"></label>
            <label>Cost<input type="number" name="cost" min="0" step="0.01" value="0"></label>
            <fieldset style="grid-column:1/-1">
                <legend>Eligible departments</legend>
                <?php if ($departments): ?>
                    <?php foreach ($departments as $department): ?>
                        <label style="display:inline-flex;align-items:center;gap:8px;margin:0 16px 8px 0">
                            <input type="checkbox" name="eligible_departments[]" value="<?= e($department['department']) ?>" required>
                            <?= e($department['department']) ?>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="muted">Add active users with a department before creating department-specific licences.</p>
                <?php endif; ?>
            </fieldset>
            <label style="grid-column:1/-1">Notes<textarea name="notes" rows="2"></textarea></label>
            <button type="submit" <?= $departments ? '' : 'disabled' ?>>Add software</button>
        </form>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div><h2>Assign or recommend a licence</h2><span>Only matching-department users are loaded for the selected product.</span></div>
        </div>
        <form method="get" action="software_dashboard.php" class="form-grid">
            <?php if ($softwareFilter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($softwareFilter) ?>"><?php endif; ?>
            <label>Software
                <select name="software_id" required>
                    <option value="">Choose software</option>
                    <?php foreach ($assignableSoftwares as $software): ?>
                        <?php $available = (int) $software['total_licenses'] - (int) $software['used_licenses']; ?>
                        <option value="<?= (int) $software['id'] ?>" <?= $selectedSoftwareId === (int) $software['id'] ? 'selected' : '' ?>>
                            <?= e($software['software_name'] . ($software['version'] ? ' ' . $software['version'] : '') . ' (' . $available . ' free)') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="secondary">Show eligible users</button>
        </form>
        <?php if ($selectedSoftware): ?>
            <p class="muted">
                Eligible departments:
                <?= $eligibleDepartments ? e(implode(', ', array_column($eligibleDepartments, 'department'))) : 'None configured — update this software’s details first.' ?>
            </p>
            <?php if ($eligibleUsers): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="software_id" value="<?= (int) $selectedSoftware['id'] ?>">
                    <label>User
                        <select name="user_id" required>
                            <option value="">Choose eligible user</option>
                            <?php foreach ($eligibleUsers as $eligibleUser): ?>
                                <option value="<?= (int) $eligibleUser['id'] ?>">
                                    <?= e($eligibleUser['full_name'] . ' · ' . $eligibleUser['department'] . ' · ' . $eligibleUser['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Assign licence</button>
                </form>
            <?php elseif ($eligibleDepartments): ?>
                <p class="notice">No active users belong to an eligible department for this licence.</p>
            <?php endif; ?>
        <?php elseif ($selectedSoftwareId !== null): ?>
            <p class="notice error">That software is unavailable or has no free licence seats.</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel" style="margin-top:24px">
    <div class="panel-header">
        <div><h2>Department licence recommendations</h2><span>Available licences configured for the selected department.</span></div>
    </div>
    <form method="get" action="software_dashboard.php" class="filter-grid">
        <?php if ($softwareFilter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($softwareFilter) ?>"><?php endif; ?>
        <div class="filter-field">
            <label for="recommend_department">Department</label>
            <select id="recommend_department" name="department" required>
                <option value="">Choose department</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= e($department['department']) ?>" <?= $recommendDepartment === $department['department'] ? 'selected' : '' ?>><?= e($department['department']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions"><button type="submit">Find recommended licences</button></div>
    </form>
    <?php if ($recommendDepartment !== ''): ?>
        <?php if ($recommendations): ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Software</th><th>Type</th><th>Available seats</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recommendations as $recommendation): ?>
                        <tr>
                            <td><strong><?= e($recommendation['software_name']) ?></strong><br><small><?= e(trim($recommendation['vendor'] . ' ' . ($recommendation['version'] ?? ''))) ?></small></td>
                            <td><?= e($recommendation['license_type']) ?></td>
                            <td><?= e((string) $recommendation['available_seats']) ?></td>
                            <td><a class="button secondary" href="software_dashboard.php?software_id=<?= (int) $recommendation['id'] ?>">View eligible users</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty-state"><strong>No available recommendations</strong>No active, unexpired software with a free seat is configured for <?= e($recommendDepartment) ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:24px">
    <div class="panel-header">
        <div><h2>Licence register</h2><span><?= e(ucfirst($softwareFilter)) ?> software licence records.</span></div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Software</th><th>Type</th><th>Seats</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php $hasSoftware = false; while ($software = $softwares->fetch_assoc()): $hasSoftware = true; $available = (int) $software['total_licenses'] - (int) $software['used_licenses']; $expired = $software['expiry_date'] && $software['expiry_date'] < $today; ?>
                    <tr>
                        <td><strong><?= e($software['software_name']) ?></strong><br><small><?= e(trim($software['vendor'] . ' ' . ($software['version'] ?? ''))) ?></small></td>
                        <td><?= e($software['license_type']) ?></td>
                        <td><?= e((string) $software['used_licenses']) ?> used / <?= e((string) $software['total_licenses']) ?><br><small><?= e((string) $available) ?> available</small></td>
                        <td><?= e($software['expiry_date'] ?: 'No expiry') ?></td>
                        <td><span class="status-badge <?= $expired || $software['status'] !== 'Active' ? 'status-inactive' : 'status-active' ?>"><?= e($expired ? 'Expired' : $software['status']) ?></span></td>
                        <td><a class="button secondary" href="software_details.php?id=<?= (int) $software['id'] ?>">Details</a></td>
                    </tr>
                <?php endwhile; ?>
                <?php if (!$hasSoftware): ?>
                    <tr><td colspan="6"><div class="empty-state"><strong>No matching software</strong>Choose another top filter or add a software record.</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php layout_end(); ?>

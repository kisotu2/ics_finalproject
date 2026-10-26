<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require __DIR__ . '/bootstrap.php';

require_login(['admin', 'super_admin']);

/* =====================================================
   FILTER VARIABLES
===================================================== */

$laptopWhere = "WHERE 1=1";
$softwareWhere = "WHERE 1=1";

$laptopParams = [];
$softwareParams = [];
$laptopTypes = "";
$softwareTypes = "";

/* =====================================================
   FILTER: USER NAME
===================================================== */

if (!empty($_GET['user_name'])) {

    $userName = trim($_GET['user_name']);

    $laptopWhere .= " AND u.full_name LIKE ?";
    $softwareWhere .= " AND u.full_name LIKE ?";

    $laptopParams[] = "%{$userName}%";
    $softwareParams[] = "%{$userName}%";
    $laptopTypes .= "s";
    $softwareTypes .= "s";
}

/* =====================================================
   FILTER: DATE RANGE
===================================================== */

if (!empty($_GET['from'])) {
    $from = $_GET['from'] . " 00:00:00";
    $laptopWhere .= " AND h.action_date >= ?";
    $softwareWhere .= " AND sh.action_date >= ?";
    $laptopParams[] = $from;
    $softwareParams[] = $from;
    $laptopTypes .= "s";
    $softwareTypes .= "s";
}

if (!empty($_GET['to'])) {
    $to = $_GET['to'] . " 23:59:59";
    $laptopWhere .= " AND h.action_date <= ?";
    $softwareWhere .= " AND sh.action_date <= ?";
    $laptopParams[] = $to;
    $softwareParams[] = $to;
    $laptopTypes .= "s";
    $softwareTypes .= "s";
}

/* =====================================================
   MAIN HISTORY QUERY
===================================================== */

$query = "

SELECT
    l.asset_tag COLLATE utf8mb4_unicode_ci AS asset_tag,
    u.id AS user_id,
    u.full_name COLLATE utf8mb4_unicode_ci AS user_name,
    a.full_name COLLATE utf8mb4_unicode_ci AS admin_name,
    h.action_type COLLATE utf8mb4_unicode_ci AS action_type,
    h.action_date
FROM laptop_history h

LEFT JOIN laptops l ON h.laptop_id = l.id
LEFT JOIN users u ON h.user_id = u.id
LEFT JOIN users a ON h.admin_id = a.id

{$laptopWhere}

UNION ALL

SELECT
    s.software_name COLLATE utf8mb4_unicode_ci AS asset_tag,
    u.id AS user_id,
    u.full_name COLLATE utf8mb4_unicode_ci AS user_name,
    a.full_name COLLATE utf8mb4_unicode_ci AS admin_name,
    sh.action_type COLLATE utf8mb4_unicode_ci AS action_type,
    sh.action_date
FROM software_history sh

LEFT JOIN softwares s ON sh.software_id = s.id
LEFT JOIN users u ON sh.user_id = u.id
LEFT JOIN users a ON sh.admin_id = a.id

{$softwareWhere}

ORDER BY action_date DESC

";

$stmt = $conn->prepare($query);

$params = array_merge($laptopParams, $softwareParams);
$types = $laptopTypes . $softwareTypes;

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

$historyRows = [];
$chartData = [];
$people = [];

while ($row = $result->fetch_assoc()) {
    $historyRows[] = $row;

    $action = trim((string) ($row['action_type'] ?? ''));
    $action = $action !== '' ? $action : 'Unspecified';
    $chartData[$action] = ($chartData[$action] ?? 0) + 1;

    if ($row['user_id'] !== null) {
        $people[$row['user_id']] = true;
    }
}

/* =====================================================
   LOAD USERS FOR FILTER
===================================================== */

$userList = $conn->query("
SELECT DISTINCT full_name 
FROM users 
WHERE role='user'
ORDER BY full_name
");

$userNameValue = (string) ($_GET['user_name'] ?? '');
$fromValue = (string) ($_GET['from'] ?? '');
$toValue = (string) ($_GET['to'] ?? '');
?>

<?php layout_start('Asset history'); ?>

<div class="history-page">
<section class="hero history-hero">
    <div class="hero-copy">
        <div class="eyebrow">RECORDS &amp; ACTIVITY</div>
        <h1>Asset history</h1>
        <p>A clear record of asset and software activity, including who made each change and when.</p>
    </div>
    <a class="button secondary back-link" href="dashboard.php">&larr; Back to dashboard</a>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Find a record</h2>
            <span>Filter activity by user or date range.</span>
        </div>
    </div>
    <form method="GET" action="history.php" class="filter-grid">
        <div class="filter-field">
            <label for="user_name">User</label>
            <input id="user_name" list="usernames" name="user_name" placeholder="Search by name" value="<?= e($userNameValue) ?>">
            <datalist id="usernames">
                <?php while ($user = $userList->fetch_assoc()): ?>
                    <option value="<?= e($user['full_name'] ?? '') ?>">
                <?php endwhile; ?>
            </datalist>
        </div>
        <div class="filter-field">
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= e($fromValue) ?>">
        </div>
        <div class="filter-field">
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= e($toValue) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit">Apply filters</button>
            <a class="reset-link" href="history.php">Clear</a>
        </div>
    </form>
</section>

<section class="metrics-grid history-metrics" aria-label="History summary">
    <article class="metric-card">
        <div class="metric-top"><span>Matching records</span><span class="metric-icon">#</span></div>
        <strong class="metric-number"><?= count($historyRows) ?></strong>
        <span class="metric-label">For the selected filters</span>
    </article>
    <article class="metric-card">
        <div class="metric-top"><span>People involved</span><span class="metric-icon">&#9673;</span></div>
        <strong class="metric-number"><?= count($people) ?></strong>
        <span class="metric-label">Unique users in these records</span>
    </article>
    <article class="metric-card">
        <div class="metric-top"><span>Action types</span><span class="metric-icon">&#8644;</span></div>
        <strong class="metric-number"><?= count($chartData) ?></strong>
        <span class="metric-label">Different actions recorded</span>
    </article>
</section>

<div class="history-layout">
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Activity records</h2>
                <span><?= count($historyRows) ?> <?= count($historyRows) === 1 ? 'record' : 'records' ?></span>
            </div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Asset or software</th>
                        <th>User</th>
                        <th>Recorded by</th>
                        <th>Action</th>
                        <th>Date and time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($historyRows): ?>
                        <?php foreach ($historyRows as $row): ?>
                            <?php
                            $actionDate = $row['action_date'] ?? '';
                            $timestamp = $actionDate !== '' ? strtotime($actionDate) : false;
                            $formattedDate = $timestamp !== false
                                ? date('M j, Y, g:i a', $timestamp)
                                : $actionDate;
                            ?>
                            <tr>
                                <td><span class="asset-name"><?= e($row['asset_tag'] ?? '') ?: 'Unavailable' ?></span></td>
                                <td><?= e($row['user_name'] ?? '') ?: '—' ?></td>
                                <td><?= e($row['admin_name'] ?? '') ?: '—' ?></td>
                                <td><span class="action-badge"><?= e($row['action_type'] ?? '') ?: 'Unspecified' ?></span></td>
                                <td class="date-cell"><?= e($formattedDate) ?: '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <strong>No matching activity</strong>
                                    Try changing your filters or clear them to see all records.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Actions overview</h2>
                <span>Breakdown for the records shown.</span>
            </div>
        </div>
        <?php if ($chartData): ?>
            <div class="chart-wrap">
                <canvas id="historyChart" aria-label="Chart of action types in the matching history records" role="img"></canvas>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <strong>Nothing to chart yet</strong>
                Matching action data will appear here.
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($chartData): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('historyChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_keys($chartData), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        datasets: [{
            data: <?= json_encode(array_values($chartData)) ?>,
            backgroundColor: ['#b08116', '#99bb4f', '#2778c8', '#dc7b45', '#718f32', '#8b79b6'],
            borderColor: '#ffffff',
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { usePointStyle: true, padding: 16, boxWidth: 8 }
            }
        }
    }
});
</script>
<?php endif; ?>
</div>
<?php layout_end(); ?>
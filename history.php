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

$params = [];
$types  = "";

/* =====================================================
   FILTER: USER NAME
===================================================== */

if (!empty($_GET['user_name'])) {

    $userName = trim($_GET['user_name']);

    $laptopWhere .= " AND u.full_name LIKE ?";
    $softwareWhere .= " AND u.full_name LIKE ?";

    $params[] = "%{$userName}%";
    $params[] = "%{$userName}%";

    $types .= "ss";
}

/* =====================================================
   FILTER: DATE RANGE
===================================================== */

if (!empty($_GET['from']) && !empty($_GET['to'])) {

    $from = $_GET['from'] . " 00:00:00";
    $to   = $_GET['to'] . " 23:59:59";

    $laptopWhere .= " AND h.action_date BETWEEN ? AND ?";
    $softwareWhere .= " AND sh.action_date BETWEEN ? AND ?";

    $params[] = $from;
    $params[] = $to;
    $params[] = $from;
    $params[] = $to;

    $types .= "ssss";
}

/* =====================================================
   MAIN HISTORY QUERY
===================================================== */

$query = "

SELECT
    l.asset_tag COLLATE utf8mb4_unicode_ci AS asset_tag,
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

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

/* =====================================================
   CHART DATA
===================================================== */

$chartQuery = "
    SELECT action_type, COUNT(*) AS total
    FROM (
        SELECT action_type
        FROM laptop_history

        UNION ALL

        SELECT action_type
        FROM software_history
    ) AS history
    GROUP BY action_type
    ORDER BY total DESC
";

$chartResult = $conn->query($chartQuery);

$chartData = [];

while ($row = $chartResult->fetch_assoc()) {
    $chartData[$row['action_type']] = (int)$row['total'];
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Asset Assignment History</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body {
    font-family: Arial, sans-serif;
    background-color: #f4f6f9;
    margin: 2rem;
}

h1 {
    margin-bottom: 1rem;
}

.filter-box {
    background: #ffffff;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 20px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.filter-box input,
.filter-box button {
    padding: 6px 10px;
    margin-right: 8px;
}

.filter-box button {
    background-color: #b08116;
    color: #ffffff;
    border: none;
    cursor: pointer;
}

.filter-box button:hover {
    background-color: #9f7004;
}

table {
    width: 100%;
    border-collapse: collapse;
    background-color: #ffffff;
}

th, td {
    padding: 10px;
    border: 1px solid #dddddd;
    text-align: left;
}

th {
    background:linear-gradient(to right,#b08116,#99bb4f);
    color: #ffffff;
}

.chart-container {
    width: 500px;
    margin-top: 30px;
}
</style>
</head>

<body>

<h1>Asset Assignment History</h1>

<!-- ================= FILTER SECTION ================= -->
<div class="filter-box">
<form method="GET">

<label>User:</label>
<input list="usernames"
       name="user_name"
       placeholder="Type or select user..."
       value="<?= htmlspecialchars($_GET['user_name'] ?? '') ?>">

<datalist id="usernames">
<?php while($u = $userList->fetch_assoc()): ?>
<option value="<?= htmlspecialchars($u['full_name']) ?>">
<?php endwhile; ?>
</datalist>

<label>From:</label>
<input type="date" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">

<label>To:</label>
<input type="date" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">

<button type="submit">Apply Filter</button>
<a href="history.php">Reset</a>

</form>
</div>

<!-- ================= HISTORY TABLE ================= -->
<table>
<thead>
<tr>
    <th>Asset Tag</th>
    <th>User</th>
    <th>Admin</th>
    <th>Action</th>
    <th>Date</th>
</tr>
</thead>
<tbody>
<?php if($result->num_rows > 0): ?>
    <?php while($row = $result->fetch_assoc()): ?>
    <tr>
        <td><?= htmlspecialchars($row['asset_tag']) ?></td>
        <td><?= htmlspecialchars($row['user_name']) ?></td>
        <td><?= htmlspecialchars($row['admin_name']) ?></td>
        <td><?= htmlspecialchars($row['action_type']) ?></td>
        <td><?= htmlspecialchars($row['action_date']) ?></td>
    </tr>
    <?php endwhile; ?>
<?php else: ?>
<tr>
    <td colspan="5" style="text-align:center;">No records found.</td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- ================= CHART SECTION ================= -->
<div class="chart-container">
<canvas id="historyChart"></canvas>
</div>

<script>
const ctx = document.getElementById('historyChart');

new Chart(ctx, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_keys($chartData)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($chartData)) ?>,
            backgroundColor: ['#2c7be5','#00d97e','#e63757','#f6c343','#6f42c1']
        }]
    }
});
</script>

</body>
</html>
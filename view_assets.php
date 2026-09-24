<?php
require 'db.php';
session_start();

if(!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','admin'])){
    header("Location: login.php");
    exit();
}

// Fetch all ICT assets
$result = $conn->query("SELECT * FROM laptops WHERE department='ICT' ORDER BY created_at DESC");
$assets = [];
if($result){
    while($row = $result->fetch_assoc()){
        $assets[] = $row;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>View ICT Assets</title>
<style>
body { font-family: Arial; margin:2rem; background:#f5f5f5; }
h1 { color:#b08116; }
table { width:100%; border-collapse:collapse; margin-top:1rem; }
th, td { padding:0.8rem; border:1px solid #ccc; text-align:left; }
th { background:#b08116; color:white; }
tr:nth-child(even) { background:#f0f0f0; }
a.logout { float:right; background:#dc3545; color:white; padding:8px 12px; border-radius:5px; text-decoration:none; }
.logout:hover { opacity:0.9; }
</style>
</head>
<body>

<h1>ICT Registered Assets 
    <a href="logout.php" class="logout">Logout</a>
</h1>

<table>
<tr>
<th>Asset Tag</th>
<th>Serial Number</th>
<th>Brand</th>
<th>Model</th>
<th>Full Name</th>
<th>Status</th>
<th>Purchase Date</th>
<th>Warranty Expiry</th>
</tr>

<?php foreach($assets as $a): ?>
<tr>
<td><?= htmlspecialchars($a['asset_tag']) ?></td>
<td><?= htmlspecialchars($a['serial_number']) ?></td>
<td><?= htmlspecialchars($a['brand']) ?></td>
<td><?= htmlspecialchars($a['model']) ?></td>
<td>
    <?= !empty($a['full_name']) 
        ? htmlspecialchars($a['full_name']) 
        : 'Unassigned' ?>
</td><td><?= htmlspecialchars($a['status']) ?></td>
<td><?= htmlspecialchars($a['purchase_date']) ?></td>
<td><?= htmlspecialchars($a['warranty_expiry']) ?></td>
</tr>
<?php endforeach; ?>
</table>

</body>
</html>
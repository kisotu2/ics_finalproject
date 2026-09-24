<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* ===============================
GET USER INFO
================================= */

$user = $conn->prepare("SELECT full_name,email FROM users WHERE id=?");
$user->bind_param("i",$user_id);
$user->execute();
$userData = $user->get_result()->fetch_assoc();

/* ===============================
GET LAPTOPS
================================= */

$stmt = $conn->prepare("
SELECT *
FROM laptops
WHERE assigned_to=?
");

$stmt->bind_param("i",$user_id);
$stmt->execute();
$laptops = $stmt->get_result();

/* ===============================
ACCESSORIES
================================= */

$accStmt = $conn->prepare("
SELECT *
FROM laptop_accessories
WHERE user_id=?
");

$accStmt->bind_param("i",$user_id);
$accStmt->execute();
$accessories = $accStmt->get_result()->fetch_assoc();

/* ===============================
SOFTWARE
================================= */

$softStmt = $conn->prepare("
SELECT software_name
FROM user_software
WHERE user_id=?
");

$softStmt->bind_param("i",$user_id);
$softStmt->execute();
$software = $softStmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>

<title>My Devices</title>

<style>

body{
margin:0;
font-family:Segoe UI;
background:#f4f6f9;
}

/* HEADER */

.header{
background:linear-gradient(to right,#b08116,#99bb4f);
color:white;
padding:20px 40px;
display:flex;
justify-content:space-between;
align-items:center;
box-shadow:0 3px 10px rgba(0,0,0,0.2);
}

.header h2{
margin:0;
}

.logout{
background:white;
color:#b08116;
padding:8px 18px;
border-radius:6px;
text-decoration:none;
font-weight:bold;
}

/* USER CARD */

.profile{
max-width:1000px;
margin:30px auto;
background:beige;
padding:25px;
border-radius:10px;
box-shadow:0 5px 20px rgba(0,0,0,0.08);
}

.profile h3{
margin-top:0;
}

/* GRID */

.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
gap:20px;
}

/* DEVICE CARD */

.card{
background:white;
border-radius:10px;
padding:20px;
box-shadow:0 5px 15px rgba(0,0,0,0.1);
border-left:6px solid #99bb4f;
transition:0.2s;
}

.card:hover{
transform:translateY(-3px);
}

.card h4{
margin-top:0;
color:#b08116;
}

.badge{
padding:5px 10px;
border-radius:20px;
font-size:12px;
color:white;
}

.active{
background:#2ecc71;
}

.issued{
background:#3498db;
}

/* SOFTWARE LIST */

.software{
margin-top:20px;
}

.software span{
display:inline-block;
background:#e9f5dc;
padding:6px 12px;
border-radius:20px;
margin:4px;
font-size:13px;
}

/* ACCESSORIES */

.accessories{
margin-top:15px;
}

.accessories span{
background:#f1f1f1;
padding:6px 10px;
border-radius:6px;
margin-right:5px;
}

</style>

</head>

<body>

<div class="header">
<h2>My IT Assets</h2>
<a href="logout.php" class="logout">Logout</a>
</div>

<div class="profile">

<h3><?= htmlspecialchars($userData['full_name']) ?></h3>
<p><?= htmlspecialchars($userData['email']) ?></p>

</div>

<div class="profile">

<h3>Assigned Devices</h3>

<div class="grid">

<?php if($laptops->num_rows > 0): ?>

<?php while($device = $laptops->fetch_assoc()): ?>

<div class="card">

<h4><?= htmlspecialchars($device['asset_tag']) ?></h4>

<p><b>Serial:</b> <?= htmlspecialchars($device['serial_number']) ?></p>

<p><b>Brand:</b> <?= htmlspecialchars($device['brand']) ?></p>

<p><b>Model:</b> <?= htmlspecialchars($device['model']) ?></p>

<p>
<b>Status:</b>
<span class="badge issued">
<?= htmlspecialchars($device['status']) ?>
</span>
</p>

<div class="accessories">

<b>Accessories:</b><br>

<?php if($accessories): ?>

<?php if($accessories['mouse_given']) echo "<span>Mouse</span>"; ?>
<?php if($accessories['charger_given']) echo "<span>Charger</span>"; ?>

<?php else: ?>

<span>No accessories</span>

<?php endif; ?>

</div>

<div class="software">

<b>Software Installed:</b><br>

<?php while($s = $software->fetch_assoc()): ?>

<span><?= htmlspecialchars($s['software_name']) ?></span>

<?php endwhile; ?>

</div>

</div>

<?php endwhile; ?>

<?php else: ?>

<p>No devices assigned to you.</p>

<?php endif; ?>

</div>

</div>

</body>
</html>
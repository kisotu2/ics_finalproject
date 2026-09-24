<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require 'db.php';
session_start();

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin'){
    header("Location: login.php");
    exit();
}

$message = "";

/* ===============================
FUNCTION TO DISPLAY ASSET ROW
=================================*/

if(!function_exists('displayAssetRow')){
    function displayAssetRow($row){
        ?>
        <tr data-type="<?= strtolower($row['type']) ?>">
            <td><?= ucfirst($row['type']) ?></td>
            <td>
                <a href="asset_details.php?id=<?= $row['id'] ?>&category=<?= strtolower($row['type']) ?>" 
                   style="color:#b08116; font-weight:bold; text-decoration:none;">
                    <?= htmlspecialchars($row['asset_tag']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars($row['serial_number']) ?></td>
            <td><?= htmlspecialchars($row['brand']) ?></td>
            <td><?= htmlspecialchars($row['model']) ?></td>
            <td><?= htmlspecialchars($row['status']) ?></td>
            <td>
                <?php if(!empty($row['assigned_to'])): ?>
                    <span style="color:red;font-weight:bold;">Issued</span>
                <?php else: ?>
                    <span style="color:green;font-weight:bold;">Available</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

/* ===============================
FETCH CATEGORIES AND ASSETS
=================================*/

$categories = ['Laptop' => 'laptops', 'Phone' => 'phones', 'Desktop' => 'desktops'];
$assetsByCategory = [];
$totalAssets = 0;

foreach($categories as $cat => $table){
    $res = $conn->query("SELECT *, '$cat' AS type FROM $table ORDER BY asset_tag ASC");
    $assetsByCategory[$cat] = $res->fetch_all(MYSQLI_ASSOC);
    $totalAssets += count($assetsByCategory[$cat]); // add to total count
}


/* ===============================
FETCH DASHBOARD DATA
=================================*/
$totalUsers = $conn->query("SELECT COUNT(*) AS t FROM users WHERE role='user'")->fetch_assoc()['t'];
$totalAdmins = $conn->query("SELECT COUNT(*) AS t FROM users WHERE role='admin'")->fetch_assoc()['t'];
$totalAssets = $conn->query("SELECT COUNT(*) AS t FROM assets")->fetch_assoc()['t'];
$totalSoftware = $conn->query("SELECT COUNT(*) AS t FROM softwares")->fetch_assoc()['t'];

$users = $conn->query("SELECT * FROM users WHERE role='user'");
$admins = $conn->query("SELECT * FROM users WHERE role='admin'");
$softwares = $conn->query("SELECT * FROM softwares");

/* ===============================
HANDLE FORMS (ADD ADMIN, REGISTER USER, ADD ASSET)
=================================*/
// -- Add Admin
if(isset($_POST['add_admin'])){
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = "admin";
    $status = "active";

    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if($check->num_rows > 0){
        $message = "⚠️ Email already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users(full_name,email,password,role,status) VALUES(?,?,?,?,?)");
        $stmt->bind_param("sssss", $full_name, $email, $password, $role, $status);
        $stmt->execute();
        $message = "✅ Admin added successfully!";
    }
}

// -- Register User
if(isset($_POST['register_user'])){
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = "user";
    $status = "active";

    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if($check->num_rows > 0){
        $message = "⚠️ Email already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users(full_name,email,password,role,status) VALUES(?,?,?,?,?)");
        $stmt->bind_param("sssss", $full_name, $email, $password, $role, $status);
        $stmt->execute();
        $message = "✅ User registered successfully!";
    }
}

// -- Add Asset
if(isset($_POST['add_asset'])){
    $asset_tag = trim($_POST['asset_tag']);
    $serial = trim($_POST['serial_number']);
    $brand = $_POST['brand'];
    $model = $_POST['model'];
    $status = $_POST['status'];
    $category = $_POST['category'];
    $assigned_to = NULL;

    // Check if asset exists
    $check = $conn->prepare("SELECT id FROM assets WHERE asset_tag=? OR serial_number=?");
    $check->bind_param("ss", $asset_tag, $serial);
    $check->execute();
    $check->store_result();

    if($check->num_rows > 0){
        $message = "⚠️ Asset already exists (duplicate tag or serial)!";
    } else {
        $stmt = $conn->prepare("INSERT INTO assets(asset_tag,serial_number,brand,model,status,category,assigned_to) VALUES(?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssi", $asset_tag, $serial, $brand, $model, $status, $category, $assigned_to);
        $stmt->execute();
        $message = "✅ Asset added successfully!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>IRA Super Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{margin:0;font-family:Segoe UI;background:#f4f6f9;}
.sidebar{width:250px;height:100vh;position:fixed;background:linear-gradient(180deg,#99bb4f,#b08116);color:white;}
.sidebar h2{text-align:center;padding:20px;border-bottom:1px solid rgba(255,255,255,0.2);}
.sidebar a{display:block;padding:14px 20px;color:white;text-decoration:none;font-size:15px;}
.sidebar a:hover{background:rgba(255,255,255,0.15);}
.main{
    margin-left:250px;
    padding:30px 40px;
    min-height:100vh;
}

/* FIX HEADER ALIGNMENT */
.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:25px;
    background:white;
    padding:20px 25px;
    border-radius:10px;
    box-shadow:0 3px 10px rgba(0,0,0,0.08);
}

/* Make title stronger */
.header h1{
    font-size:22px;
    color:#333;
    margin:0;
}
.sidebar{
    width:250px;
    height:100vh;
    position:fixed;
    top:0;
    left:0;
    background:linear-gradient(180deg,#99bb4f,#b08116);
    color:white;
    z-index:1000; /* ensures it stays above */
}
.logout{background:#b08116;color:white;padding:8px 15px;text-decoration:none;border-radius:5px;}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:25px;}
.card{
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,0.08);
    cursor:pointer;
    transition:0.3s;
    text-align:center;
    position:relative;
    overflow:hidden;
}

/* subtle top accent line */
.card::before{
    content:'';
    position:absolute;
    top:0;
    left:0;
    width:100%;
    height:5px;
    background:linear-gradient(90deg,#99bb4f,#b08116);
}

.card:hover{
    transform:translateY(-6px) scale(1.01);
}
.card h3{margin:0;color:#333;}
.card p{font-size:30px;margin-top:10px;color:#99bb4f;}
.card ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.card ul li {
    background: rgba(255, 255, 255, 0.2);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
    transition: background 0.3s;
}
.card ul li:hover {
    background: rgba(255, 255, 255, 0.35);
}
.badge {
    display: inline-block;
    background: linear-gradient(135deg, #b08116, #99bb4f);
    color: white;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    transition: transform 0.2s, opacity 0.2s;
    margin: 3px 5px 0 0;
    cursor: default;
}
.badge:hover {
    transform: scale(1.05);
    opacity: 0.85;
}
.section{
    display:none;
    margin-top:25px;
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 4px 12px rgba(0,0,0,0.08);
}
.section table{
    width:100%;
    border-collapse:collapse;
    border-radius:10px;
    overflow:hidden;
}

.section th{
    background:linear-gradient(180deg,#b08116,#8e6a10);
    color:white;
    text-align:left;
}

.section tr:hover{
    background:#f9f9f9;
}
input,select{width:100%;padding:10px;margin:8px 0;border:1px solid #ddd;border-radius:6px;}
button{background:#99bb4f;border:none;padding:10px 15px;color:white;cursor:pointer;border-radius:6px;}
button:hover{background:#7ea93e;}
.message{margin-top:15px;font-weight:bold;
}
</style>
<script>
function showSection(sectionId){
    let sections=document.querySelectorAll(".section");
    sections.forEach(s=>s.style.display="none");
    document.getElementById(sectionId).style.display="block";
}
function showAssetsSection(){
    showSection('assetManager');
}
function filterAssets(type){
    let rows = document.querySelectorAll("#assetTable tr[data-type]");
    rows.forEach(row => {
        if(type==='all'){ row.style.display=''; }
        else{ row.style.display = (row.getAttribute('data-type')===type) ? '' : 'none'; }
    });
}
</script>
</head>
<body>
<div class="sidebar">
<h2>IRA Asset System</h2>
<a href="#" onclick="showSection('dashboard')"><i class="fa fa-chart-line"></i> Dashboard</a>
<a href="#" onclick="showSection('addAdmin')"><i class="fa fa-user-shield"></i> Add Admin</a>
<a href="#" onclick="showSection('registerUser')"><i class="fa fa-user"></i> Register User</a>
<a href="software_dashboard.php"><i class="fa fa-box"></i> Software Monitoring</a>
<a href="#" onclick="showAssetsSection()"><i class="fa fa-boxes"></i> Assets</a>
<a href="software_dashboard.php"><i class="fa fa-key"></i> Licence Assignment</a>
<a href="logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a>
</div>

<div class="main">
<div class="header">
<h1>Super Admin Control Center</h1>
</div>

<?php if($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<!-- DASHBOARD CARDS -->
<div class="cards">
<div class="card" onclick="showSection('adminsList')"><h3>Total Admins</h3><p><?= $totalAdmins ?></p></div>
<div class="card" onclick="showSection('usersList')"><h3>Total Users</h3><p><?= $totalUsers ?></p></div>
<div class="card" onclick="showSection('assetManager')">
    <h3>Total Assets</h3>
    <p><?= $totalAssets ?></p>
    <div style="margin-top:10px;">
    <?php foreach($assetsByCategory as $cat => $list): ?>
        <span class="badge"><?= $cat ?>: <?= count($list) ?></span>
    <?php endforeach; ?>
</div>
</div>
<div class="card" onclick="showSection('softwaresList')"><h3>Total Software</h3><p><?= $totalSoftware ?></p></div>
</div>

<!-- Add Admin Form -->
<div class="section" id="addAdmin">
<h2>Add System Admin</h2>
<form method="POST">
<input type="text" name="full_name" placeholder="Full Name" required>
<input type="email" name="email" placeholder="Email" required>
<input type="password" name="password" placeholder="Password" required>
<button name="add_admin">Create Admin</button>
</form>
</div>

<!-- Register User Form -->
<div class="section" id="registerUser">
<h2>Register User</h2>
<form method="POST">
<input type="text" name="full_name" placeholder="Full Name" required>
<input type="email" name="email" placeholder="Email" required>
<input type="password" name="password" placeholder="Password" required>
<button name="register_user">Register User</button>
</form>
</div>

<!-- ASSET MANAGER SECTION -->
<div class="section" id="assetManager">
    <h2>Assets</h2>

    <!-- ADD ASSET FORM -->
    <div style="margin-bottom:20px; padding:10px; border:1px solid #ddd; border-radius:6px;">
        <h3>Add New Asset</h3>
        <form id="addAssetForm">
            <input type="text" name="asset_tag" placeholder="Asset Tag" required>
            <input type="text" name="serial_number" placeholder="Serial Number" required>
            <input type="text" name="brand" placeholder="Brand" required>
            <input type="text" name="model" placeholder="Model" required>
            <select name="status" required>
                <option value="Active">Active</option>
                <option value="Faulty">Faulty</option>
                <option value="Retired">Retired</option>
            </select>
            <select name="category" required>
                <option value="Laptop">Laptop</option>
                <option value="Phone">Phone</option>
                <option value="Desktop">Desktop</option>
            </select>
            <button type="submit">Add Asset</button>
        </form>
        <p id="assetMessage" style="font-weight:bold;margin-top:5px;"></p>
    </div>

    <!-- CATEGORY FILTER -->
    <div style="margin-bottom:20px;">
        <button onclick="filterAssets('all')">All</button>
        <?php foreach($categories as $cat => $tbl): ?>
            <button onclick="filterAssets('<?= strtolower($cat) ?>')"><?= $cat ?>s</button>
        <?php endforeach; ?>
    </div>

    <!-- ASSETS TABLE -->
    <table id="assetTable">
        <tr>
            <th>Type</th>
            <th>Asset Tag</th>
            <th>Serial</th>
            <th>Brand</th>
            <th>Model</th>
            <th>Status</th>
            <th>State</th>
        </tr>
        <?php
        foreach($assetsByCategory as $cat => $rows){
            foreach($rows as $row){
                displayAssetRow($row);
            }
        }
        ?>
    </table>
</div>

<script>
// FILTER ASSETS BY CATEGORY
function filterAssets(type){
    let rows = document.querySelectorAll("#assetTable tr[data-type]");
    rows.forEach(row=>{
        if(type==='all'){ row.style.display=''; }
        else{ row.style.display = (row.getAttribute('data-type')===type) ? '' : 'none'; }
    });
}

// AJAX ADD ASSET
document.getElementById('addAssetForm').addEventListener('submit', function(e){
    e.preventDefault();
    let formData = new FormData(this);
    fetch('add_asset_ajax.php', { method:'POST', body:formData })
        .then(res => res.json())
        .then(data => {
            const msg = document.getElementById('assetMessage');
            msg.textContent = data.message;
            msg.style.color = data.success ? 'green' : 'red';
            if(data.success){
                // Append new row to table
            // Append new row to table dynamically
const table = document.getElementById('assetTable');
const tr = document.createElement('tr');
tr.setAttribute('data-type', formData.get('category').toLowerCase());
tr.innerHTML = `
    <td>${formData.get('category')}</td>
    <td>
        <a href="asset_details.php?id=${data.id}&category=${formData.get('category').toLowerCase()}" 
           style="color:#b08116; font-weight:bold; text-decoration:none;">
            ${formData.get('asset_tag')}
        </a>
    </td>
    <td>${formData.get('serial_number')}</td>
    <td>${formData.get('brand')}</td>
    <td>${formData.get('model')}</td>
    <td>${formData.get('status')}</td>
    <td><span style="color:green;font-weight:bold;">Available</span></td>
`;
table.appendChild(tr);
                this.reset();
            }
        });
});
</script>
</table>
</div>

<!-- Admins List -->
<div class="section" id="adminsList">
<h2>All Admins</h2>
<table><tr><th>Name</th><th>Email</th><th>Status</th></tr>
<?php while($a=$admins->fetch_assoc()){ ?>
<tr><td><?= $a['full_name'] ?></td><td><?= $a['email'] ?></td><td><?= $a['status'] ?></td></tr>
<?php } ?>
</table>
</div>

<!-- Users List -->
<div class="section" id="usersList">
<h2>All Users</h2>
<table><tr><th>Name</th><th>Email</th><th>Status</th></tr>
<?php while($u=$users->fetch_assoc()){ ?>
<tr><td><?= $u['full_name'] ?></td><td><?= $u['email'] ?></td><td><?= $u['status'] ?></td></tr>
<?php } ?>
</table>
</div>

<!-- Software List -->
<div class="section" id="softwaresList">
<h2>All Software</h2>
<table>
<tr><th>Name</th><th>Vendor</th><th>License</th><th>Action</th></tr>
<?php while($s=$softwares->fetch_assoc()){ ?>
<tr>
<td><?= $s['software_name'] ?></td>
<td><?= $s['vendor'] ?></td>
<td><?= $s['license_type'] ?></td>
<td><a href="software_details.php?id=<?= $s['id'] ?>"><button>View Details</button></a></td>
</tr>
<?php } ?>
</table>
</div>

</div>
</body>
</html>

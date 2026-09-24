<?php
require 'db.php';
session_start();

if(!isset($_SESSION['role']) || 
   ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'super_admin')){
    header("Location: login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = "";
$admin_id = $_SESSION['user_id'];

/* ==========================
   ASSIGN DEVICE
========================== */
if(isset($_POST['assign_device'])){
    $laptop_id = intval($_POST['laptop_id']);
    $user_id   = intval($_POST['user_id']);
    $stmt = $conn->prepare("UPDATE laptops SET assigned_to=?, status='Assigned' WHERE id=?");
    $stmt->bind_param("ii",$user_id,$laptop_id);
    $stmt->execute();
    $message = "Device assigned successfully!";
}

/* ==========================
   UNASSIGN DEVICE
========================== */
if(isset($_POST['unassign_device'])){
    $laptop_id = intval($_POST['laptop_id']);
    $stmt = $conn->prepare("UPDATE laptops SET assigned_to=NULL, status='Available' WHERE id=?");
    $stmt->bind_param("i",$laptop_id);
    $stmt->execute();
    $message = "Device unassigned successfully!";
}

/* ==========================
   UPDATE DEVICE STATUS
========================== */
if(isset($_POST['update_status'])){
    $laptop_id = intval($_POST['laptop_id']);
    $status    = $_POST['status'];
    $stmt = $conn->prepare("UPDATE laptops SET status=? WHERE id=?");
    $stmt->bind_param("si",$status,$laptop_id);
    $stmt->execute();
    $message = "Device status updated!";
}

/* ==========================
   FETCH USERS
========================== */
$users = [];
$result = $conn->query("SELECT id, full_name FROM users WHERE status='active' AND role='user' ORDER BY full_name");
while($row = $result->fetch_assoc()){
    $users[] = $row;
}

/* ==========================
   DASHBOARD STATS
========================== */
$assigned_assets = $conn->query("SELECT COUNT(*) as total FROM laptops WHERE LOWER(status)='assigned'")->fetch_assoc()['total'];
$available_assets = $conn->query("SELECT COUNT(*) as total FROM laptops WHERE LOWER(status)='available'")->fetch_assoc()['total'];
$retired_assets = $conn->query("SELECT COUNT(*) as total FROM laptops WHERE LOWER(status)='retired'")->fetch_assoc()['total'];
$disposed_assets = $conn->query("SELECT COUNT(*) as total FROM laptops WHERE LOWER(status)='disposed' OR LOWER(status)='faulty'")->fetch_assoc()['total'];
$total_assets = $conn->query("SELECT COUNT(*) as total FROM laptops")->fetch_assoc()['total'];

/* ==========================
   FILTER
========================== */
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$whereClause = "";
switch($filter){
    case 'assigned': 
        $whereClause = "WHERE l.status='Assigned' OR l.status='assigned'";
        break;
    case 'available': 
        $whereClause = "WHERE l.status='Available'";
        break;
    case 'retired': 
        $whereClause = "WHERE l.status='Retired'";
        break;
    case 'disposed': 
        $whereClause = "WHERE l.status='Disposed' OR l.status='Faulty'";
        break;
    default: 
        $whereClause = "";
        break;
}

/* ==========================
   FETCH LAPTOPS
========================== */
$laptops = [];
$result2 = $conn->query("
    SELECT l.*, u.full_name 
    FROM laptops l 
    LEFT JOIN users u ON l.assigned_to = u.id 
    $whereClause
    ORDER BY l.created_at DESC
");
while($row = $result2->fetch_assoc()){
    $laptops[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Admin Dashboard</title>
<style>
body{margin:0;font-family:Arial;background:#f4f6f9;}
.wrapper{display:flex;}
.sidebar{width:230px;height:100vh;background:linear-gradient(180deg,#99bb4f,#b08116);color:white;position:fixed;left:0;top:0;padding-top:20px;}
.sidebar h2{text-align:center;margin-bottom:30px;}
.sidebar a{display:block;color:white;text-decoration:none;padding:14px 20px;transition:0.2s;}
.sidebar a:hover{background:rgba(255,255,255,0.15);}
.sidebar a.active{background:rgba(255,255,255,0.25);font-weight:bold;}
.main{margin-left:230px;padding:30px;width:100%;}
h1{color:#b08116;display:flex;justify-content:space-between;align-items:center;}
.card-container{display:flex;gap:20px;margin-bottom:2rem;flex-wrap:wrap;}
.card{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.1);flex:1;text-align:center;cursor:pointer;transition:0.2s;}
.card:hover{transform:translateY(-3px);box-shadow:0 4px 12px rgba(0,0,0,0.2);}
.active-card{border:3px solid #b08116;}
table{width:100%;border-collapse:collapse;background:white;box-shadow:0 2px 6px rgba(0,0,0,0.08);}
th,td{padding:10px;border:1px solid #ccc;}
th{background:#99bb4f;color:white;}
tr:hover{background:#f9fafb;}
.assign{background:#28a745;color:white;border:none;padding:6px 12px;cursor:pointer;border-radius:5px;}
.unassign{background:#dc3545;color:white;border:none;padding:6px 12px;cursor:pointer;border-radius:5px;}
.logout{background:#dc3545;color:white;padding:8px 12px;text-decoration:none;border-radius:5px;}
.status-Available{color:green;font-weight:bold;}
.status-Assigned{color:blue;font-weight:bold;}
.status-Retired{color:orange;font-weight:bold;}
.status-Disposed{color:red;font-weight:bold;}
.search-bar{padding:10px;width:320px;border:1px solid #ccc;border-radius:6px;margin-bottom:15px;}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);justify-content:center;align-items:center;}
.modal-content{background:white;padding:20px;border-radius:8px;min-width:300px;text-align:center;}
.close-btn{float:right;cursor:pointer;color:#dc3545;font-weight:bold;}
select,input{padding:5px;border-radius:5px;border:1px solid #ccc;}
</style>
<script>
function goFilter(filter){window.location="?filter="+filter;}
function searchLaptop(){
let input=document.getElementById("searchLaptop").value.toLowerCase();
let rows=document.querySelectorAll("tbody tr");
rows.forEach(row=>{
  let text=row.textContent.toLowerCase();
  row.style.display=text.includes(input)?"":"none";
});
}
function openModal(id){document.getElementById('modal-'+id).style.display='flex';}
function closeModal(id){document.getElementById('modal-'+id).style.display='none';}
</script>
</head>
<body>

<div class="wrapper">

<div class="sidebar">
<h2>IRA Asset System</h2>

<a href="admin_dashboard.php" class="<?= $current_page=='admin_dashboard.php'?'active':'' ?>">
🏠 Dashboard
</a>

<a href="issue_software.php" class="<?= $current_page=='issue_software.php'?'active':'' ?>">
💾 Issue Software
</a>

<a href="history.php" class="<?= $current_page=='history.php'?'active':'' ?>">
📜 Asset History
</a>

<a href="unassign_device.php">
🔄 Unassign Device
</a>

<a href="javascript:history.back()">
⬅ Back
</a>

<a href="logout.php">
🚪 Logout
</a>

</div>

<div class="main">

<h1>Admin Dashboard
</h1>

<input type="text" id="searchLaptop" class="search-bar" placeholder="Search assets or users..." onkeyup="searchLaptop()">

<div class="card-container">
  <div class="card <?= $filter=='all' ? 'active-card' : '' ?>" onclick="goFilter('all')">
    <h2><?= $total_assets ?></h2><p>Total</p>
  </div>
  <div class="card <?= $filter=='available' ? 'active-card' : '' ?>" onclick="goFilter('available')">
    <h2><?= $available_assets ?></h2><p>Available</p>
  </div>
  <div class="card <?= $filter=='assigned' ? 'active-card' : '' ?>" onclick="goFilter('assigned')">
    <h2><?= $assigned_assets ?></h2><p>Issued</p>
  </div>
  <div class="card <?= $filter=='retired' ? 'active-card' : '' ?>" onclick="goFilter('retired')">
    <h2><?= $retired_assets ?></h2><p>Retired</p>
  </div>
  <div class="card <?= $filter=='disposed' ? 'active-card' : '' ?>" onclick="goFilter('disposed')">
    <h2><?= $disposed_assets ?></h2><p>Faulty / Disposed</p>
  </div>
</div>

<?php if($message): ?><p style="color:green;font-weight:bold;"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<table>
<thead>
<tr><th>Asset Tag</th><th>Serial</th><th>Brand</th><th>Model</th><th>Status</th><th>Assigned To</th><th>Action</th></tr>
</thead>
<tbody>
<?php foreach($laptops as $lap): ?>
<tr>
<td><?= htmlspecialchars($lap['asset_tag']) ?></td>
<td><?= htmlspecialchars($lap['serial_number']) ?></td>
<td><?= htmlspecialchars($lap['brand']) ?></td>
<td><?= htmlspecialchars($lap['model']) ?></td>
<td class="status-<?= $lap['status'] ?>"><?= htmlspecialchars($lap['status']) ?></td>
<td><?= $lap['full_name'] ? htmlspecialchars($lap['full_name']) : 'Unassigned' ?></td>
<td>
<?php if(!$lap['assigned_to'] && $lap['status']=='Available'): ?>
<form method="POST" style="margin-bottom:5px">
<input type="hidden" name="laptop_id" value="<?= $lap['id'] ?>">
<select name="user_id" required>
<option value="">Select User</option>
<?php foreach($users as $user): ?>
<option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
<?php endforeach; ?>
</select>
<button type="submit" name="assign_device" class="assign">Assign</button>
</form>
<button onclick="openModal(<?= $lap['id'] ?>)">Check Condition</button>
<div class="modal" id="modal-<?= $lap['id'] ?>">
<div class="modal-content">
<span class="close-btn" onclick="closeModal(<?= $lap['id'] ?>)">×</span>
<h3>Check Laptop Condition</h3>
<form method="POST">
<input type="hidden" name="laptop_id" value="<?= $lap['id'] ?>">
<select name="status" required>
<option value="Available">Good - Ready for Assignment</option>
<option value="Retired">Needs Repair - Retire Temporarily</option>
<option value="Disposed">Cannot Repair - Dispose</option>
</select>
<br><br>
<button type="submit" name="update_status" class="assign">Update Status</button>
</form>
</div>
</div>
<?php elseif($lap['assigned_to']): ?>
<form method="POST"><input type="hidden" name="laptop_id" value="<?= $lap['id'] ?>"><button type="submit" name="unassign_device" class="unassign">Unassign</button></form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</div>
</div>
</body>
</html>
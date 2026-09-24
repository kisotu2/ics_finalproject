<?php
require 'db.php';
session_start();

if(!isset($_SESSION['role']) || 
   ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'super_admin')){
    header("Location: login.php");
    exit();
}

$message = "";

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
$result = $conn->query("SELECT id, full_name, role FROM users WHERE status='active' ORDER BY full_name");
while($row = $result->fetch_assoc()){
    $users[] = $row;
}

/* ==========================
   FETCH LAPTOPS
========================== */
$laptops = [];
$result2 = $conn->query("
    SELECT l.*, u.full_name 
    FROM laptops l
    LEFT JOIN users u ON l.assigned_to = u.id
    ORDER BY l.created_at DESC
");
while($row = $result2->fetch_assoc()){
    $laptops[] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Assign Devices</title>
<style>
body { font-family: Arial; background:#f4f6f9; margin:0; padding:2rem; }
h1 { color:#b08116; display:flex; justify-content:space-between; align-items:center; }
table { width:100%; border-collapse:collapse; margin-top:20px; box-shadow:0 2px 6px rgba(0,0,0,0.1); }
th, td { padding:12px; border:1px solid #ccc; text-align:center; }
th { background:#99bb4f; color:white; }
button { padding:6px 12px; border:none; border-radius:5px; cursor:pointer; }
.assign { background:#28a745; color:white; }
.unassign { background:#dc3545; color:white; }
.status-Available { color:green; font-weight:bold; }
.status-Assigned { color:blue; font-weight:bold; }
.status-Retired { color:orange; font-weight:bold; }
.status-Disposed { color:red; font-weight:bold; }
.logout { background:#dc3545; color:white; padding:8px 12px; text-decoration:none; border-radius:5px; }
.message { color:green; font-weight:bold; margin-bottom:10px; }
.modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; }
.modal-content { background:white; padding:20px; border-radius:8px; min-width:300px; text-align:center; }
.close-btn { float:right; cursor:pointer; color:#dc3545; font-weight:bold; }
select, input { padding:5px; border-radius:5px; border:1px solid #ccc; }
</style>

<script>
function openModal(id){
    document.getElementById('modal-'+id).style.display='flex';
}
function closeModal(id){
    document.getElementById('modal-'+id).style.display='none';
}
</script>

</head>
<body>

<h1>Assign Devices
<a href="logout.php" class="logout">Logout</a>
</h1>

<?php if($message): ?>
<p class="message"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<table>
<tr>
<th>Asset Tag</th>
<th>Serial</th>
<th>Brand</th>
<th>Model</th>
<th>Status</th>
<th>Assigned To</th>
<th>Action</th>
</tr>

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

<!-- Assign Form -->
<form method="POST" style="margin-bottom:5px">
<input type="hidden" name="laptop_id" value="<?= $lap['id'] ?>">
<select name="user_id" required>
<option value="">Select User</option>
<?php foreach($users as $user): ?>
<option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?> (<?= $user['role'] ?>)</option>
<?php endforeach; ?>
</select>
<button type="submit" name="assign_device" class="assign">Assign</button>
</form>

<!-- Check Condition Button -->
<button onclick="openModal(<?= $lap['id'] ?>)">Check Condition</button>

<div class="modal" id="modal-<?= $lap['id'] ?>">
<div class="modal-content">
<span class="close-btn" onclick="closeModal(<?= $lap['id'] ?>)">×</span>
<h3>Check Laptop Condition</h3>
<form method="POST">
<input type="hidden" name="laptop_id" value="<?= $lap['id'] ?>">
<p>Select current condition:</p>
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
<form method="POST">
<input type="hidden" name="laptop_id" value="<?= $lap['id'] ?>">
<button type="submit" name="unassign_device" class="unassign">Unassign</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>

</table>

</body>
</html>
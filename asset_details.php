<?php
require 'db.php';
session_start();

/* =====================================================
   VALIDATE INPUT
===================================================== */
if(!isset($_GET['id'], $_GET['category'])){
    die("<h2 style='color:red;'>❌ Invalid request: No asset selected.</h2>");
}

$asset_id = intval($_GET['id']);
$category = strtolower($_GET['category']);

/* =====================================================
   DETERMINE TABLE BASED ON CATEGORY
===================================================== */
$tables = ['laptop'=>'laptops','phone'=>'phones','desktop'=>'desktops'];
if(!isset($tables[$category])){
    die("<h2 style='color:red;'>❌ Invalid asset category.</h2>");
}
$table = $tables[$category];

/* =====================================================
   FETCH ASSET DETAILS
===================================================== */
$stmt = $conn->prepare("
    SELECT a.*, u.full_name 
    FROM $table a
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE a.id=?
");
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0){
    die("<h2 style='color:red;'>❌ Asset not found.</h2>");
}
$asset = $result->fetch_assoc();

/* =====================================================
   FETCH ASSET HISTORY
===================================================== */
$history_stmt = $conn->prepare("
    SELECT h.*, u.full_name AS user, a.full_name AS admin
    FROM asset_history h
    LEFT JOIN users u ON h.user_id = u.id
    LEFT JOIN users a ON h.admin_id = a.id
    WHERE h.asset_id = ?
    ORDER BY h.action_date DESC
");
$history_stmt->bind_param("i", $asset_id);
$history_stmt->execute();
$history = $history_stmt->get_result();x
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Asset Details - <?= htmlspecialchars($asset['asset_tag'] ?? '') ?></title>
<style>
body{font-family:Segoe UI, sans-serif;background:#f4f6f9;padding:20px;}
.back-btn{display:inline-block;margin-bottom:20px;color:#b08116;font-weight:bold;text-decoration:none;}
.back-btn:hover{text-decoration:underline;}
.asset-card{background:white;padding:20px;border-radius:10px;box-shadow:0 3px 10px rgba(0,0,0,0.1);margin-bottom:30px;transition:0.2s;}
.asset-card:hover{transform:translateY(-3px);}
.badge{padding:5px 10px;border-radius:5px;color:white;font-size:13px;font-weight:bold;}
.available{background:#99bb4f;}
.issued{background:#e74c3c;}
.timeline{position:relative;margin-top:20px;}
.timeline::before{content:'';position:absolute;left:20px;top:0;width:4px;height:100%;background:#b08116;}
.event{position:relative;margin-left:60px;margin-bottom:20px;background:white;padding:15px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);transition:0.2s;}
.event:hover{transform:translateX(5px);}
.event::before{content:'';position:absolute;left:-40px;top:15px;width:15px;height:15px;background:#99bb4f;border-radius:50%;}
h2{margin-bottom:10px;color:#333;}
h3{color:#b08116;}
@media(max-width:600px){.event{margin-left:40px;}.timeline::before{left:10px;}.event::before{left:-30px;}}
</style>
</head>
<body>

<a href="javascript:history.back()" class="back-btn">← Back</a>

<div class="asset-card">
<h2><?= htmlspecialchars($asset['asset_tag']) ?> (<?= ucfirst($category) ?>)</h2>
<p><b>Brand:</b> <?= htmlspecialchars($asset['brand']) ?></p>
<p><b>Model:</b> <?= htmlspecialchars($asset['model']) ?></p>
<p><b>Status:</b> 
<span class="badge <?= !empty($asset['assigned_to']) ? 'issued':'available' ?>">
<?= !empty($asset['assigned_to']) ? 'Issued':'Available' ?>
</span>
</p>
<p><b>Current Holder:</b> <?= htmlspecialchars($asset['full_name'] ?? 'None') ?></p>
</div>

<h3>Asset Timeline</h3>
<div class="timeline">
<?php if($history->num_rows > 0): ?>
    <?php while($row = $history->fetch_assoc()): ?>
    <div class="event">
        <b><?= htmlspecialchars($row['action_type']) ?></b><br>
        👤 User: <?= htmlspecialchars($row['user'] ?? 'N/A') ?><br>
        🛠 Admin: <?= htmlspecialchars($row['admin'] ?? 'N/A') ?><br>
        <small>📅 <?= htmlspecialchars($row['action_date']) ?></small>
    </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="event">No history available for this asset.</div>
<?php endif; ?>
</div>

</body>
</html>
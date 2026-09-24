<?php
require 'db.php';
session_start();

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin'){
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$message = "";

/* =========================
AJAX: LOAD USER ASSETS
========================= */
if(isset($_GET['fetch_assets'])){
    $user_id = intval($_GET['user_id']);

    echo "<h4>User Assets</h4>";

    /* ===============================
       LAPTOPS (FROM ACCESSORIES TABLE)
    =============================== */
    $sql = "
        SELECT l.id, l.asset_tag, la.mouse_given, la.charger_given
        FROM laptop_accessories la
        INNER JOIN laptops l ON la.laptop_id = l.id
        WHERE la.user_id = $user_id
    ";

    $res = $conn->query($sql);

    while($row = $res->fetch_assoc()){
        echo "
        <div>
            <input type='radio' name='asset_select' value='laptops-{$row['id']}' required>
            Laptop - {$row['asset_tag']}
            <small style='color:gray'>
                (Mouse: ".($row['mouse_given'] ? 'Yes' : 'No').",
                 Charger: ".($row['charger_given'] ? 'Yes' : 'No').")
            </small>
        </div>";
    }

    /* ===============================
       PHONES
    =============================== */
    $res = $conn->query("SELECT id, asset_tag FROM phones WHERE assigned_to=$user_id");
    while($row=$res->fetch_assoc()){
        echo "
        <div>
            <input type='radio' name='asset_select' value='phones-{$row['id']}'>
            Phone - {$row['asset_tag']}
        </div>";
    }

    /* ===============================
       DESKTOPS
    =============================== */
    $res = $conn->query("SELECT id, asset_tag FROM desktops WHERE assigned_to=$user_id");
    while($row=$res->fetch_assoc()){
        echo "
        <div>
            <input type='radio' name='asset_select' value='desktops-{$row['id']}'>
            Desktop - {$row['asset_tag']}
        </div>";
    }

    exit();
}

/* =========================
RETURN LOGIC
========================= */
if(isset($_POST['return_asset'])){
    list($table,$asset_id) = explode('-', $_POST['asset_select']);
    $user_id = intval($_POST['user_id']);
    $condition = $_POST['condition'];
    $reason = $_POST['reason'];
    $remarks = $_POST['remarks'];

    /* =========================
       CHECK ACCESSORIES (LAPTOP ONLY)
    ========================== */
    if($table == 'laptops'){

        $check = $conn->query("
            SELECT mouse_given, charger_given, mouse_returned, charger_returned 
            FROM laptop_accessories 
            WHERE laptop_id=$asset_id AND user_id=$user_id
        ");

        $acc = $check->fetch_assoc();

        if(($acc['mouse_given'] && !$acc['mouse_returned']) || 
           ($acc['charger_given'] && !$acc['charger_returned'])){
            
            $message = "❌ Cannot return: Accessories missing!";
        } else {

            // Update laptop
            $conn->query("UPDATE laptops SET assigned_to=NULL, status='$condition' WHERE id=$asset_id");

            // Update accessories return log
            $conn->query("
                UPDATE laptop_accessories 
                SET return_condition='$condition', returned_at=NOW() 
                WHERE laptop_id=$asset_id AND user_id=$user_id
            ");

            // History
            $stmt = $conn->prepare("
                INSERT INTO laptop_history (laptop_id,user_id,admin_id,action_type) 
                VALUES (?,?,?,?)
            ");
            $action = "Returned - $reason ($condition)";
            $stmt->bind_param("iiis", $asset_id,$user_id,$admin_id,$action);
            $stmt->execute();

            $message = "✅ Laptop returned successfully";
        }

    } else {

        // PHONES & DESKTOPS
        $conn->query("UPDATE $table SET assigned_to=NULL, status='$condition' WHERE id=$asset_id");

        $history_table = $table.'_history';
        $column = rtrim($table,'s')."_id";

        $stmt = $conn->prepare("
            INSERT INTO $history_table ($column,user_id,admin_id,action_type) 
            VALUES (?,?,?,?)
        ");

        $action = "Returned - $reason ($condition)";
        $stmt->bind_param("iiis", $asset_id,$user_id,$admin_id,$action);
        $stmt->execute();

        $message = "✅ Asset returned successfully";
    }
}

/* =========================
REPLACEMENT LOGIC
========================= */
if(isset($_POST['replace_asset'])){
    $user_id = intval($_POST['user_id']);
    list($table,$asset_id) = explode('-', $_POST['new_asset']);

    /* =========================
       CHECK ANY ACTIVE ASSETS
    ========================== */
    $check = $conn->query("
        SELECT id FROM laptops WHERE assigned_to=$user_id
        UNION
        SELECT id FROM phones WHERE assigned_to=$user_id
        UNION
        SELECT id FROM desktops WHERE assigned_to=$user_id
    ");

    if($check->num_rows > 0){
        $message = "❌ User still has active assets!";
    } else {

        /* =========================
           CHECK UNRETURNED ACCESSORIES
        ========================== */
        $accCheck = $conn->query("
            SELECT * FROM laptop_accessories 
            WHERE user_id=$user_id 
            AND (mouse_given=1 AND mouse_returned=0 
                 OR charger_given=1 AND charger_returned=0)
        ");

        if($accCheck->num_rows > 0){
            $message = "❌ Cannot replace: Accessories not returned!";
        } else {

            // Assign new asset
            $conn->query("UPDATE $table SET assigned_to=$user_id, status='Issued' WHERE id=$asset_id");

            $history_table = $table.'_history';
            $column = rtrim($table,'s')."_id";

            $stmt = $conn->prepare("
                INSERT INTO $history_table ($column,user_id,admin_id,action_type) 
                VALUES (?,?,?,?)
            ");

            $action = "Replaced";
            $stmt->bind_param("iiis", $asset_id,$user_id,$admin_id,$action);
            $stmt->execute();

            $message = "✅ Replacement successful";
        }
    }
}

$users = $conn->query("SELECT id, full_name FROM users WHERE role='user'");

$laptops = $conn->query("SELECT id, asset_tag FROM laptops WHERE assigned_to IS NULL");
$phones = $conn->query("SELECT id, asset_tag FROM phones WHERE assigned_to IS NULL");
$desktops = $conn->query("SELECT id, asset_tag FROM desktops WHERE assigned_to IS NULL");
?>

<!DOCTYPE html>
<html>
<head>
<title>Smart Asset Signout</title>
<style>
body{
    font-family:Segoe UI;
    background:#f4f6f9;
    margin:0;
}

.container{
    max-width:1100px;
    margin:30px auto;
}

h2{
    color:#333;
}

.tabs{
    display:flex;
    gap:15px;
    margin-bottom:20px;
}

.tabs button{
    padding:12px 25px;
    border:none;
    background:linear-gradient(135deg,#99bb4f,#b08116);
    color:white;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

.section{
    display:none;
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 4px 12px rgba(0,0,0,0.08);
}

input,select,textarea{
    width:100%;
    padding:12px;
    margin:10px 0;
    border:1px solid #ddd;
    border-radius:8px;
}

button.submit{
    background:linear-gradient(135deg,#99bb4f,#b08116);
    color:white;
    padding:12px;
    border:none;
    border-radius:8px;
    cursor:pointer;
    width:100%;
}

.asset-box{
    background:#f9fafb;
    padding:15px;
    border-radius:10px;
    margin-top:10px;
    border:1px solid #eee;
}

.asset-box div{
    padding:8px;
    border-bottom:1px solid #eee;
}

.asset-box div:last-child{
    border-bottom:none;
}

label{
    display:block;
    margin-top:10px;
}
</style>
<script>
function showTab(id){
    document.querySelectorAll('.section').forEach(s=>s.style.display='none');
    document.getElementById(id).style.display='block';
}

function loadAssets(userId){
    fetch('?fetch_assets=1&user_id='+userId)
    .then(res=>res.text())
    .then(data=>{
        document.getElementById('userAssets').innerHTML = data;
    });
}
</script>
</head>
<body>
<div class="container">
<h2>Smart Asset Sign Out</h2>

<?php if($message) echo "<p><b>$message</b></p>"; ?>

<div class="tabs">
<button onclick="showTab('return')">Return Asset</button>
<button onclick="showTab('replace')">Replacement</button>
</div>

<!-- RETURN -->
<div id="return" class="section">
<h3>Return Asset</h3>
<form method="POST">
<select name="user_id" onchange="loadAssets(this.value)" required>
<option>Select User</option>
<?php while($u=$users->fetch_assoc()){ ?>
<option value="<?= $u['id'] ?>"><?= $u['full_name'] ?></option>
<?php } ?>
</select>

<div id="userAssets" class="asset-box">Select user to load assets</div>

<select name="reason">
<option>Retirement</option>
<option>Contract End</option>
<option>Attachment End</option>
<option>Internship End</option>
</select>

<select name="condition">
<option>Good</option>
<option>Faulty</option>
<option>Damaged</option>
<option>Lost</option>
</select>

<textarea name="remarks" placeholder="Remarks"></textarea>

<button class="submit" name="return_asset">Return Asset</button>
</form>
</div>

<!-- REPLACEMENT -->
<div id="replace" class="section">
<h3>Replacement</h3>
<form method="POST">
<select name="user_id" required>
<option>Select User</option>
<?php $users->data_seek(0); while($u=$users->fetch_assoc()){ ?>
<option value="<?= $u['id'] ?>"><?= $u['full_name'] ?></option>
<?php } ?>
</select>

<label><input type="checkbox" required> Confirm all assets returned</label>

<select name="new_asset" required>
<option>Select New Asset</option>
<?php while($l=$laptops->fetch_assoc()){ ?>
<option value="laptops-<?= $l['id'] ?>">Laptop - <?= $l['asset_tag'] ?></option>
<?php } ?>
<?php while($p=$phones->fetch_assoc()){ ?>
<option value="phones-<?= $p['id'] ?>">Phone - <?= $p['asset_tag'] ?></option>
<?php } ?>
<?php while($d=$desktops->fetch_assoc()){ ?>
<option value="desktops-<?= $d['id'] ?>">Desktop - <?= $d['asset_tag'] ?></option>
<?php } ?>
</select>

<button class="submit" name="replace_asset">Assign Replacement</button>
</form>
</div>

</div>
</body>
</html>
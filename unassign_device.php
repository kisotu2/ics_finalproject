<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

if(!isset($_SESSION['role']) || 
   ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'super_admin')){
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$message = "";

/* ==========================
   HANDLE UNASSIGN PROCESS
========================== */
if(isset($_POST['process_unassign'])) {

    $conn->begin_transaction();

    try {
        $laptop_id = intval($_POST['laptop_id']);
        $user_id   = intval($_POST['user_id']);
        $condition = $_POST['condition'];
        $notes     = trim($_POST['notes']);

        $charger = isset($_POST['charger']) ? 1 : 0;
        $bag     = isset($_POST['bag']) ? 1 : 0;
        $mouse   = isset($_POST['mouse']) ? 1 : 0;

        // Validate laptop assignment
        $check = $conn->prepare("SELECT id, asset_tag FROM laptops WHERE id=? AND assigned_to=?");
        $check->bind_param("ii", $laptop_id, $user_id);
        $check->execute();
        $res = $check->get_result();

        if($res->num_rows == 0){
            throw new Exception("Invalid laptop assignment.");
        }
        $laptop_data = $res->fetch_assoc();

        // Determine status
        if($condition == "faulty"){
            $status = "Faulty";
            $action = "Unassigned (Faulty)";
        } else {
            $status = "Active";
            $action = "Unassigned (Good)";
        }

        // Update laptop
        $stmt = $conn->prepare("UPDATE laptops SET assigned_to=NULL, status=? WHERE id=?");
        $stmt->bind_param("si", $status, $laptop_id);
        $stmt->execute();

        // Save return details
        $stmt2 = $conn->prepare("INSERT INTO returns (laptop_id, user_id, charger, bag, mouse, notes, returned_at) VALUES (?,?,?,?,?,?,NOW())");
        $stmt2->bind_param("iiiiis", $laptop_id, $user_id, $charger, $bag, $mouse, $notes);
        $stmt2->execute();

        // Record history
        $stmt3 = $conn->prepare("INSERT INTO laptop_history (laptop_id, user_id, admin_id, action_type, action_date) VALUES (?,?,?,?,NOW())");
        $stmt3->bind_param("iiis", $laptop_id, $user_id, $admin_id, $action);
        $stmt3->execute();

        $conn->commit();
        $message = "✅ Device successfully unassigned.";

        // ==========================
        // SEND EMAIL NOTIFICATION
        // ==========================
        $user_res = $conn->prepare("SELECT full_name, email FROM users WHERE id=?");
        $user_res->bind_param("i", $user_id);
        $user_res->execute();
        $user_result = $user_res->get_result();
        $user = $user_result->fetch_assoc();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('ASSET_MAIL_USERNAME') ?: '';
            $mail->Password   = getenv('ASSET_MAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom(getenv('ASSET_MAIL_FROM') ?: 'noreply@example.invalid', 'IRA Asset Management System');
            $mail->addAddress($user['email'], $user['full_name']);

            $mail->isHTML(true);
            $mail->Subject = 'Laptop Return Confirmation';
            $mail->Body    = "
                <h3>IRA Asset Management System</h3>
                <p>Hello {$user['full_name']},</p>
                <p>Your laptop <b>{$laptop_data['asset_tag']}</b> has been returned successfully.</p>
                <p>Condition: <b>{$condition}</b></p>
                <p>Accessories returned: ".
                    ($charger ? "Charger " : "").($bag ? "Bag " : "").($mouse ? "Mouse " : "")."
                </p>
                <br>
                <small>If you did not return this device, contact the admin immediately.</small>
            ";
            $mail->send();

        } catch(Exception $e){
            $message .= " ⚠️ Email notification failed: {$mail->ErrorInfo}";
        }

    } catch(Exception $e){
        $conn->rollback();
        $message = "❌ Error: " . $e->getMessage();
    }
}

/* ==========================
   FETCH ASSIGNED LAPTOPS
========================== */
$result = $conn->query("
    SELECT l.*, u.id as user_id, u.full_name, u.email 
    FROM laptops l 
    JOIN users u ON l.assigned_to = u.id 
    WHERE l.assigned_to IS NOT NULL
    ORDER BY l.id DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Unassign Devices</title>
<style>
body{font-family:'Segoe UI';background:linear-gradient(180deg,#99bb4f,#b08116);padding:30px;}
h1{text-align:center;color:white;margin-bottom:20px;}
.container{background:white;padding:25px;border-radius:10px;max-width:1100px;margin:auto;box-shadow:0 10px 30px rgba(0,0,0,0.2);}
table{width:100%;border-collapse:collapse;}
th{background:#b08116;color:white;padding:12px;}
td{padding:12px;border-bottom:1px solid #ddd;}
tr:hover{background:#f9f9f9;}
button{padding:8px 12px;border:none;border-radius:5px;cursor:pointer;}
/* Button */
.process-btn {
  background: linear-gradient(90deg,#007bff,#0056b3);
  color: white;
  border-radius: 8px;
  padding: 10px 18px;
  font-weight: 500;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: 0.3s;
}
.process-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0,123,255,0.4);
}

/* Modal */
.modal {
  display: none;
  position: fixed;
  top:0; left:0;
  width:100%; height:100%;
  background: rgba(0,0,0,0.5);
  justify-content: center;
  align-items: center;
  z-index: 1000;
}
.modal-content {
  background: #fff;
  padding: 25px 30px;
  border-radius: 12px;
  width: 420px;
  max-width: 90%;
  position: relative;
  box-shadow: 0 10px 30px rgba(0,0,0,0.15);
  animation: fadeIn 0.3s ease-out;
}
@keyframes fadeIn {
  from {opacity:0; transform: translateY(-10px);}
  to {opacity:1; transform: translateY(0);}
}

/* Close button */
.close {
  position: absolute;
  top: 12px; right: 15px;
  font-size: 20px;
  font-weight: bold;
  cursor: pointer;
  color: #ff4d4f;
}
.close:hover { color: #ff1a1a; }

/* Form inside modal */
.modal-form {
  display: flex;
  flex-direction: column;
  gap: 15px;
}
.section-title {
  font-weight: 600;
  margin-bottom: 6px;
  color: #333;
}
.checkbox {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
  margin-bottom: 6px;
  cursor: pointer;
}
.checkbox input {
  accent-color: #b08116; /* modern checkbox color */
  width: 16px; height: 16px;
}

/* Select & textarea */
select, textarea {
  width: 100%;
  padding: 10px;
  border-radius: 6px;
  border: 1px solid #ccc;
  font-size: 14px;
  transition: 0.3s;
}
select:focus, textarea:focus {
  outline: none;
  border-color: #b08116;
  box-shadow: 0 0 0 2px rgba(176,129,22,0.2);
}
textarea { resize: vertical; min-height: 60px; }

/* Submit button */
.submit-btn {
  background: linear-gradient(90deg,#28a745,#1e7e34);
  color: #fff;
  border-radius: 8px;
  padding: 12px 0;
  font-weight: 600;
  font-size: 15px;
  border: none;
  cursor: pointer;
  transition: 0.3s;
}
.submit-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(40,167,69,0.4);
}

</style>

<script>
function openModal(id){document.getElementById('modal-'+id).style.display='flex';}
function closeModal(id){document.getElementById('modal-'+id).style.display='none';}

// Fade out message after 5 seconds
window.addEventListener('DOMContentLoaded',()=> {
    const msg = document.querySelector('.message');
    if(msg){
        setTimeout(()=>{
            msg.style.transition="opacity 0.5s";
            msg.style.opacity=0;
            setTimeout(()=>msg.remove(),500);
        },5000);
    }
});
</script>
</head>
<body>

<h1>🔄 Unassign Devices</h1>
<div class="container">

<?php if($message): ?>
<div class="message"><?= $message ?></div>
<?php endif; ?>

<table>
<tr>
<th>Asset</th>
<th>User</th>
<th>Accessories</th>
<th>Action</th>
</tr>

<?php while($row=$result->fetch_assoc()): ?>
<tr>
<td><b><?= htmlspecialchars($row['asset_tag']) ?></b><br><?= htmlspecialchars($row['brand']) ?> - <?= htmlspecialchars($row['model']) ?></td>
<td><?= htmlspecialchars($row['full_name']) ?><br><small><?= htmlspecialchars($row['email']) ?></small></td>
<td>
<?php
$acc = $conn->query("SELECT mouse_given, charger_given, bag_given FROM laptop_accessories WHERE laptop_id=".$row['id']." ORDER BY id DESC LIMIT 1");
if($acc && $acc->num_rows>0){
    $a=$acc->fetch_assoc();
    if($a['mouse_given']) echo "• Mouse<br>";
    if($a['charger_given']) echo "• Charger<br>";
    if($a['bag_given']) echo "• Bag<br>";
}else{ echo "None"; }
?>
</td>
<td>
  <!-- Modern Process Button -->
  <button class="process-btn" onclick="openModal(<?= $row['id'] ?>)">
    <i class="fa fa-undo-alt"></i> Process Return
  </button>

  <!-- Modal -->
  <div class="modal" id="modal-<?= $row['id'] ?>">
    <div class="modal-content">
      <span class="close" onclick="closeModal(<?= $row['id'] ?>)">×</span>
      <h3>Return Asset - <?= htmlspecialchars($row['asset_tag']) ?></h3>

      <form method="POST" class="modal-form">
        <input type="hidden" name="laptop_id" value="<?= $row['id'] ?>">
        <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">

        <!-- Accessories -->
        <div class="form-section">
          <p class="section-title">Accessories Returned</p>
          <label class="checkbox">
            <input type="checkbox" name="charger"> Charger
          </label>
          <label class="checkbox">
            <input type="checkbox" name="bag"> Bag
          </label>
          <label class="checkbox">
            <input type="checkbox" name="mouse"> Mouse
          </label>
        </div>

        <!-- Condition -->
        <div class="form-section">
          <label class="section-title">Condition</label>
          <select name="condition">
            <option value="good">Good</option>
            <option value="faulty">Faulty</option>
          </select>
        </div>

        <!-- Notes -->
        <div class="form-section">
          <label class="section-title">Notes</label>
          <textarea name="notes" placeholder="Optional notes..."></textarea>
        </div>

        <!-- Submit -->
        <button type="submit" name="process_unassign" class="submit-btn">
          Confirm Return
        </button>
      </form>
    </div>
  </div>
</td>
</tr>
<?php endwhile; ?>

</table>
</div>
</body>
</html>

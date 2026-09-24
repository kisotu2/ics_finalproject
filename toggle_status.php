<?php
require 'db.php';
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') exit();

$id = $_GET['id'];
$result = $conn->query("SELECT status FROM users WHERE id=$id AND role!='super_admin'");
if($row = $result->fetch_assoc()){
    $new_status = $row['status'] == 'active' ? 'inactive':'active';
    $conn->query("UPDATE users SET status='$new_status' WHERE id=$id");
}
header("Location: super_dashboard.php");
?>
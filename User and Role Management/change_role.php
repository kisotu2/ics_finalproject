<?php
require 'db.php';
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') exit();

$id = $_GET['id'];
$new_role = $_GET['role'];
if(in_array($new_role,['admin','user'])){
    $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=? AND role!='super_admin'");
    $stmt->bind_param("si",$new_role,$id);
    $stmt->execute();
}
header("Location: super_dashboard.php");
?>
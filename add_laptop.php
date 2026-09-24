<?php
require 'db.php';
session_start();

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $db = getDB();

    $stmt = $db->prepare("INSERT INTO laptops
        (asset_tag, serial_number, brand, model, department, assigned_to, status, purchase_date, warranty_expiry)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    try {
        $stmt->execute([
            $_POST['asset_tag'],
            $_POST['serial_number'],
            $_POST['brand'],
            $_POST['model'],
            $_POST['department'],
            $_POST['assigned_to'],
            $_POST['status'],
            $_POST['purchase_date'],
            $_POST['warranty_expiry']
        ]);

        $_SESSION['flash'] = "Laptop added successfully!";
        $_SESSION['flash_type'] = "flash-success";

    } catch (PDOException $e) {
        $_SESSION['flash'] = "Error: ".$e->getMessage();
        $_SESSION['flash_type'] = "flash-error";
    }

    header("Location: admin.php");
    exit();
}
?>
<?php
// edit_laptop.php
require 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $db = getDB();

    $stmt = $db->prepare("
        UPDATE laptops SET
            asset_tag = ?, serial_number = ?, brand = ?, model = ?,
            department = ?, assigned_to = ?, status = ?, purchase_date = ?, warranty_expiry = ?
        WHERE id = ?
    ");

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
            $_POST['warranty_expiry'],
            $id
        ]);

        $_SESSION['flash'] = "Laptop updated successfully!";
        $_SESSION['flash_type'] = "flash-success";

    } catch (PDOException $e) {
        $_SESSION['flash'] = "Error updating laptop: " . $e->getMessage();
        $_SESSION['flash_type'] = "flash-error";
    }

    header("Location: admin.php");
    exit();
}
?>
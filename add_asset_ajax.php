<?php
require 'db.php';
$response = ['success'=>false,'message'=>'','id'=>null];

$asset_tag = trim($_POST['asset_tag']);
$serial = trim($_POST['serial_number']);
$brand = $_POST['brand'];
$model = $_POST['model'];
$status = $_POST['status'];
$category = $_POST['category'];

$tables = ['Laptop'=>'laptops','Phone'=>'phones','Desktop'=>'desktops'];
if(!isset($tables[$category])){
    $response['message'] = 'Invalid category';
    echo json_encode($response); exit;
}

$table = $tables[$category];

// CHECK DUPLICATE
$stmt = $conn->prepare("SELECT id FROM $table WHERE asset_tag=? OR serial_number=?");
$stmt->bind_param("ss",$asset_tag,$serial);
$stmt->execute();
$stmt->store_result();
if($stmt->num_rows > 0){
    $response['message'] = "Asset already exists!";
    echo json_encode($response); exit;
}

// INSERT ASSET
$stmt = $conn->prepare("INSERT INTO $table(asset_tag,serial_number,brand,model,status,assigned_to) VALUES(?,?,?,?,?,NULL)");
$stmt->bind_param("sssss",$asset_tag,$serial,$brand,$model,$status);
if($stmt->execute()){
    $response['success'] = true;
    $response['message'] = "Asset added successfully!";
    $response['id'] = $stmt->insert_id;
} else {
    $response['message'] = "Database error!";
}
echo json_encode($response);
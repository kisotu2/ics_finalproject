<?php
require __DIR__.'/bootstrap.php';
require_login(['admin','super_admin']);
header('Content-Type: application/json; charset=utf-8');
$result=$conn->query('SELECT l.id,l.asset_tag,l.serial_number,l.brand,l.model,l.department,l.status,l.purchase_date,l.warranty_expiry,u.full_name AS assigned_to FROM laptops l LEFT JOIN users u ON u.id=l.assigned_to ORDER BY l.asset_tag');
$assets=[];while($row=$result->fetch_assoc())$assets[]=$row;
audit($conn,'asset_api_view','asset_api');
echo json_encode(['data'=>$assets],JSON_UNESCAPED_SLASHES);

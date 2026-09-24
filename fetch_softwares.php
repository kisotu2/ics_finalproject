<?php
require 'db.php';

$today = date("Y-m-d");
$warning = date("Y-m-d", strtotime("+30 days"));

$where = "WHERE 1=1";

if(!empty($_GET['search'])){
    $search = $conn->real_escape_string($_GET['search']);
    $where .= " AND (software_name LIKE '%$search%' OR vendor LIKE '%$search%')";
}

if(!empty($_GET['status'])){
    if($_GET['status']=="active"){
        $where .= " AND expiry_date >= '$today'";
    }
    elseif($_GET['status']=="expired"){
        $where .= " AND expiry_date < '$today'";
    }
    elseif($_GET['status']=="expiring"){
        $where .= " AND expiry_date BETWEEN '$today' AND '$warning'";
    }
}

$result = $conn->query("SELECT * FROM softwares $where ORDER BY expiry_date ASC");

while($row = $result->fetch_assoc()){
    $status = "Active";
    $class = "active-text";

    if($row['expiry_date'] < $today){
        $status = "Expired";
        $class = "expired-text";
    }

    echo "<tr>
        <td>{$row['id']}</td>
        <td>{$row['software_name']}</td>
        <td>{$row['vendor']}</td>
        <td>{$row['license_type']}</td>
        <td>{$row['total_licenses']}</td>
        <td>{$row['used_licenses']}</td>
        <td>{$row['expiry_date']}</td>
        <td class='$class'>$status</td>
    </tr>";
}
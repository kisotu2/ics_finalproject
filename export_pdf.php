<?php
require 'db.php';
require_once(__DIR__ . '/tcpdf/src/Tcpdf.php');

$pdf = new TCPDF();
$pdf->AddPage();

$html = "<h2>Laptop Assignment History</h2>
<table border='1' cellpadding='5'>
<tr>
<th>Asset</th>
<th>User</th>
<th>Admin</th>
<th>Action</th>
<th>Date</th>
</tr>";

$result = $conn->query("
SELECT h.*, l.asset_tag, 
       u.full_name AS user_name,
       a.full_name AS admin_name
FROM laptop_history h
LEFT JOIN laptops l ON h.laptop_id=l.id
LEFT JOIN users u ON h.user_id=u.id
LEFT JOIN users a ON h.admin_id=a.id
ORDER BY h.action_date DESC
");

while($row = $result->fetch_assoc()){
$html .= "<tr>
<td>{$row['asset_tag']}</td>
<td>{$row['user_name']}</td>
<td>{$row['admin_name']}</td>
<td>{$row['action_type']}</td>
<td>{$row['action_date']}</td>
</tr>";
}

$html .= "</table>";

$pdf->writeHTML($html);
$pdf->Output('history.pdf','I');
?>
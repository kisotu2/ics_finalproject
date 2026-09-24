<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=software-licence-report.csv');
$out = fopen('php://output', 'w');
fputcsv($out, ['Software', 'Vendor', 'Version', 'Licence type', 'Total seats', 'Assigned seats', 'Available seats', 'Expiry date', 'Status']);
$result = $conn->query('SELECT s.*, COUNT(sa.id) AS used_licenses FROM softwares s LEFT JOIN software_assignments sa ON sa.software_id=s.id AND sa.revoked_at IS NULL GROUP BY s.id ORDER BY s.software_name, s.vendor');
while ($row = $result->fetch_assoc()) { $available = (int) $row['total_licenses'] - (int) $row['used_licenses']; fputcsv($out, [$row['software_name'], $row['vendor'], $row['version'], $row['license_type'], $row['total_licenses'], $row['used_licenses'], $available, $row['expiry_date'], $row['status']]); }
fclose($out);

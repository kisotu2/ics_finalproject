<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
$report = $conn->query("SELECT s.id,s.software_name,s.vendor,s.version,s.license_type,s.total_licenses,s.expiry_date,s.status,COUNT(sa.id) used_licenses FROM softwares s LEFT JOIN software_assignments sa ON sa.software_id=s.id AND sa.revoked_at IS NULL GROUP BY s.id ORDER BY s.software_name,s.vendor");
layout_start('Software reports');
?>
<section class="hero"><div><div class="eyebrow">REPORTING</div><h1>Licence utilisation</h1><p>Review capacity and expiry risk across the complete software estate.</p></div><div><a class="button secondary" href="export_softwares_excel.php">Download CSV</a> <a class="button secondary" href="export_softwares_pdf.php">Print report</a></div></section>
<section class="panel"><div class="table-wrapper"><table><thead><tr><th>Software</th><th>Type</th><th>Seats used</th><th>Available</th><th>Expiry</th><th>Status</th></tr></thead><tbody><?php while ($item = $report->fetch_assoc()): $available = (int) $item['total_licenses'] - (int) $item['used_licenses']; $expired = $item['expiry_date'] && $item['expiry_date'] < date('Y-m-d'); ?><tr><td><a href="software_details.php?id=<?= $item['id'] ?>"><strong><?= e($item['software_name']) ?></strong><br><small><?= e(trim($item['vendor'] . ' ' . ($item['version'] ?? ''))) ?></small></a></td><td><?= e($item['license_type']) ?></td><td><?= e((string) $item['used_licenses']) ?> / <?= e((string) $item['total_licenses']) ?></td><td><?= e((string) $available) ?></td><td><?= e($item['expiry_date'] ?: 'No expiry') ?></td><td><span class="status-badge <?= $expired || $item['status'] !== 'Active' ? 'status-inactive' : 'status-active' ?>"><?= e($expired ? 'Expired' : $item['status']) ?></span></td></tr><?php endwhile; ?></tbody></table></div></section>
<?php layout_end();

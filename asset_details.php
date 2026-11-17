<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id && strtolower((string) ($_GET['category'] ?? '')) === 'laptop') {
    header('Location: maintenance_risk.php?laptop_id=' . $id, true, 302);
    exit;
}
flash('This application currently manages laptop assets.');
header('Location: dashboard.php', true, 302);
exit;

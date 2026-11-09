<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

$laptopId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($laptopId === false || $laptopId === null || $laptopId < 1) {
    http_response_code(400);
    exit('Invalid laptop identifier.');
}

header('Location: add_laptop.php?id=' . $laptopId);
exit;

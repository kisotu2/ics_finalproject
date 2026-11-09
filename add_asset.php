<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

header('Location: add_laptop.php');
exit;

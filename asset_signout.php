<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
header('Location: unassign_device.php', true, 302);
exit;

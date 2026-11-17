<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login(['super_admin']);
header('Location: dashboard.php', true, 302);
exit;

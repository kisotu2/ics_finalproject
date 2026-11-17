<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();
header('Location: my_laptop.php', true, 302);
exit;

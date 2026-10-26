<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

flash('Laptop identity and hardware details are maintained through Active Directory sync.', 'error');
header('Location: directory_sync.php');
exit;

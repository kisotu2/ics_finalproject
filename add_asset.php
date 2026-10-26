<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

flash('Manual laptop registration has been retired. Use the authorized Active Directory sync.', 'error');
header('Location: directory_sync.php');
exit;

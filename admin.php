<?php
// Legacy route retained for bookmarks; the maintained administration interface is dashboard.php.
require __DIR__.'/bootstrap.php';
require_login(['admin','super_admin']);
header('Location: dashboard.php');
exit;

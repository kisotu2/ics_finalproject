<?php
// Legacy landing route retained for existing links.
require __DIR__.'/bootstrap.php';
header('Location: '.(!empty($_SESSION['user_id']) ? 'dashboard.php' : 'login.php'));
exit;

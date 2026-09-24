<?php require __DIR__.'/bootstrap.php'; header('Location: '.(!empty($_SESSION['user_id']) ? 'dashboard.php' : 'login.php')); exit;

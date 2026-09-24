<?php
$host = $_POST['host'] ?? '127.0.0.1'; $user = $_POST['user'] ?? 'root'; $pass = $_POST['password'] ?? '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try { $db=new mysqli($host,$user,$pass);
     $sql=file_get_contents(__DIR__.'/database.sql'); 
     foreach(array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) $db->query($statement); 
     $message='Database installed. Copy config.example.php to config.php, then create the first super administrator.'; }
    catch(Throwable $e) { $message='Installation failed: '.$e->getMessage(); }
}
?><!doctype html><title>Install Asset Management</title><link rel="stylesheet" href="assets/css/app.css"><main><h1>Install Asset Management</h1><p>This creates the local MySQL schema used by the application.</p><?php if($message): ?><p class="notice"><?=htmlspecialchars($message)?></p><?php endif; ?><form method="post" class="panel"><label>MySQL host<input name="host" value="<?=htmlspecialchars($host)?>"></label><label>MySQL user<input name="user" value="<?=htmlspecialchars($user)?>"></label><label>Password<input name="password" type="password"></label><button>Install database</button></form></main>

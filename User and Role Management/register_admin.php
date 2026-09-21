<?php
require 'db.php';
session_start();

if ($_SESSION['role'] !== 'super_admin') {
    header("Location: admin.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'admin')");
    try {
        $stmt->execute([$_POST['email'], $password]);
        $message = "Admin added successfully!";
    } catch (PDOException $e) {
        $message = "Error: Email already exists.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Admin</title>
</head>
<body>

<h2>Add New Admin</h2>

<?php if(isset($message)) echo "<p>$message</p>"; ?>

<form method="POST">
<input type="email" name="email" placeholder="Admin Email" required>
<input type="password" name="password" placeholder="Password" required>
<button type="submit">Create Admin</button>
</form>

</body>
</html>
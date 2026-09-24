<?php
require 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($full_name) && !empty($email) && !empty($password)) {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $role = "user";

        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $full_name, $email, $hashedPassword, $role);

        if ($stmt->execute()) {
            header("Location: login.php");
            exit();
        } else {
            $error = "Email already exists.";
        }

        $stmt->close();
    } else {
        $error = "All fields are required.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - Asset Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(to right,#b08116,#99bb4f);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            width: 350px;
        }

        h2 {
            text-align: center;
            color: #b08116;
            margin-bottom: 1.5rem;
        }

        input {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 1rem;
        }

        button {
            width: 100%;
            padding: 10px;
            background-color: #99bb4f;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            margin-top: 10px;
        }

        button:hover {
            opacity: 0.9;
        }

        .error {
            color: red;
            text-align: center;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .login-link {
            display: block;
            text-align: center;
            margin-top: 12px;
            font-size: 0.9rem;
            color: #b08116;
            text-decoration: none;
        }

        .login-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="card">
    <h2>Register</h2>

    <?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="POST">
        <input type="text" name="full_name" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Register</button>
    </form>

    <a class="login-link" href="login.php">Already have an account? Login</a>
</div>

</body>
</html>
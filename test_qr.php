<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Handle form submission ---
$verificationMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Send Email OTP
    if (isset($_POST['send_email_otp'])) {
        $otp = random_int(100000, 999999);   // 6-digit OTP
        $_SESSION['email_otp'] = $otp;       // store OTP temporarily

        // PHPMailer setup
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';       // your SMTP server
            $mail->SMTPAuth   = true;
            $mail->Username   = 'kisotusamuel1@gmail.com'; // your email
            $mail->Password   = 'your-app-password';    // app password or SMTP password
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom('your-email@gmail.com', 'IRA Asset System');
            $mail->addAddress('user@example.com');      // recipient email
            $mail->Subject = 'Your OTP for IRA Asset System';
            $mail->Body    = "Your One-Time Password (OTP) is: $otp";

            $mail->send();
            $verificationMessage = '<p style="color:blue;">✉️ OTP sent successfully to your email.</p>';
        } catch (Exception $e) {
            $verificationMessage = "<p style='color:red;'>❌ OTP could not be sent. Mailer Error: {$mail->ErrorInfo}</p>";
        }
    }

    // Verify Email OTP
    if (!empty($_POST['email_otp'])) {
        $userOtp = $_POST['email_otp'];
        if (isset($_SESSION['email_otp']) && $_SESSION['email_otp'] == $userOtp) {
            $verificationMessage = '<p style="color:green;">✅ OTP is valid! You are authenticated.</p>';
            unset($_SESSION['email_otp']); // clear OTP after success
        } else {
            $verificationMessage = '<p style="color:red;">❌ Invalid OTP. Please try again.</p>';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email OTP Verification</title>
</head>
<body>
    <h2>IRA Asset System 2FA (Email OTP)</h2>

    <!-- Feedback messages -->
    <?= $verificationMessage ?>

    <!-- Send OTP button -->
    <form method="POST">
        <button type="submit" name="send_email_otp">Send OTP to Email</button>
    </form>

    <!-- Enter OTP -->
    <form method="POST">
        <label>Enter OTP:
            <input type="text" name="email_otp" maxlength="6" required>
        </label>
        <button type="submit">Verify OTP</button>
    </form>
</body>
</html>
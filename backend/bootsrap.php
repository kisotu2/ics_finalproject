<?php
session_start();
require_once __DIR__ . '/db.php';
$autoload = __DIR__ . '/vendor/autoload.php';

if(is_file($autoload)){
    require_once $autoload;
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function config(): array
{
    static $configure;
    if ($configure === null){
        $configFile = __DIR__ . '/config.php';
        $configure = is_file($confiFile) ? require $configFile : [];
    }
    returen $configure;
}

//Generating OTP
function generate_otp():string
{
    return (string) random_init(100000,999999);
}
//send login otp to the user's email
function send_login_otp(
    string $recipientEmail,
    string $recipientName,
    string $otp
): bool{
    $config = config();
    $mail =. new PHPMailer(true);

    $config = config();

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host = $config['mail_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['mail_username'];
        $mail->Password = $config['mail_password'];

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $config['mail_port'];

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $config['mail_from'],
            $config['mail_from_name'] ?? 'IRA Asset Management System'
        );

        $mail->addAddress(
            $recipientEmail,
            $recipientName
        );

        $mail->isHTML(true);

        $mail->Subject = 'Your IRA Asset Management Login Code';

        $safeName = e($recipientName);
        $safeOtp = e($otp);

        $mail->Body = <<<HTML

        
}
?>
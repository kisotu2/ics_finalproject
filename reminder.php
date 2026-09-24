$stmt = $conn->query("SELECT u.email, u.full_name, a.token 
    FROM asset_approvals a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.status='pending'");

while($row = $stmt->fetch_assoc()){
    $approve_link = "http://yourdomain.com/approve.php?token={$row['token']}&action=approve";
    $decline_link = "http://yourdomain.com/approve.php?token={$row['token']}&action=decline";

    $mail = new PHPMailer(true);
    try{
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('ASSET_MAIL_USERNAME') ?: '';
        $mail->Password   = getenv('ASSET_MAIL_PASSWORD') ?: '';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom(getenv('ASSET_MAIL_FROM') ?: 'noreply@example.invalid', 'IRA Asset Management System');
        $mail->addAddress($row['email'], $row['full_name']);

        $mail->isHTML(true);
        $mail->Subject = "Reminder: Approve Your IT Asset Assignment";
        $mail->Body = "
            <p>Hello {$row['full_name']},</p>
            <p>You have pending IT asset assignments. Please approve or decline:</p>
            <a href='$approve_link' style='padding:10px 15px;background:#28a745;color:white;text-decoration:none;border-radius:5px;'>Approve</a>
            <a href='$decline_link' style='padding:10px 15px;background:#dc3545;color:white;text-decoration:none;border-radius:5px;margin-left:10px;'>Decline</a>
        ";
        $mail->send();
    } catch(Exception $e){
        continue; // skip if email fails
    }
}

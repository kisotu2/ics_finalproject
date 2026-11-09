<?php
declare(strict_types=1);

function send_software_expiry_email(array $recipient, array $software): void
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $mailerFiles = [
            dirname(__DIR__) . '/PHPMailer-master/src/Exception.php',
            dirname(__DIR__) . '/PHPMailer-master/src/SMTP.php',
            dirname(__DIR__) . '/PHPMailer-master/src/PHPMailer.php',
        ];
        foreach ($mailerFiles as $mailerFile) {
            if (!is_file($mailerFile)) {
                throw new RuntimeException('PHPMailer is not installed; run Composer install or restore the bundled PHPMailer sources.');
            }
            require_once $mailerFile;
        }
    }

    $settings = config();
    $requiredSettings = ['mail_host', 'mail_username', 'mail_password', 'mail_from', 'mail_port'];
    foreach ($requiredSettings as $setting) {
        if (!isset($settings[$setting]) || trim((string) $settings[$setting]) === '') {
            throw new RuntimeException('Email setting "' . $setting . '" is not configured.');
        }
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $settings['mail_host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $settings['mail_username'];
    $mail->Password = (string) $settings['mail_password'];
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $settings['mail_port'];
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) $settings['mail_from'], (string) ($settings['mail_from_name'] ?? 'Asset Management System'));
    $mail->addAddress((string) $recipient['email'], (string) $recipient['full_name']);
    $mail->isHTML(true);

    $name = e((string) $software['software_name']);
    $vendor = e(trim((string) $software['vendor'] . ' ' . (string) ($software['version'] ?? '')));
    $expiry = e((string) $software['expiry_date']);
    $daysRemaining = (int) $software['days_remaining'];
    $expiryText = $daysRemaining < 0
        ? 'expired ' . abs($daysRemaining) . ' day(s) ago'
        : ($daysRemaining === 0 ? 'expires today' : 'expires in ' . $daysRemaining . ' day(s)');
    $mail->Subject = 'Software licence expiry reminder: ' . (string) $software['software_name'];
    $mail->Body = '<p>Hello ' . e((string) $recipient['full_name']) . ',</p>'
        . '<p>The licence for <strong>' . $name . '</strong> (' . $vendor . ') '
        . e($expiryText) . ' on <strong>' . $expiry . '</strong>.</p>'
        . '<p>Please update the licence expiry date or deactivate the licence record after renewal.</p>';
    $mail->AltBody = 'Hello ' . (string) $recipient['full_name'] . ",\n\n"
        . (string) $software['software_name'] . ' (' . trim((string) $software['vendor'] . ' ' . (string) ($software['version'] ?? ''))
        . ') ' . $expiryText . ' on ' . (string) $software['expiry_date'] . ".\n"
        . "Please update the licence expiry date or deactivate the licence record after renewal.";

    $mail->send();
}

function run_software_expiry_reminders(mysqli $conn): int
{
    $lock = $conn->query("SELECT GET_LOCK('software_expiry_reminders', 0) AS acquired")->fetch_assoc();
    if ((int) ($lock['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Another software expiry reminder run is already in progress.');
    }

    $sentCount = 0;
    $failedCount = 0;
    try {
        $recipients = [];
        $adminResult = $conn->query(
            "SELECT id, full_name, email
             FROM users
             WHERE role IN ('admin', 'super_admin')
               AND status = 'active'
               AND email <> ''
             ORDER BY id"
        );
        while ($admin = $adminResult->fetch_assoc()) {
            if (filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[(int) $admin['id']] = $admin;
            } else {
                error_log('Software expiry reminder skipped an administrator with an invalid email address.');
            }
        }

        $configuredAddress = trim((string) (config()['admin_alert_email'] ?? ''));
        if ($configuredAddress !== '' && filter_var($configuredAddress, FILTER_VALIDATE_EMAIL)) {
            $alreadyIncluded = false;
            foreach ($recipients as $recipient) {
                if (strcasecmp((string) $recipient['email'], $configuredAddress) === 0) {
                    $alreadyIncluded = true;
                    break;
                }
            }
            if (!$alreadyIncluded) {
                $recipients[0] = [
                    'id' => 0,
                    'full_name' => 'Administrator',
                    'email' => $configuredAddress,
                ];
            }
        }

        $softwareResult = $conn->query(
            "SELECT id, software_name, vendor, version, expiry_date,
                    DATEDIFF(expiry_date, CURDATE()) AS days_remaining
             FROM softwares
             WHERE status = 'Active'
               AND expiry_date IS NOT NULL
               AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY expiry_date, software_name"
        );
        $softwareRecords = $softwareResult->fetch_all(MYSQLI_ASSOC);
        if ($softwareRecords === []) {
            return 0;
        }
        if ($recipients === []) {
            throw new RuntimeException('No active administrator email addresses are configured.');
        }

        foreach ($softwareRecords as $software) {
            foreach ($recipients as $recipient) {
                $check = $conn->prepare(
                    'SELECT id FROM software_expiry_notifications
                     WHERE software_id = ? AND expiry_date = ? AND recipient_email = ? AND notified_on = CURDATE()'
                );
                $check->bind_param('iss', $software['id'], $software['expiry_date'], $recipient['email']);
                $check->execute();
                $alreadySent = $check->get_result()->fetch_assoc();
                $check->close();
                if ($alreadySent) {
                    continue;
                }

                try {
                    send_software_expiry_email($recipient, $software);
                    $record = $conn->prepare(
                        'INSERT INTO software_expiry_notifications
                            (software_id, expiry_date, recipient_email, notified_on)
                         VALUES (?, ?, ?, CURDATE())'
                    );
                    $record->bind_param('iss', $software['id'], $software['expiry_date'], $recipient['email']);
                    $record->execute();
                    $record->close();
                    $sentCount++;
                } catch (Throwable $exception) {
                    $failedCount++;
                    error_log(
                        'Software expiry reminder failed for software ID '
                        . (int) $software['id'] . ' and recipient ' . (string) $recipient['email']
                        . ': ' . $exception->getMessage()
                    );
                }
            }
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('software_expiry_reminders')");
    }

    if ($failedCount > 0) {
        throw new RuntimeException($sentCount . ' reminder(s) sent; ' . $failedCount . ' reminder(s) failed. See the PHP error log.');
    }

    return $sentCount;
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/backend/software_expiry_notifications.php';

try {
    $sent = run_software_expiry_reminders($conn);
    fwrite(STDOUT, 'Software expiry reminders sent: ' . $sent . PHP_EOL);
} catch (Throwable $exception) {
    error_log('Software expiry reminder run failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Software expiry reminder run failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

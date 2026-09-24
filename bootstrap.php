<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';

$autoload = __DIR__ . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;


/**
 * Escape HTML output
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


/**
 * Load application configuration
 */
function config(): array
{
    static $c;

    if ($c === null) {
        $configFile = __DIR__ . '/config.php';
        $c = is_file($configFile) ? require $configFile : [];
    }

    return $c;
}
/**
 * Generate a secure six-digit OTP.
 */
function generate_otp(): string
{
    return (string) random_int(100000, 999999);
}


/**
 * Send login OTP to the user's email.
 */
function send_login_otp(
    string $recipientEmail,
    string $recipientName,
    string $otp
): bool {

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Verification Code</title>
</head>

<body style="
    margin:0;
    padding:0;
    background:#f4f4f4;
    font-family:Arial,Helvetica,sans-serif;
">

<div style="
    max-width:600px;
    margin:40px auto;
    background:#ffffff;
    padding:35px;
    border-radius:10px;
">

    <h2 style="margin-top:0;color:#333;">
        Asset Management
    </h2>

    <p>
        Hello {$safeName},
    </p>

    <p>
        Someone is attempting to sign in to your
        Asset Management account.
    </p>

    <p>
        Your login verification code is:
    </p>

    <div style="
        font-size:32px;
        font-weight:bold;
        letter-spacing:8px;
        text-align:center;
        padding:20px;
        background:#f5f5f5;
        border-radius:8px;
        margin:25px 0;
    ">
        {$safeOtp}
    </div>

    <p>
        This code will expire in
        <strong>10 minutes</strong>.
    </p>

    <p>
        If you did not attempt to sign in, you can safely
        ignore this email.
    </p>

    <hr>

    <p style="font-size:12px;color:#777;">
        This is an automated message from the
        Asset Management System.
    </p>

</div>

</body>
</html>
HTML;

        $mail->AltBody =
            "Hello {$recipientName},\n\n" .
            "Your IRA Asset Management login verification code is: {$otp}\n\n" .
            "This code will expire in 10 minutes.\n\n" .
            "If you did not attempt to sign in, ignore this email.";

        return $mail->send();

    } catch (Exception $e) {

        error_log(
            'OTP email error: ' . $mail->ErrorInfo
        );

        return false;
    }
}


/**
 * Generate and store a new login OTP.
 *
 * A new OTP automatically invalidates the previous OTP.
 */
function create_login_otp(
    mysqli $conn,
    int $userId
): string {

    $config = config();

    // Generate a secure 6-digit OTP.
    $otp = generate_otp();

    // Never store the actual OTP.
    $otpHash = password_hash(
        $otp,
        PASSWORD_DEFAULT
    );

    $expiryMinutes = (int) (
        $config['otp_expiry_minutes'] ?? 10
    );

    $expiresAt = date(
        'Y-m-d H:i:s',
        time() + ($expiryMinutes * 60)
    );

    /*
     * Store the new OTP and reset attempts.
     *
     * This automatically invalidates any previous OTP.
     */
    $stmt = $conn->prepare(
        "UPDATE users
         SET otp_code = ?,
             otp_expiry = ?,
             otp_attempts = 0
         WHERE id = ?"
    );

    $stmt->bind_param(
        'ssi',
        $otpHash,
        $expiresAt,
        $userId
    );

    $stmt->execute();

    $stmt->close();

    return $otp;
}

/**
 * Verify a user's login OTP.
 *
 * Maximum attempts: 5.
 *
 * OTPs are single-use.
 */
function verify_login_otp(
    mysqli $conn,
    int $userId,
    string $otp
): bool {

    $config = config();

    $maxAttempts = (int) (
        $config['otp_max_attempts'] ?? 5
    );

    $stmt = $conn->prepare(
        "SELECT otp_code,
                otp_expiry,
                otp_attempts
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();

    $user = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    /*
     * No OTP exists.
     */
    if (!$user || empty($user['otp_code'])) {
        return false;
    }

    /*
     * Check whether the OTP has expired.
     */
    if (
        empty($user['otp_expiry']) ||
        strtotime($user['otp_expiry']) < time()
    ) {

        // Remove expired OTP.
        $stmt = $conn->prepare(
            "UPDATE users
             SET otp_code = NULL,
                 otp_expiry = NULL,
                 otp_attempts = 0
             WHERE id = ?"
        );

        $stmt->bind_param(
            'i',
            $userId
        );

        $stmt->execute();
        $stmt->close();

        return false;
    }

    /*
     * Check maximum attempts.
     */
    if (
        (int)$user['otp_attempts'] >=
        $maxAttempts
    ) {

        // Invalidate the OTP.
        $stmt = $conn->prepare(
            "UPDATE users
             SET otp_code = NULL,
                 otp_expiry = NULL,
                 otp_attempts = 0
             WHERE id = ?"
        );

        $stmt->bind_param(
            'i',
            $userId
        );

        $stmt->execute();
        $stmt->close();

        return false;
    }

    /*
     * Check the supplied OTP.
     */
    if (
        !password_verify(
            $otp,
            $user['otp_code']
        )
    ) {

        /*
         * Increase failed attempts.
         */
        $stmt = $conn->prepare(
            "UPDATE users
             SET otp_attempts = otp_attempts + 1
             WHERE id = ?"
        );

        $stmt->bind_param(
            'i',
            $userId
        );

        $stmt->execute();
        $stmt->close();

        return false;
    }

    /*
     * OTP is correct.
     *
     * Immediately delete it so it cannot
     * ever be used again.
     */
    $stmt = $conn->prepare(
        "UPDATE users
         SET otp_code = NULL,
             otp_expiry = NULL,
             otp_attempts = 0
         WHERE id = ?"
    );

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Generate CSRF token
 */
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}


/**
 * Verify CSRF token
 */
function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $sessionToken = $_SESSION['csrf'] ?? '';
        $postedToken = $_POST['csrf'] ?? '';

        if (
            empty($sessionToken) ||
            empty($postedToken) ||
            !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(419);
            exit('Invalid form token. Refresh the page and try again.');
        }
    }
}


/**
 * Require user login
 */
function require_login(array $roles = []): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    if (
        $roles &&
        !in_array($_SESSION['role'] ?? '', $roles, true)
    ) {
        http_response_code(403);
        exit('Access denied.');
    }
}


/**
 * Flash messages
 */
function flash(
    string $message = '',
    string $type = 'success'
): ?array {

    if ($message !== '') {
        $_SESSION['flash'] = [$type, $message];
        return null;
    }

    $result = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return $result;
}


/**
 * Audit logging
 */
function audit(
    mysqli $conn,
    string $action,
    string $entity,
    ?int $entityId = null,
    array $metadata = []
): void {

    $userId = $_SESSION['user_id'] ?? null;

    $json = json_encode(
        $metadata,
        JSON_THROW_ON_ERROR
    );

    $stmt = $conn->prepare(
        'INSERT INTO audit_logs
        (user_id, action, entity_type, entity_id, metadata)
        VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'issis',
        $userId,
        $action,
        $entity,
        $entityId,
        $json
    );

    $stmt->execute();
}

function current_user(): array
{
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => (string) ($_SESSION['name'] ?? $_SESSION['user_name'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
    ];
}

function nav_item(string $label, string $url, string $icon, string $currentPage): string
{
    $active = basename($url) === $currentPage ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . e($url) . '"><span class="nav-icon">'
        . $icon . '</span><span>' . e($label) . '</span></a>';
}


/**
 * Start page layout
 */
function layout_start(string $title): void
{
    $flash = flash();
    $user = current_user();
    $role = $user['role'];
    $currentPage = basename($_SERVER['PHP_SELF']);
    ?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="theme-color" content="#b08116">
        <title><?= e($title) ?> · Asset Management</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/app.css">
    </head>
    <body>
<div class="app-shell">
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark">SLK</div>
        <div class="brand-copy">
            <strong>SLK</strong><span>Asset Management</span>
        </div>
    </div>
<div class="sidebar-user">
    <div class="avatar">
        <?= e(strtoupper(substr($user['name'] ?: 'U', 0, 1))) ?>
    </div>
    <div class="sidebar-user-info">
        <strong><?= e($user['name'] ?: 'User') ?></strong>
        <span><?= e(ucwords(str_replace('_', ' ', $role))) ?></span>
    </div>
</div>
<nav class="sidebar-nav">
    <div class="nav-section-title">WORKSPACE</div>
    <?= nav_item('Dashboard', 'dashboard.php', '⌂', $currentPage) ?>
<?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
<?= nav_item('Software licences', 'software_dashboard.php', '▣', $currentPage) ?>
<?= nav_item('Software reports', 'software_reports.php', '▤', $currentPage) ?>
<?= nav_item('Maintenance', 'maintenance.php', '⌁', $currentPage) ?>
<?= nav_item('Laptop assignment', 'laptop_assignment.php', '⇄', $currentPage) ?>
<?= nav_item('History', 'history.php', '◷', $currentPage) ?>
<?php endif; ?>
<?php if ($role === 'super_admin'): ?>
    <?= nav_item('Users', 'users.php', '♙', $currentPage) ?>
    <?php endif; ?>
</nav>
<div class="sidebar-footer">
    <span>SLK AMS</span><a href="logout.php">Sign out</a></div></aside>
<div class="main-area">
    <header class="topbar">
        <button class="mobile-menu" type="button" aria-label="Open navigation" onclick="document.getElementById('sidebar').classList.toggle('sidebar-open')">☰

        </button><div class="topbar-title"><?= e($title) ?></div><div class="topbar-actions"><div class="system-status"><span class="status-dot"></span> System online</div><div class="topbar-profile"><div class="topbar-avatar"><?= e(strtoupper(substr($user['name'] ?: 'U', 0, 1))) ?></div><div><strong><?= e($user['name'] ?: 'User') ?></strong><small><?= e(ucwords(str_replace('_', ' ', $role))) ?></small></div></div></div></header>
<main class="content">
    <?php if ($flash): ?>
        <div class="notice <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
        <?php endif; ?>

    <?php
}


/**
 * End page layout
 */
function layout_end(): void
{
    ?>
 </main></div></div></body></html><?php
}


<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/google_config.php';

function googleLoginError(string $message): never
{
    $_SESSION['google_login_error'] = $message;

    header('Location: login.php');
    exit;
}

$client = new Google\Client();

$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri($config['redirect_uri']);
$client->setScopes(['openid', 'email', 'profile']);
$client->setPrompt('select_account');

// Start the Google sign-in process.
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(32));

    $_SESSION['google_oauth_state'] = $state;
    $client->setState($state);

    header('Location: ' . $client->createAuthUrl());
    exit;
}

// Verify OAuth state to protect against login CSRF.
$expectedState = $_SESSION['google_oauth_state'] ?? '';
$receivedState = $_GET['state'] ?? '';

unset($_SESSION['google_oauth_state']);

if (
    !is_string($expectedState) ||
    $expectedState === '' ||
    !is_string($receivedState) ||
    !hash_equals($expectedState, $receivedState)
) {
    googleLoginError('Google sign-in could not be verified. Please try again.');
}

try {
    $token = $client->fetchAccessTokenWithAuthCode(
        (string) $_GET['code']
    );

    if (!empty($token['error']) || empty($token['id_token'])) {
        googleLoginError('Google sign-in failed. Please try again.');
    }

    $claims = $client->verifyIdToken($token['id_token']);

    if (
        !$claims ||
        empty($claims['sub']) ||
        empty($claims['email']) ||
        !filter_var($claims['email'], FILTER_VALIDATE_EMAIL) ||
        !in_array($claims['email_verified'] ?? false, [true, 1, 'true', '1'], true) ||
        ($claims['aud'] ?? '') !== $config['client_id']
    ) {
        googleLoginError('Google could not verify your account. Please try again.');
    }

    $email = $claims['email'];

    // Only allow an existing account with an active status.
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, role, status
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->bind_param('s', $email);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || $user['status'] !== 'active') {
        googleLoginError(
            'No active account was found for this Google email.'
        );
    }

    // Keep the existing email OTP verification requirement.
    $otp = create_login_otp($conn, (int) $user['id']);

    if (!send_login_otp($user['email'], $user['full_name'], $otp)) {
        googleLoginError(
            'We could not send your verification code. Please try again later.'
        );
    }

    // Do not authenticate the user until OTP verification succeeds.
    session_regenerate_id(true);

    $_SESSION['pending_login_user_id'] = (int) $user['id'];
    $_SESSION['pending_login_email'] = $user['email'];
    $_SESSION['pending_login_name'] = $user['full_name'];
    $_SESSION['pending_login_role'] = $user['role'];
    $_SESSION['otp_sent_at'] = time();

    header('Location: verify_otp.php');
    exit;

} catch (Throwable $e) {
    error_log('Google login error: ' . $e->getMessage());

    googleLoginError(
        'Google sign-in could not be completed. Please try again.'
    );
}

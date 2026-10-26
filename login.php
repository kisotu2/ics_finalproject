<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
require __DIR__ . '/bootstrap.php';

// If the user is already logged in, take them straight to the dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token.
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $error = 'Please enter your email and password.';

    } else {

        /*
         * Find the user by email.
         */
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, password, role, status
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            's',
            $email
        );

        $stmt->execute();

        $user = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        /*
         * Login succeeds only when:
         *
         * 1. The account exists.
         * 2. The account is active.
         * 3. The password is correct.
         */
        $loginSuccessful =
            $user &&
            $user['status'] === 'active' &&
            password_verify(
                $password,
                $user['password']
            );

        if (!$loginSuccessful) {

            /*
             * Keep the error generic so we don't reveal
             * whether an email address exists.
             */
            $error =
                'Invalid email, password, or account status.';

        } else {

            /*
             * Generate a new OTP.
             *
             * This also invalidates/replaces any previous OTP.
             */
            $otp = create_login_otp(
                $conn,
                (int) $user['id']
            );

            /*
             * Send OTP to the user's registered email.
             */
            $emailSent = send_login_otp(
                $user['email'],
                $user['full_name'],
                $otp
            );

            if (!$emailSent) {

                $error =
                    'We could not send the verification code. ' .
                    'Please try again later.';

            } else {

                /*
                 * Do NOT authenticate the user yet.
                 *
                 * Store temporary login information.
                 */
                $_SESSION['pending_login_user_id'] =
                    (int) $user['id'];

                $_SESSION['pending_login_email'] =
                    $user['email'];

                $_SESSION['pending_login_name'] =
                    $user['full_name'];

                $_SESSION['pending_login_role'] =
                    $user['role'];

                /*
                 * Used by the resend cooldown.
                 */
                $_SESSION['otp_sent_at'] = time();

                /*
                 * Redirect to OTP verification.
                 */
                header(
                    'Location: verify_otp.php'
                );

                exit;
            }
        }
    }
}

layout_start('Sign in');
?>

<section
    class="panel"
    style="max-width:460px;margin:70px auto;"
>

    <h1>Welcome back</h1>

    <p class="muted">
        Sign in to access your Asset Management account.
    </p>

    <?php if ($error): ?>

        <p class="notice error">
            <?= e($error) ?>
        </p>

    <?php endif; ?>

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?= e(csrf()) ?>"
        >

        <label>
            Email

            <input
                type="email"
                name="email"
                autocomplete="email"
                placeholder="Enter your email address"
                required
            >
        </label>

        <label>
            Password

            <input
                type="password"
                name="password"
                autocomplete="current-password"
                placeholder="Enter your password"
                required
            >
        </label>

        <button type="submit">
            Continue
        </button>

    </form>

<div class="login-divider">
    <span>OR</span>
</div>

<a href="google-login.php" class="google-login-btn">
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="20"
        height="20"
        viewBox="0 0 48 48"
        aria-hidden="true"
    >
        <path fill="#EA4335"
            d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0
            14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4"
            d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94
            c-.58 2.96-2.26 5.48-4.76 7.18l7.73 6C44.42 37.95 46.98 31.7 46.98 24.55z"/>
        <path fill="#FBBC05"
            d="M10.53 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.76-4.59l-7.98-6.19
            A23.9 23.9 0 0 0 0 24c0 3.87.93 7.52 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853"
            d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.14 1.45-4.89 2.3-8.18 2.3
            -6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
    </svg>

    <span>Continue with Google</span>
</a>

</section>

<?php
layout_end();
?>
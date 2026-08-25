<?php

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
</section>

<?php
layout_end();
?>
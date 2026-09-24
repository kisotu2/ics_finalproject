<?php

require __DIR__ . '/bootstrap.php';

/*
 * User must have passed the email/password stage.
 */
if (empty($_SESSION['pending_login_user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';

$success = '';

$userId = (int) $_SESSION['pending_login_user_id'];

$email = $_SESSION['pending_login_email'] ?? '';

$name = $_SESSION['pending_login_name'] ?? '';

$role = $_SESSION['pending_login_role'] ?? '';


/*
 * ============================================================
 * OTP SETTINGS
 * ============================================================
 */

$config = config();

$otpExpiryMinutes = (int) (
    $config['otp_expiry_minutes'] ?? 10
);

$otpLifetimeSeconds =
    $otpExpiryMinutes * 60;

$resendCooldown = 60;


/*
 * ============================================================
 * RESEND OTP
 * ============================================================
 */

$otpSentAt = (int) (
    $_SESSION['otp_sent_at'] ?? 0
);

$secondsSinceOtp =
    time() - $otpSentAt;

$canResend =
    $otpSentAt === 0 ||
    $secondsSinceOtp >= $resendCooldown;


/*
 * Handle resend request.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['resend_otp'])
) {

    verify_csrf();

    /*
     * Check resend cooldown.
     */
    if (!$canResend) {

        $remaining =
            $resendCooldown - $secondsSinceOtp;

        $error =
            "Please wait {$remaining} seconds before " .
            "requesting another code.";

    } else {

        /*
         * Get current user.
         */
        $stmt = $conn->prepare(
            "SELECT id,
                    full_name,
                    email,
                    role,
                    status
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
         * Check account.
         */
        if (
            !$user ||
            $user['status'] !== 'active'
        ) {

            unset(
                $_SESSION['pending_login_user_id'],
                $_SESSION['pending_login_email'],
                $_SESSION['pending_login_name'],
                $_SESSION['pending_login_role'],
                $_SESSION['otp_sent_at']
            );

            header('Location: login.php');

            exit;
        }


        /*
         * Generate a completely new OTP.
         *
         * This invalidates the old OTP.
         */
        $otp = create_login_otp(
            $conn,
            $userId
        );


        /*
         * Send new OTP.
         */
        $emailSent = send_login_otp(
            $user['email'],
            $user['full_name'],
            $otp
        );


        if (!$emailSent) {

            $error =
                'We could not send a new verification code. ' .
                'Please try again later.';

        } else {

            /*
             * Reset OTP timer.
             */
            $_SESSION['otp_sent_at'] =
                time();

            /*
             * Update session information.
             */
            $_SESSION['pending_login_email'] =
                $user['email'];

            $_SESSION['pending_login_name'] =
                $user['full_name'];

            $_SESSION['pending_login_role'] =
                $user['role'];

            /*
             * Prevent form resubmission.
             */
            header(
                'Location: verify_otp.php?resent=1'
            );

            exit;
        }
    }
}


/*
 * ============================================================
 * RESEND SUCCESS MESSAGE
 * ============================================================
 */

if (
    isset($_GET['resent']) &&
    $_GET['resent'] === '1'
) {

    $success =
        'A new verification code has been sent to your email.';
}


/*
 * ============================================================
 * VERIFY OTP
 * ============================================================
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['verify_otp'])
) {

    verify_csrf();

    $otp = trim(
        $_POST['otp'] ?? ''
    );


    /*
     * OTP must contain exactly 6 digits.
     */
    if (!preg_match('/^\d{6}$/', $otp)) {

        $error =
            'Please enter the 6-digit verification code.';

    } else {

        /*
         * Verify OTP.
         */
        if (
            verify_login_otp(
                $conn,
                $userId,
                $otp
            )
        ) {

            /*
             * OTP verified.
             *
             * Regenerate session ID.
             */
            session_regenerate_id(true);


            /*
             * Authenticate the user.
             */
            $_SESSION['user_id'] =
                $userId;

            $_SESSION['role'] =
                $role;

            $_SESSION['name'] =
                $name;


            /*
             * Remove temporary login information.
             */
            unset(
                $_SESSION['pending_login_user_id'],
                $_SESSION['pending_login_email'],
                $_SESSION['pending_login_name'],
                $_SESSION['pending_login_role'],
                $_SESSION['otp_sent_at']
            );


            /*
             * Audit successful login.
             */
            audit(
                $conn,
                'login',
                'user',
                $userId,
                [
                    'method' =>
                        'password_and_email_otp'
                ]
            );


            /*
             * Redirect to dashboard.
             */
            header(
                'Location: dashboard.php'
            );

            exit;

        } else {

            /*
             * Determine whether OTP still exists.
             *
             * This lets us show a better message when
             * the 5-attempt limit has been reached.
             */
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

            $otpState = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();


            if (
                !$otpState ||
                empty($otpState['otp_code'])
            ) {

                $error =
                    'The verification code is no longer valid. ' .
                    'Please request a new code.';

            } elseif (
                (int)$otpState['otp_attempts'] >= 5
            ) {

                $error =
                    'Too many incorrect attempts. ' .
                    'Please request a new verification code.';

            } elseif (
                strtotime($otpState['otp_expiry']) < time()
            ) {

                $error =
                    'The verification code has expired. ' .
                    'Please request a new code.';

            } else {

                $remainingAttempts =
                    5 - (int)$otpState['otp_attempts'];

                $error =
                    'Invalid verification code. ' .
                    $remainingAttempts .
                    ' attempt' .
                    (
                        $remainingAttempts === 1
                            ? ''
                            : 's'
                    ) .
                    ' remaining.';
            }
        }
    }
}


/*
 * ============================================================
 * CALCULATE OTP EXPIRY
 * ============================================================
 */

$otpRemainingSeconds = 0;

$stmt = $conn->prepare(
    "SELECT otp_expiry
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$otpData = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (
    $otpData &&
    !empty($otpData['otp_expiry'])
) {

    $otpRemainingSeconds = max(
        0,
        strtotime($otpData['otp_expiry']) - time()
    );
}


/*
 * ============================================================
 * RESEND TIMER
 * ============================================================
 */

$otpSentAt = (int) (
    $_SESSION['otp_sent_at'] ?? 0
);

$secondsSinceOtp =
    time() - $otpSentAt;

$resendRemainingSeconds = max(
    0,
    $resendCooldown - $secondsSinceOtp
);


layout_start('Email Verification');
?>

<section
    class="panel"
    style="max-width:460px;margin:70px auto;"
>

    <h1>Verify your email</h1>

    <p class="muted">

        Hello
        <strong><?= e($name) ?></strong>.

    </p>

    <p class="muted">

        We sent a 6-digit verification code to:

        <strong>
            <?= e($email) ?>
        </strong>

    </p>


    <?php if ($error): ?>

        <p class="notice error">
            <?= e($error) ?>
        </p>

    <?php endif; ?>


    <?php if ($success): ?>

        <p class="notice success">
            <?= e($success) ?>
        </p>

    <?php endif; ?>


    <!-- =====================================================
         OTP FORM
         ===================================================== -->

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?= e(csrf()) ?>"
        >

        <label>
            Verification Code

            <input
                type="text"
                name="otp"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                minlength="6"
                autocomplete="one-time-code"
                placeholder="Enter 6-digit code"
                required
                autofocus
            >
        </label>


        <button
            type="submit"
            name="verify_otp"
            value="1"
        >
            Verify & Sign In
        </button>

    </form>


    <!-- =====================================================
         SINGLE TIMER / RESEND CONTROL
         ===================================================== -->

    <div
        style="
            margin-top:25px;
            text-align:center;
        "
    >

        <p
            class="muted"
            id="otpTimer"
        >
            Code expires in
            <strong>--:--</strong>
        </p>


        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >

            <button
                type="submit"
                name="resend_otp"
                value="1"
                id="resendButton"
                disabled
            >
                Resend code
            </button>

        </form>

    </div>


    <p
        style="
            margin-top:20px;
            text-align:center;
        "
    >

        <a href="login.php">
            Back to login
        </a>

    </p>

</section>


<script>
(function () {

    /*
     * OTP expiry countdown.
     */
    let otpRemaining =
        <?= (int) $otpRemainingSeconds ?>;


    /*
     * Resend cooldown.
     */
    let resendRemaining =
        <?= (int) $resendRemainingSeconds ?>;


    const timer =
        document.getElementById('otpTimer');

    const resendButton =
        document.getElementById('resendButton');


    if (!timer || !resendButton) {
        return;
    }


    function formatTime(seconds) {

        const minutes =
            Math.floor(seconds / 60);

        const secs =
            seconds % 60;

        return (
            String(minutes).padStart(2, '0') +
            ':' +
            String(secs).padStart(2, '0')
        );
    }


    function updateDisplay() {

        /*
         * OTP has expired.
         */
        if (otpRemaining <= 0) {

            timer.innerHTML =
                '<strong>Code expired</strong>';

        } else {

            timer.innerHTML =
                'Code expires in <strong>' +
                formatTime(otpRemaining) +
                '</strong>';
        }


        /*
         * Resend button availability.
         *
         * It becomes available after 60 seconds.
         */
        if (resendRemaining <= 0) {

            resendButton.disabled = false;

            resendButton.textContent =
                'Resend code';

        } else {

            resendButton.disabled = true;

            resendButton.textContent =
                'Resend code (' +
                resendRemaining +
                's)';
        }
    }


    /*
     * Initial display.
     */
    updateDisplay();


    /*
     * Update every second.
     */
    const interval =
        setInterval(function () {

            if (otpRemaining > 0) {
                otpRemaining--;
            }

            if (resendRemaining > 0) {
                resendRemaining--;
            }

            updateDisplay();


            /*
             * Stop the timer once the OTP has expired
             * and the resend button is available.
             */
            if (
                otpRemaining <= 0 &&
                resendRemaining <= 0
            ) {

                clearInterval(interval);
            }

        }, 1000);

})();
</script>

<?php
layout_end();
?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = '';
$success = false;
$created = false;

// Restrict this setup page to requests originating on this same computer.
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden. Open this setup page from localhost on the server computer.');
}

function superAdminExists(mysqli $conn): bool
{
    $result = $conn->query(
        "SELECT id FROM users WHERE role = 'super_admin' LIMIT 1"
    );
    return (bool) $result->fetch_assoc();
}

try {
    // Existing super admins permanently close this first-run setup page.
    if (superAdminExists($conn)) {
        $created = true;
    }
} catch (Throwable $e) {
    error_log('Super admin setup database check failed: ' . $e->getMessage());
    http_response_code(500);
    exit(
        'The database could not be checked. Confirm that the database is imported ' .
        'and that config.php points to the correct database.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$created) {
    verify_csrf();

    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        $error = 'Enter a name between 2 and 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address (maximum 190 characters).';
    } elseif (strlen($password) < 12) {
        $error = 'Use a password with at least 12 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        $lockAcquired = false;

        try {
            // Serialize first-run attempts to prevent two super admins being
            // created by simultaneous requests. MySQL named locks are connection-scoped.
            $lockResult = $conn->query(
                "SELECT GET_LOCK('ira_assets_first_super_admin_setup', 10) AS lock_ok"
            );
            $lockRow = $lockResult->fetch_assoc();
            $lockAcquired = isset($lockRow['lock_ok']) && (int)$lockRow['lock_ok'] === 1;

            if (!$lockAcquired) {
                $error = 'Setup is busy. Wait a few seconds and try again.';
            } elseif (superAdminExists($conn)) {
                $created = true;
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO users (full_name, email, password, role, status)
                     VALUES (?, ?, ?, 'super_admin', 'active')"
                );
                $stmt->bind_param('sss', $name, $email, $passwordHash);
                $stmt->execute();
                $stmt->close();

                $success = true;
                $created = true;
            }
        } catch (mysqli_sql_exception $e) {
            // Do not expose database internals in the browser.
            error_log('Super admin setup failed: ' . $e->getMessage());

            if ((int)$e->getCode() === 1062) {
                $error = 'That email address is already registered. Use another email.';
            } else {
                $error = 'The account could not be created. Check the database schema and error log.';
            }
        } finally {
            if ($lockAcquired) {
                try {
                    $conn->query(
                        "SELECT RELEASE_LOCK('ira_assets_first_super_admin_setup')"
                    );
                } catch (Throwable $ignored) {
                    // Connection close also releases the named lock.
                }
            }
        }
    }
}

layout_start('Temporary super admin setup');
?>

<style>
.setup-wrap {
    max-width: 560px;
    margin: 42px auto;
}
.setup-card {
    background: #fff;
    border: 1px solid #e7e9ef;
    border-radius: 18px;
    padding: clamp(22px, 4vw, 36px);
    box-shadow: 0 18px 50px rgba(20, 32, 60, .08);
}
.setup-kicker {
    display: inline-block;
    margin-bottom: 10px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #fff4d6;
    color: #805a00;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.setup-card h1 {
    margin: 0 0 10px;
    font-size: clamp(25px, 4vw, 32px);
}
.setup-intro {
    color: #657084;
    line-height: 1.6;
    margin-bottom: 24px;
}
.setup-card label {
    display: block;
    margin: 16px 0;
    font-weight: 600;
}
.setup-card input {
    display: block;
    box-sizing: border-box;
    width: 100%;
    margin-top: 7px;
    padding: 12px 13px;
    border: 1px solid #cfd5df;
    border-radius: 9px;
    font: inherit;
    background: #fff;
}
.setup-card input:focus {
    outline: 3px solid rgba(46, 105, 220, .16);
    border-color: #3268d8;
}
.setup-card button, .setup-link {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    box-sizing: border-box;
    margin-top: 12px;
    padding: 13px 16px;
    border: 0;
    border-radius: 9px;
    background: #2457c5;
    color: #fff;
    font: inherit;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}
.setup-card button:hover, .setup-link:hover { background: #1947a8; }
.setup-alert {
    padding: 12px 14px;
    border-radius: 9px;
    margin: 16px 0;
    line-height: 1.5;
}
.setup-alert.error { background: #fff0f0; color: #9b1c1c; border: 1px solid #ffd2d2; }
.setup-alert.success { background: #edfbf1; color: #176534; border: 1px solid #c5efd1; }
.setup-alert.info { background: #eef5ff; color: #204a85; border: 1px solid #cfe0ff; }
.setup-footnote { color: #6c7585; font-size: 13px; line-height: 1.6; margin-top: 18px; }
</style>

<section class="setup-wrap">
    <div class="setup-card">
        <span class="setup-kicker">First-run setup · Temporary</span>
        <h1>Create the super administrator</h1>
        <p class="setup-intro">
            Create the first account with full administrative access to the
            IRA Asset Management System. Use a unique email address and a
            strong password that you have not used elsewhere.
        </p>

        <?php if ($success): ?>
            <div class="setup-alert success">
                <strong>Super administrator created successfully.</strong><br>
                You can now sign in using the email and password you entered.
            </div>
            <a class="setup-link" href="login.php">Go to sign in</a>
            <p class="setup-footnote">
                Important: delete <code>setup_super_admin.php</code> from your
                project folder now. The page will also refuse further account
                creation because a super administrator exists.
            </p>

        <?php elseif ($created): ?>
            <div class="setup-alert info">
                A super administrator already exists. This setup page is closed.
            </div>
            <a class="setup-link" href="login.php">Go to sign in</a>
            <p class="setup-footnote">
                If you need another administrator, sign in as the existing
                super administrator and use the user-management page.
            </p>

        <?php else: ?>
            <?php if ($error !== ''): ?>
                <div class="setup-alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

                <label for="full_name">Full name</label>
                <input
                    id="full_name"
                    name="full_name"
                    type="text"
                    maxlength="150"
                    autocomplete="name"
                    required
                >

                <label for="email">Administrator email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    maxlength="190"
                    autocomplete="email"
                    required
                >

                <label for="password">Password (minimum 12 characters)</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="12"
                    autocomplete="new-password"
                    required
                >

                <label for="confirm_password">Confirm password</label>
                <input
                    id="confirm_password"
                    name="confirm_password"
                    type="password"
                    minlength="12"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">Create super administrator</button>
            </form>

            <p class="setup-footnote">
                This page only accepts requests from localhost and only creates
                the first super administrator. It does not display or email
                your password.
            </p>
        <?php endif; ?>
    </div>
</section>

<?php layout_end(); ?>

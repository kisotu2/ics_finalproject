<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['super_admin']);

$error = '';
$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 150) {
        $error = 'Enter a name between 1 and 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 12) {
        $error = 'Use a password with at least 12 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "INSERT INTO users (full_name, email, password, role, status)
                 VALUES (?, ?, ?, 'user', 'active')"
            );
            $stmt->bind_param('sss', $fullName, $email, $passwordHash);
            $stmt->execute();
            $userId = (int) $conn->insert_id;
            $stmt->close();

            audit($conn, 'user_registered', 'user', $userId, [
                'email' => $email,
                'role' => 'user',
            ]);

            $conn->commit();
            flash('User account created successfully.');
            header('Location: users.php');
            exit;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();

            if ((int) $exception->getCode() === 1062) {
                $error = 'An account with that email address already exists.';
            } else {
                error_log('User registration failed: ' . $exception->getMessage());
                $error = 'The account could not be created. Please try again.';
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('User registration audit failed: ' . $exception->getMessage());
            $error = 'The account could not be created. Please try again.';
        }
    }
}

layout_start('Register user');
?>

<section class="user-registration">
    <div class="page-heading">
        <div>
            <div class="eyebrow">USER MANAGEMENT</div>
            <h1>Create a user account</h1>
            <p class="page-description">Add an active employee account to the system. The new account will have standard user access.</p>
        </div>
        <a class="button secondary" href="users.php">Back to users</a>
    </div>

    <section class="panel registration-panel">
        <div class="panel-header">
            <div>
                <h2>Account details</h2>
                <span>Only super administrators can create user accounts.</span>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <p class="notice error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" class="registration-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

            <label>
                Full name
                <input
                    type="text"
                    name="full_name"
                    value="<?= e($fullName) ?>"
                    maxlength="150"
                    autocomplete="name"
                    placeholder="e.g. Alex Morgan"
                    required
                >
            </label>

            <label>
                Work email
                <input
                    type="email"
                    name="email"
                    value="<?= e($email) ?>"
                    maxlength="190"
                    autocomplete="email"
                    placeholder="name@company.com"
                    required
                >
            </label>

            <div class="form-grid">
                <label>
                    Temporary password
                    <input
                        type="password"
                        name="password"
                        minlength="12"
                        autocomplete="new-password"
                        aria-describedby="registration-password-help"
                        required
                    >
                    <small id="registration-password-help" class="muted">At least 12 characters. Share it through an approved secure channel.</small>
                </label>

                <label>
                    Confirm password
                    <input
                        type="password"
                        name="password_confirmation"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                </label>
            </div>

            <div class="registration-actions">
                <a class="button secondary" href="users.php">Cancel</a>
                <button type="submit">Create user account</button>
            </div>
        </form>
    </section>
</section>

<?php layout_end(); ?>

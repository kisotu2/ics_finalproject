<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/bootstrap.php';

$error = '';

$exists = (int) $conn
    ->query("SELECT COUNT(*) AS n FROM users WHERE role='super_admin'")
    ->fetch_assoc()['n'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$exists) {
    verify_csrf();

    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        mb_strlen($name) < 2 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($password) < 12
    ) {
        $error = 'Enter a name, a valid email, and a password of at least 12 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, email, password, role, status)
                VALUES (?, ?, ?, 'super_admin', 'active')"
            );

            $stmt->bind_param('sss', $name, $email, $hash);
            $stmt->execute();

            flash('Super administrator created. You can now sign in.');

            header('Location: login.php');
            exit;

        } catch (mysqli_sql_exception $exception) {
            $error = 'That email address is already registered.';
        }
    }
}

layout_start('Initial setup');
?>

<section class="panel" style="max-width:520px;margin:50px auto">

    <h1>Create super administrator</h1>

    <?php if ($exists): ?>

        <p class="notice">
            A super administrator already exists.
            <a href="login.php">Sign in</a>.
        </p>

    <?php else: ?>

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
                Full name
                <input
                    name="full_name"
                    required
                >
            </label>

            <label>
                Email
                <input
                    type="email"
                    name="email"
                    required
                >
            </label>

            <label>
                Strong password
                <input
                    type="password"
                    name="password"
                    minlength="12"
                    required
                >
            </label>

            <button type="submit">
                Create account
            </button>

        </form>

    <?php endif; ?>

</section>

<?php layout_end(); ?>
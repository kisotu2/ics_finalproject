<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();
require_once __DIR__ . '/backend/laptop_issues.php';

$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'super_admin'], true);
if (!laptop_portal_schema_ready($conn)) {
    http_response_code(503);
    layout_start('Issue reporting setup');
    ?>
    <section class="panel">
        <h1>Issue reporting setup required</h1>
        <p>An administrator must apply <code>migrations/20261027_virtual_laptop_portal.sql</code> before issue reporting is available.</p>
    </section>
    <?php
    layout_end();
    exit;
}
$laptopId = filter_var($_POST['laptop_id'] ?? $_GET['laptop_id'] ?? null, FILTER_VALIDATE_INT);
$error = '';
$laptop = null;

if ($laptopId) {
    if ($isAdmin) {
        $stmt = $conn->prepare(
            'SELECT id, asset_tag, ad_computer_name, brand, model, assigned_to, responsible_admin_id
             FROM laptops WHERE id = ?'
        );
        $stmt->bind_param('i', $laptopId);
    } else {
        $stmt = $conn->prepare(
            'SELECT id, asset_tag, ad_computer_name, brand, model, assigned_to, responsible_admin_id
             FROM laptops WHERE id = ? AND assigned_to = ?'
        );
        $stmt->bind_param('ii', $laptopId, $user['id']);
    }
    $stmt->execute();
    $laptop = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$laptop) {
    http_response_code(404);
}

$categories = [
    'Battery or charging',
    'Overheating',
    'Slow performance',
    'Hardware failure',
    'Storage problem',
    'Operating system error',
    'Application crash',
    'Network connectivity',
    'Security concern',
    'Other',
];
$severities = ['low', 'medium', 'high', 'critical'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $laptop) {
    verify_csrf();

    $category = trim((string) ($_POST['category'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $severity = (string) ($_POST['severity'] ?? '');
    $isUsable = ($_POST['is_usable'] ?? '') === 'yes' ? 1 : 0;
    $occurredInput = trim((string) ($_POST['occurred_at'] ?? ''));
    $occurredAt = null;

    if (!in_array($category, $categories, true)) {
        $error = 'Choose an issue category.';
    } elseif ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
        $error = 'Enter a title of 1 to 160 characters.';
    } elseif ($description === '' || mb_strlen($description, 'UTF-8') > 5000) {
        $error = 'Describe the issue in 1 to 5,000 characters.';
    } elseif (!in_array($severity, $severities, true)) {
        $error = 'Choose an issue severity.';
    } elseif ($occurredInput !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $occurredInput);
        $parseErrors = DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
            || $parsed->format('Y-m-d\TH:i') !== $occurredInput
        ) {
            $error = 'Enter a valid issue date and time.';
        } else {
            $occurredAt = $parsed->format('Y-m-d H:i:s');
        }
    }

    if ($error === '') {
        $assignedAdminId = $laptop['responsible_admin_id'] ? (int) $laptop['responsible_admin_id'] : null;
        if ($assignedAdminId !== null) {
            $adminCheck = $conn->prepare(
                "SELECT id FROM users WHERE id = ? AND role IN ('admin','super_admin') AND status = 'active'"
            );
            $adminCheck->bind_param('i', $assignedAdminId);
            $adminCheck->execute();
            if (!$adminCheck->get_result()->fetch_assoc()) {
                $assignedAdminId = null;
            }
            $adminCheck->close();
        }

        try {
            $conn->begin_transaction();

            $reference = 'ISS-' . strtoupper(bin2hex(random_bytes(8)));
            $source = 'manual';
            $pending = 'pending_admin_review';
            $stmt = $conn->prepare(
                'INSERT INTO laptop_issues
                    (reference_code, laptop_id, reported_by, assigned_admin_id, category, title,
                     description, severity, source, status, is_usable, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $reporterId = $user['id'];
            $stmt->bind_param(
                'siiissssssis',
                $reference,
                $laptopId,
                $reporterId,
                $assignedAdminId,
                $category,
                $title,
                $description,
                $severity,
                $source,
                $pending,
                $isUsable,
                $occurredAt
            );
            $stmt->execute();
            $issueId = (int) $conn->insert_id;
            $stmt->close();

            record_laptop_issue_update($conn, $issueId, $reporterId, null, $pending, 'Issue submitted by employee.');
            create_user_notification(
                $conn,
                $reporterId,
                $issueId,
                'Issue received',
                'Your report ' . $reference . ' is pending administrator review.'
            );

            if ($assignedAdminId !== null) {
                create_user_notification(
                    $conn,
                    $assignedAdminId,
                    $issueId,
                    'Laptop issue reported',
                    $reference . ' · ' . $title . ' (' . $severity . ')'
                );
            } else {
                $admins = $conn->query(
                    "SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'"
                );
                while ($admin = $admins->fetch_assoc()) {
                    create_user_notification(
                        $conn,
                        (int) $admin['id'],
                        $issueId,
                        'Laptop issue needs assignment',
                        $reference . ' has no active responsible administrator.'
                    );
                }
            }

            audit($conn, 'laptop_issue_reported', 'laptop_issue', $issueId, [
                'reference_code' => $reference,
                'laptop_id' => $laptopId,
                'severity' => $severity,
                'source' => 'manual',
            ]);

            $conn->commit();
            flash('Issue ' . $reference . ' was submitted for administrator review.');
            header('Location: my_laptop.php?id=' . $laptopId);
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Laptop issue submission failed: ' . $exception->getMessage());
            $error = 'The issue could not be submitted. Please try again.';
        }
    }
}

layout_start('Report an issue');
?>
<section class="issue-report-page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">DEVICE SUPPORT</div>
            <h1>Report a laptop issue</h1>
            <p class="page-description">Your report goes to an administrator for review. It does not automatically authorize maintenance.</p>
        </div>
        <?php if ($laptop): ?><a class="button secondary" href="my_laptop.php?id=<?= (int) $laptop['id'] ?>">Back to device</a><?php endif; ?>
    </div>

    <?php if (!$laptop): ?>
        <section class="panel"><h2>Device unavailable</h2><p>You can only report an issue for a laptop assigned to your account.</p></section>
    <?php else: ?>
        <section class="panel issue-report-panel">
            <div class="panel-header"><div><h2><?= e($laptop['asset_tag']) ?> · <?= e($laptop['ad_computer_name'] ?: $laptop['brand'] . ' ' . $laptop['model']) ?></h2><span>Provide enough detail for the administrator to review the problem.</span></div></div>
            <?php if ($error !== ''): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="laptop_id" value="<?= (int) $laptop['id'] ?>">
                <div class="form-grid">
                    <label>Issue category
                        <select name="category" required>
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $option): ?>
                                <option value="<?= e($option) ?>" <?= ($_POST['category'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Severity
                        <select name="severity" required>
                            <option value="">Select severity</option>
                            <?php foreach ($severities as $option): ?>
                                <option value="<?= e($option) ?>" <?= ($_POST['severity'] ?? '') === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label>Issue title
                    <input type="text" name="title" maxlength="160" value="<?= e((string) ($_POST['title'] ?? '')) ?>" placeholder="Briefly describe the issue" required>
                </label>
                <label>Description
                    <textarea name="description" maxlength="5000" placeholder="What happened? Include any error message and when it started." required><?= e((string) ($_POST['description'] ?? '')) ?></textarea>
                </label>
                <div class="form-grid">
                    <label>Is the laptop still usable?
                        <select name="is_usable" required>
                            <option value="yes" <?= ($_POST['is_usable'] ?? 'yes') === 'yes' ? 'selected' : '' ?>>Yes</option>
                            <option value="no" <?= ($_POST['is_usable'] ?? '') === 'no' ? 'selected' : '' ?>>No</option>
                        </select>
                    </label>
                    <label>When did it occur? <span class="muted">(optional)</span>
                        <input type="datetime-local" name="occurred_at" value="<?= e((string) ($_POST['occurred_at'] ?? '')) ?>">
                    </label>
                </div>
                <div class="registration-actions">
                    <a class="button secondary" href="my_laptop.php?id=<?= (int) $laptop['id'] ?>">Cancel</a>
                    <button type="submit">Submit for review</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</section>
<?php layout_end(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
require_once __DIR__ . '/backend/laptop_issues.php';

if (!laptop_portal_schema_ready($conn)) {
    http_response_code(503);
    layout_start('Issue review setup');
    ?>
    <section class="panel">
        <h1>Issue review setup required</h1>
        <p>Back up the database and apply <code>migrations/20261027_virtual_laptop_portal.sql</code> before using issue review.</p>
    </section>
    <?php
    layout_end();
    exit;
}

$adminId = (int) $_SESSION['user_id'];
$allowedStatuses = [
    'pending_admin_review',
    'approved_for_maintenance',
    'in_maintenance',
    'awaiting_information',
    'rejected',
    'duplicate',
    'resolved',
];
$filter = (string) ($_GET['status'] ?? '');
if (!in_array($filter, $allowedStatuses, true)) {
    $filter = '';
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $issueId = filter_var($_POST['issue_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $duplicateReference = trim((string) ($_POST['duplicate_reference'] ?? ''));

    if (!$issueId || !in_array($action, ['approve', 'reject', 'request_information', 'mark_duplicate', 'start_maintenance', 'resolve'], true)) {
        $error = 'Choose a valid issue and action.';
    } elseif (in_array($action, ['reject', 'request_information'], true) && $note === '') {
        $error = 'Enter a reason or a clear request for more information.';
    } elseif (in_array($action, ['reject', 'request_information'], true) && mb_strlen($note, 'UTF-8') > 2000) {
        $error = 'The note must be 2,000 characters or fewer.';
    } elseif (mb_strlen($note, 'UTF-8') > 2000) {
        $error = 'The note must be 2,000 characters or fewer.';
    } else {
        try {
            $conn->begin_transaction();

            $issueStmt = $conn->prepare(
                'SELECT i.*, l.asset_tag, l.assigned_to, l.status AS asset_status
                 FROM laptop_issues i
                 JOIN laptops l ON l.id = i.laptop_id
                 WHERE i.id = ? FOR UPDATE'
            );
            $issueStmt->bind_param('i', $issueId);
            $issueStmt->execute();
            $issue = $issueStmt->get_result()->fetch_assoc();
            $issueStmt->close();

            if (!$issue) {
                throw new DomainException('Issue not found.');
            }

            $oldStatus = $issue['status'];
            $newStatus = '';
            $notificationTitle = '';
            $notificationMessage = '';

            if ($action === 'approve') {
                if (!in_array($oldStatus, ['pending_admin_review', 'awaiting_information'], true)) {
                    throw new DomainException('Only issues awaiting review can be approved.');
                }

                $maintenanceCheck = $conn->prepare('SELECT id FROM maintenance_records WHERE source_issue_id = ? FOR UPDATE');
                $maintenanceCheck->bind_param('i', $issueId);
                $maintenanceCheck->execute();
                $maintenanceRecord = $maintenanceCheck->get_result()->fetch_assoc();
                $maintenanceCheck->close();

                if (!$maintenanceRecord) {
                    $description = $issue['title'] . "\n\n" . $issue['description'];
                    $reporterId = $issue['reported_by'] === null ? null : (int) $issue['reported_by'];
                    $insertMaintenance = $conn->prepare(
                        "INSERT INTO maintenance_records
                            (laptop_id, source_issue_id, reported_by, issue_description, status)
                         VALUES (?, ?, ?, ?, 'open')"
                    );
                    $laptopId = (int) $issue['laptop_id'];
                    $insertMaintenance->bind_param('iiis', $laptopId, $issueId, $reporterId, $description);
                    $insertMaintenance->execute();
                    $insertMaintenance->close();
                }

                $newStatus = 'approved_for_maintenance';
                $notificationTitle = 'Issue approved';
                $notificationMessage = $issue['reference_code'] . ' was approved and added to the maintenance queue.';
                $asset = $conn->prepare(
                    "UPDATE laptops SET status = 'Maintenance'
                     WHERE id = ? AND status NOT IN ('Retired','Disposed')"
                );
                $asset->bind_param('i', $issue['laptop_id']);
                $asset->execute();
                $asset->close();
            } elseif ($action === 'reject') {
                if (!in_array($oldStatus, ['pending_admin_review', 'awaiting_information'], true)) {
                    throw new DomainException('Only issues awaiting review can be rejected.');
                }
                $newStatus = 'rejected';
                $notificationTitle = 'Issue review update';
                $notificationMessage = $issue['reference_code'] . ' was not approved: ' . $note;
            } elseif ($action === 'request_information') {
                if (!in_array($oldStatus, ['pending_admin_review', 'awaiting_information'], true)) {
                    throw new DomainException('Information can only be requested while an issue is under review.');
                }
                $newStatus = 'awaiting_information';
                $notificationTitle = 'More information requested';
                $notificationMessage = $issue['reference_code'] . ': ' . $note;
            } elseif ($action === 'mark_duplicate') {
                if (!in_array($oldStatus, ['pending_admin_review', 'awaiting_information'], true) || $duplicateReference === '') {
                    throw new DomainException('Select a review-stage issue and provide the original issue reference.');
                }
                $duplicateCheck = $conn->prepare(
                    'SELECT id FROM laptop_issues WHERE reference_code = ? AND id <> ?'
                );
                $duplicateCheck->bind_param('si', $duplicateReference, $issueId);
                $duplicateCheck->execute();
                $duplicate = $duplicateCheck->get_result()->fetch_assoc();
                $duplicateCheck->close();
                if (!$duplicate) {
                    throw new DomainException('The original issue reference was not found.');
                }
                $duplicateId = (int) $duplicate['id'];
                $markDuplicate = $conn->prepare(
                    "UPDATE laptop_issues SET status = 'duplicate', duplicate_of = ?, assigned_admin_id = ?
                     WHERE id = ?"
                );
                $markDuplicate->bind_param('iii', $duplicateId, $adminId, $issueId);
                $markDuplicate->execute();
                $markDuplicate->close();
                $newStatus = 'duplicate';
                $note = 'Marked as duplicate of ' . $duplicateReference . ($note === '' ? '' : ': ' . $note);
                $notificationTitle = 'Issue review update';
                $notificationMessage = $issue['reference_code'] . ' was linked to an existing report as a duplicate.';
            } elseif ($action === 'start_maintenance') {
                if ($oldStatus !== 'approved_for_maintenance') {
                    throw new DomainException('Approve the issue before starting maintenance.');
                }
                $maintenance = $conn->prepare(
                    "UPDATE maintenance_records SET status = 'in_progress'
                     WHERE source_issue_id = ? AND status = 'open'"
                );
                $maintenance->bind_param('i', $issueId);
                $maintenance->execute();
                if ($maintenance->affected_rows !== 1) {
                    throw new DomainException('The linked maintenance job could not be started.');
                }
                $maintenance->close();
                $newStatus = 'in_maintenance';
                $notificationTitle = 'Maintenance started';
                $notificationMessage = 'Work has started on ' . $issue['reference_code'] . '.';
            } else {
                if (!in_array($oldStatus, ['approved_for_maintenance', 'in_maintenance'], true)) {
                    throw new DomainException('Only an approved issue can be resolved.');
                }
                $maintenance = $conn->prepare(
                    "UPDATE maintenance_records
                     SET status = 'resolved', repaired_at = NOW()
                     WHERE source_issue_id = ? AND status IN ('open','in_progress')"
                );
                $maintenance->bind_param('i', $issueId);
                $maintenance->execute();
                if ($maintenance->affected_rows !== 1) {
                    throw new DomainException('The linked maintenance job could not be completed.');
                }
                $maintenance->close();
                $newStatus = 'resolved';
                $notificationTitle = 'Maintenance completed';
                $notificationMessage = 'Work on ' . $issue['reference_code'] . ' has been marked complete.';
                $remaining = $conn->prepare(
                    "SELECT COUNT(*) AS total
                     FROM maintenance_records
                     WHERE laptop_id = ? AND status IN ('open','in_progress')"
                );
                $remaining->bind_param('i', $issue['laptop_id']);
                $remaining->execute();
                $hasOtherMaintenance = (int) $remaining->get_result()->fetch_assoc()['total'] > 0;
                $remaining->close();
                if (!$hasOtherMaintenance && !in_array($issue['asset_status'], ['Retired', 'Disposed'], true)) {
                    $asset = $conn->prepare(
                        "UPDATE laptops
                         SET status = CASE WHEN assigned_to IS NULL THEN 'Available' ELSE 'Assigned' END
                         WHERE id = ? AND status = 'Maintenance'"
                    );
                    $asset->bind_param('i', $issue['laptop_id']);
                    $asset->execute();
                    $asset->close();
                }
            }

            if ($action !== 'mark_duplicate') {
                $statusUpdate = $conn->prepare(
                    'UPDATE laptop_issues SET status = ?, assigned_admin_id = ? WHERE id = ?'
                );
                $statusUpdate->bind_param('sii', $newStatus, $adminId, $issueId);
                $statusUpdate->execute();
                $statusUpdate->close();
            }

            record_laptop_issue_update($conn, $issueId, $adminId, $oldStatus, $newStatus, $note ?: null);
            if ($issue['reported_by'] !== null) {
                create_user_notification(
                    $conn,
                    (int) $issue['reported_by'],
                    $issueId,
                    $notificationTitle,
                    $notificationMessage
                );
            }
            audit($conn, 'laptop_issue_' . $action, 'laptop_issue', $issueId, [
                'reference_code' => $issue['reference_code'],
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            $history = $conn->prepare(
                'INSERT INTO laptop_history (laptop_id, user_id, admin_id, action_type) VALUES (?, ?, ?, ?)'
            );
            $reporterId = $issue['reported_by'] === null ? null : (int) $issue['reported_by'];
            $historyAction = 'Issue ' . $issue['reference_code'] . ': ' . laptop_issue_status_label($newStatus);
            $history->bind_param('iiis', $issue['laptop_id'], $reporterId, $adminId, $historyAction);
            $history->execute();
            $history->close();

            $conn->commit();
            flash($issue['reference_code'] . ' updated: ' . laptop_issue_status_label($newStatus) . '.');
            header('Location: laptop_issues.php' . ($filter !== '' ? '?status=' . rawurlencode($filter) : ''));
            exit;
        } catch (DomainException $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Laptop issue review failed: ' . $exception->getMessage());
            $error = 'The issue could not be updated. Please check the server log and try again.';
        }
    }
}

$where = $filter === '' ? '' : 'WHERE i.status = ?';
$query = "SELECT i.*, l.asset_tag, l.ad_computer_name, l.brand, l.model,
                 reporter.full_name AS reporter_name, admin.full_name AS admin_name,
                 duplicate.reference_code AS duplicate_reference,
                 m.id AS maintenance_id, m.status AS maintenance_status
          FROM laptop_issues i
          JOIN laptops l ON l.id = i.laptop_id
          LEFT JOIN users reporter ON reporter.id = i.reported_by
          LEFT JOIN users admin ON admin.id = i.assigned_admin_id
          LEFT JOIN laptop_issues duplicate ON duplicate.id = i.duplicate_of
          LEFT JOIN maintenance_records m ON m.source_issue_id = i.id
          $where
          ORDER BY FIELD(i.severity, 'critical','high','medium','low'), i.created_at DESC
          LIMIT 200";
$stmt = $conn->prepare($query);
if ($filter !== '') {
    $stmt->bind_param('s', $filter);
}
$stmt->execute();
$issues = $stmt->get_result();

layout_start('Laptop issues');
?>
<section class="laptop-issues-page">
    <div class="page-heading">
        <div><div class="eyebrow">DEVICE SUPPORT</div><h1>Issue review</h1><p class="page-description">Review employee reports before maintenance is authorized.</p></div>
        <a class="button secondary" href="my_laptop.php">Virtual device overview</a>
    </div>
    <?php if ($error !== ''): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form class="issue-filter-bar" method="get">
        <label for="issue-status-filter">Filter by status</label>
        <select id="issue-status-filter" name="status">
            <option value="">All issues</option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filter === $status ? 'selected' : '' ?>><?= e(laptop_issue_status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="secondary" type="submit">Apply</button>
    </form>

    <?php if ($issues->num_rows === 0): ?>
        <section class="panel issue-empty"><h2>No issues found</h2><p>There are no reports matching this filter.</p></section>
    <?php endif; ?>

    <?php while ($issue = $issues->fetch_assoc()): ?>
        <article class="panel issue-review-card">
            <div class="issue-review-head">
                <div>
                    <span class="issue-reference"><?= e($issue['reference_code']) ?></span>
                    <h2><?= e($issue['title']) ?></h2>
                    <p><?= e($issue['asset_tag']) ?> · <?= e($issue['ad_computer_name'] ?: $issue['brand'] . ' ' . $issue['model']) ?> · Reported by <?= e($issue['reporter_name'] ?: 'Unknown') ?></p>
                </div>
                <div class="issue-review-badges">
                    <span class="severity-badge severity-<?= e($issue['severity']) ?>"><?= e(ucfirst($issue['severity'])) ?></span>
                    <span class="issue-state issue-state-<?= e($issue['status']) ?>"><?= e(laptop_issue_status_label($issue['status'])) ?></span>
                </div>
            </div>
            <div class="issue-review-meta">
                <span><?= e($issue['category']) ?></span>
                <span>Source: <?= e(ucfirst($issue['source'])) ?></span>
                <span><?= e($issue['created_at']) ?></span>
                <span><?= $issue['is_usable'] ? 'Device usable' : 'Device not usable' ?></span>
                <?php if ($issue['admin_name']): ?><span>Responsible: <?= e($issue['admin_name']) ?></span><?php endif; ?>
                <?php if ($issue['duplicate_reference']): ?><span>Duplicate of <?= e($issue['duplicate_reference']) ?></span><?php endif; ?>
                <?php if ($issue['maintenance_id']): ?><span>Maintenance #<?= (int) $issue['maintenance_id'] ?> · <?= e($issue['maintenance_status']) ?></span><?php endif; ?>
            </div>
            <p class="issue-review-description"><?= nl2br(e($issue['description'])) ?></p>
            <?php if ($issue['occurred_at']): ?><p class="muted">Occurred: <?= e($issue['occurred_at']) ?></p><?php endif; ?>

            <?php if (in_array($issue['status'], ['pending_admin_review', 'awaiting_information'], true)): ?>
                <form method="post" class="issue-action-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                    <label>Action
                        <select name="action" required>
                            <option value="">Choose action</option>
                            <option value="approve">Approve and create maintenance job</option>
                            <option value="request_information">Request more information</option>
                            <option value="reject">Reject report</option>
                            <option value="mark_duplicate">Mark as duplicate</option>
                        </select>
                    </label>
                    <label>Note or original issue reference
                        <input type="text" name="note" maxlength="2000" placeholder="Required for rejection or information request">
                    </label>
                    <label>Duplicate reference
                        <input type="text" name="duplicate_reference" maxlength="24" placeholder="ISS-... when marking duplicate">
                    </label>
                    <button type="submit">Save review decision</button>
                </form>
            <?php elseif ($issue['status'] === 'approved_for_maintenance'): ?>
                <form method="post" class="issue-inline-action">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                    <input type="hidden" name="action" value="start_maintenance">
                    <button type="submit">Start maintenance</button>
                </form>
                <form method="post" class="issue-inline-action">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                    <input type="hidden" name="action" value="resolve">
                    <button class="secondary" type="submit">Mark work complete</button>
                </form>
            <?php elseif ($issue['status'] === 'in_maintenance'): ?>
                <form method="post" class="issue-inline-action">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                    <input type="hidden" name="action" value="resolve">
                    <button type="submit">Complete maintenance and resolve</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endwhile; ?>
</section>
<?php
$stmt->close();
layout_end();
?>

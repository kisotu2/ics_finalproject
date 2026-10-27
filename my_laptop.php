<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();
require_once __DIR__ . '/backend/laptop_issues.php';

$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'super_admin'], true);
$portalReady = laptop_portal_schema_ready($conn);

if (!$portalReady) {
    layout_start('My Laptop');
    ?>
    <section class="panel laptop-empty">
        <h1>My Laptop setup required</h1>
        <p>The virtual laptop portal is not enabled yet. An administrator must back up the database and apply <code>migrations/20261027_virtual_laptop_portal.sql</code>.</p>
        <?php if ($isAdmin): ?><a class="button" href="directory_sync.php">Open directory sync</a><?php endif; ?>
    </section>
    <?php
    layout_end();
    exit;
}

$notificationStmt = $conn->prepare(
    'SELECT id, issue_id, title, message, created_at
     FROM user_notifications
     WHERE user_id = ? AND read_at IS NULL
     ORDER BY created_at DESC LIMIT 5'
);
$notificationStmt->bind_param('i', $user['id']);
$notificationStmt->execute();
$notifications = $notificationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notificationStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'respond_to_issue') {
    verify_csrf();
    $issueId = filter_var($_POST['issue_id'] ?? null, FILTER_VALIDATE_INT);
    $responseNote = trim((string) ($_POST['response_note'] ?? ''));
    if (!$issueId || $responseNote === '' || mb_strlen($responseNote, 'UTF-8') > 2000) {
        http_response_code(422);
        exit('Provide a response of 1 to 2,000 characters.');
    }

    try {
        $conn->begin_transaction();
        $issueStmt = $conn->prepare(
            "SELECT i.id, i.reference_code, i.assigned_admin_id, i.status, l.id AS laptop_id
             FROM laptop_issues i
             JOIN laptops l ON l.id = i.laptop_id
             WHERE i.id = ? AND i.reported_by = ? AND l.assigned_to = ?
             FOR UPDATE"
        );
        $issueStmt->bind_param('iii', $issueId, $user['id'], $user['id']);
        $issueStmt->execute();
        $issueForResponse = $issueStmt->get_result()->fetch_assoc();
        $issueStmt->close();

        if (!$issueForResponse || $issueForResponse['status'] !== 'awaiting_information') {
            throw new DomainException('This issue is not awaiting information from you.');
        }

        $pendingStatus = 'pending_admin_review';
        $updateIssue = $conn->prepare('UPDATE laptop_issues SET status = ? WHERE id = ?');
        $updateIssue->bind_param('si', $pendingStatus, $issueId);
        $updateIssue->execute();
        $updateIssue->close();
        record_laptop_issue_update(
            $conn,
            $issueId,
            $user['id'],
            'awaiting_information',
            $pendingStatus,
            $responseNote
        );

        $responseMessage = $issueForResponse['reference_code'] . ' · Employee provided requested information.';
        if ($issueForResponse['assigned_admin_id'] !== null) {
            create_user_notification(
                $conn,
                (int) $issueForResponse['assigned_admin_id'],
                $issueId,
                'Issue information received',
                $responseMessage
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
                    'Issue information received',
                    $responseMessage
                );
            }
        }

        audit($conn, 'laptop_issue_information_provided', 'laptop_issue', $issueId, [
            'reference_code' => $issueForResponse['reference_code'],
        ]);
        $conn->commit();
        flash('Your response was sent to the administrator.');
        header('Location: my_laptop.php?id=' . (int) $issueForResponse['laptop_id']);
        exit;
    } catch (DomainException $exception) {
        $conn->rollback();
        http_response_code(409);
        exit(e($exception->getMessage()));
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Laptop issue response failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Your response could not be saved.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notification_read') {
    verify_csrf();
    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$notificationId) {
        http_response_code(422);
        exit('Invalid notification.');
    }

    $read = $conn->prepare(
        'UPDATE user_notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL'
    );
    $read->bind_param('ii', $notificationId, $user['id']);
    $read->execute();
    $read->close();
    header('Location: my_laptop.php');
    exit;
}

if ($isAdmin) {
    $stmt = $conn->prepare(
        'SELECT l.*, u.full_name AS assigned_name,
                (SELECT COUNT(*) FROM laptop_issues i
                 WHERE i.laptop_id = l.id
                   AND i.status NOT IN ("rejected", "duplicate", "resolved")) AS open_issues
         FROM laptops l
         LEFT JOIN users u ON u.id = l.assigned_to
         ORDER BY l.asset_tag'
    );
} else {
    $stmt = $conn->prepare(
        'SELECT l.*, u.full_name AS assigned_name,
                (SELECT COUNT(*) FROM laptop_issues i
                 WHERE i.laptop_id = l.id
                   AND i.status NOT IN ("rejected", "duplicate", "resolved")) AS open_issues
         FROM laptops l
         LEFT JOIN users u ON u.id = l.assigned_to
         WHERE l.assigned_to = ?
         ORDER BY l.asset_tag'
    );
    $stmt->bind_param('i', $user['id']);
}
$stmt->execute();
$laptopResult = $stmt->get_result();
$laptops = $laptopResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedLaptop = null;
if ($laptops !== []) {
    if (!isset($_GET['id'])) {
        $selectedLaptop = $laptops[0];
    } else {
        $requestedId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if ($requestedId !== false) {
            foreach ($laptops as $laptop) {
                if ((int) $laptop['id'] === $requestedId) {
                    $selectedLaptop = $laptop;
                    break;
                }
            }
        }
    }

    if ($selectedLaptop === null) {
        http_response_code(404);
    }
}

$issues = [];
$maintenance = [];
if ($selectedLaptop !== null) {
    $issueStmt = $conn->prepare(
        '        SELECT id, reference_code, title, severity, status, source, created_at, reported_by
         FROM laptop_issues
         WHERE laptop_id = ?
         ORDER BY created_at DESC LIMIT 10'
    );
    $issueStmt->bind_param('i', $selectedLaptop['id']);
    $issueStmt->execute();
    $issues = $issueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $issueStmt->close();

    $maintenanceStmt = $conn->prepare(
        'SELECT id, issue_description, status, created_at, repaired_at
         FROM maintenance_records
         WHERE laptop_id = ? AND status = "resolved"
         ORDER BY COALESCE(repaired_at, created_at) DESC LIMIT 5'
    );
    $maintenanceStmt->bind_param('i', $selectedLaptop['id']);
    $maintenanceStmt->execute();
    $maintenance = $maintenanceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $maintenanceStmt->close();

}

layout_start('My Laptop');
?>
<section class="virtual-laptop-page">
    <div class="page-heading">
        <div>
            <div class="eyebrow">EMPLOYEE DEVICE PORTAL</div>
            <h1><?= $isAdmin ? 'Virtual device overview' : 'My Laptop' ?></h1>
            <p class="page-description">View assigned equipment, inventory details, and reported issue updates.</p>
        </div>
        <?php if ($isAdmin): ?>
            <a class="button secondary" href="laptop_issues.php">Review reported issues</a>
        <?php endif; ?>
    </div>

    <?php if ($notifications !== []): ?>
        <section class="panel laptop-notifications" aria-label="Recent notifications">
            <h2>Recent updates</h2>
            <?php foreach ($notifications as $notification): ?>
                <div class="laptop-notification-item">
                    <p><strong><?= e($notification['title']) ?></strong> — <?= e($notification['message']) ?>
                        <small><?= e($notification['created_at']) ?></small></p>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                        <input type="hidden" name="action" value="mark_notification_read">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                        <button class="secondary" type="submit">Mark read</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($laptops === []): ?>
        <section class="panel laptop-empty">
            <div class="laptop-empty-mark">—</div>
            <h2>No laptop assigned yet</h2>
            <p>Your assigned company laptop will appear here. If you believe this is a mistake, contact your system administrator.</p>
            <?php if ($isAdmin): ?><a class="button" href="directory_sync.php">Open directory inventory</a><?php endif; ?>
        </section>
    <?php elseif ($selectedLaptop === null): ?>
        <section class="panel laptop-empty">
            <h2>This laptop is not available</h2>
            <p>You can only open devices assigned to your account.</p>
            <a class="button secondary" href="my_laptop.php">Return to my devices</a>
        </section>
    <?php else: ?>
        <?php if (count($laptops) > 1): ?>
            <form class="laptop-selector" method="get">
                <label for="laptop-select">Choose a laptop</label>
                <select id="laptop-select" name="id" onchange="this.form.submit()">
                    <?php foreach ($laptops as $laptop): ?>
                        <option value="<?= (int) $laptop['id'] ?>" <?= (int) $laptop['id'] === (int) $selectedLaptop['id'] ? 'selected' : '' ?>>
                            <?= e($laptop['asset_tag'] . ' · ' . ($laptop['ad_computer_name'] ?: $laptop['brand'] . ' ' . $laptop['model'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <section class="virtual-device-panel">
            <div class="virtual-device-heading">
                <div>
                    <div class="eyebrow">VIRTUAL DEVICE · NOT REMOTE POWER CONTROL</div>
                    <h2><?= e($selectedLaptop['ad_computer_name'] ?: $selectedLaptop['asset_tag']) ?></h2>
                    <p><?= e($selectedLaptop['asset_tag']) ?> · <?= e($selectedLaptop['brand'] . ' ' . $selectedLaptop['model']) ?></p>
                </div>
                <span class="device-status-pill"><?= e($selectedLaptop['status']) ?></span>
            </div>

            <div class="virtual-laptop" data-state="<?= e($selectedLaptop['virtual_power_state']) ?>">
                <div class="virtual-screen-frame">
                    <div class="virtual-screen" id="virtual-screen">
                        <div class="virtual-desktop" id="virtual-desktop">
                            <span class="desktop-brand">SLK <small>ASSET MANAGEMENT</small></span>
                            <div>
                                <span class="desktop-status-dot"></span>
                                <p class="desktop-device"><?= e($selectedLaptop['ad_computer_name'] ?: $selectedLaptop['asset_tag']) ?></p>
                                <p><?= e($selectedLaptop['ad_operating_system'] ?: 'Operating system not reported') ?></p>
                                <p class="desktop-note">This is a visual representation of the asset, not a connection to the physical laptop.</p>
                            </div>
                            <a class="desktop-details-link" href="#device-details">View device details ↓</a>
                        </div>
                        <div class="virtual-screen-off" id="virtual-screen-off" <?= $selectedLaptop['virtual_power_state'] === 'on' ? 'hidden' : '' ?>>
                            <span class="screen-off-indicator"></span>
                            <strong>Powered off</strong>
                            <small>Virtual display only</small>
                        </div>
                    </div>
                </div>
                <div class="virtual-hinge"></div>
                <div class="virtual-base">
                    <div class="virtual-keyboard" aria-hidden="true">
                        <?php for ($row = 0; $row < 3; $row++): ?>
                            <div class="keyboard-row"><?php for ($key = 0; $key < 13; $key++): ?><i></i><?php endfor; ?></div>
                        <?php endfor; ?>
                    </div>
                    <div class="virtual-trackpad" aria-hidden="true"></div>
                    <button
                        type="button"
                        class="virtual-power-button"
                        id="virtual-power-button"
                        aria-pressed="<?= $selectedLaptop['virtual_power_state'] === 'on' ? 'true' : 'false' ?>"
                        data-laptop-id="<?= (int) $selectedLaptop['id'] ?>"
                        data-csrf="<?= e(csrf()) ?>"
                    >
                        <span class="power-symbol">⏻</span>
                        <span id="power-label"><?= $selectedLaptop['virtual_power_state'] === 'on' ? 'Turn virtual display off' : 'Turn virtual display on' ?></span>
                    </button>
                </div>
            </div>
            <p class="virtual-disclaimer">Changing this virtual state never turns the physical device on or off.</p>
            <p class="power-error" id="power-error" role="alert" hidden></p>
            <div class="virtual-actions">
                <a class="button" href="report_laptop_issue.php?laptop_id=<?= (int) $selectedLaptop['id'] ?>">Report an issue</a>
                <a class="button secondary" href="#device-details">Device details</a>
            </div>
        </section>

        <section class="split laptop-information-grid" id="device-details">
            <article class="panel">
                <div class="panel-header"><div><h2>Device details</h2><span>Inventory values currently on record.</span></div></div>
                <dl class="device-details">
                    <?php foreach ([
                        'Asset tag' => $selectedLaptop['asset_tag'],
                        'Inventory serial' => $selectedLaptop['serial_number'],
                        'Manufacturer serial' => $selectedLaptop['manufacturer_serial'] ?: 'Not available',
                        'Computer name' => $selectedLaptop['ad_computer_name'] ?: 'Not available',
                        'Manufacturer' => $selectedLaptop['brand'],
                        'Model' => $selectedLaptop['model'],
                        'Operating system' => $selectedLaptop['ad_operating_system'] ?: 'Not available',
                        'OS version' => $selectedLaptop['ad_operating_system_version'] ?: 'Not available',
                        'Processor' => $selectedLaptop['processor_description'] ?: 'Not available',
                        'Memory' => $selectedLaptop['ram_gb'] ? $selectedLaptop['ram_gb'] . ' GB' : 'Not available',
                        'Storage' => $selectedLaptop['storage_gb'] ? $selectedLaptop['storage_gb'] . ' GB' : 'Not available',
                        'Department' => $selectedLaptop['department'] ?: 'Not available',
                        'Assigned employee' => $selectedLaptop['assigned_name'] ?: 'Unassigned',
                        'Purchase date' => $selectedLaptop['purchase_date'] ?: 'Not available',
                        'Warranty expiry' => $selectedLaptop['warranty_expiry'] ?: 'Not available',
                        'Asset status' => $selectedLaptop['status'],
                        'Virtual power' => strtoupper($selectedLaptop['virtual_power_state']),
                        'AD inventory sync' => $selectedLaptop['ad_last_synced_at'] ?: 'Not available',
                        'Device last seen' => 'Not configured',
                        'Monitoring agent' => 'Not configured',
                    ] as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e((string) $value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <p class="monitoring-note">Active Directory sync updates inventory data, not live health or connectivity. No monitoring check-in has been configured for this device.</p>
            </article>

            <div>
                <article class="panel">
                    <div class="panel-header"><div><h2>Reported issues</h2><span>Your reports and their current review status.</span></div></div>
                    <?php if ($issues === []): ?>
                        <p class="muted">No issues have been reported for this laptop.</p>
                    <?php else: ?>
                        <div class="issue-list">
                            <?php foreach ($issues as $issue): ?>
                                <div class="issue-list-item">
                                    <div><strong><?= e($issue['title']) ?></strong><small><?= e($issue['reference_code']) ?> · <?= e($issue['created_at']) ?></small></div>
                                    <span class="issue-state issue-state-<?= e($issue['status']) ?>"><?= e(laptop_issue_status_label($issue['status'])) ?></span>
                                    <?php if ($issue['status'] === 'awaiting_information' && (int) $issue['reported_by'] === $user['id']): ?>
                                        <form method="post" class="issue-response-form">
                                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                            <input type="hidden" name="action" value="respond_to_issue">
                                            <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                                            <label>Administrator requested more information
                                                <textarea name="response_note" maxlength="2000" required></textarea>
                                            </label>
                                            <button type="submit">Send response</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="panel">
                    <div class="panel-header"><div><h2>Maintenance history</h2><span>Completed work on this device.</span></div></div>
                    <?php if ($maintenance === []): ?>
                        <p class="muted">No completed maintenance records are available.</p>
                    <?php else: ?>
                        <?php foreach ($maintenance as $record): ?>
                            <div class="maintenance-history-item">
                                <strong><?= e($record['issue_description']) ?></strong>
                                <small><?= e($record['repaired_at'] ?: $record['created_at']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>
            </div>
        </section>
    <?php endif; ?>
</section>

<?php if ($selectedLaptop !== null): ?>
<script>
(() => {
    const button = document.getElementById('virtual-power-button');
    const laptop = document.querySelector('.virtual-laptop');
    const screenOff = document.getElementById('virtual-screen-off');
    const label = document.getElementById('power-label');
    const error = document.getElementById('power-error');

    button.addEventListener('click', async () => {
        const nextState = button.getAttribute('aria-pressed') === 'true' ? 'off' : 'on';
        const body = new URLSearchParams({
            laptop_id: button.dataset.laptopId,
            state: nextState,
            csrf: button.dataset.csrf
        });
        button.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch('api/laptop_power_state.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Could not save virtual power state.');
            const isOn = result.state === 'on';
            laptop.dataset.state = result.state;
            screenOff.hidden = isOn;
            button.setAttribute('aria-pressed', String(isOn));
            label.textContent = isOn ? 'Turn virtual display off' : 'Turn virtual display on';
        } catch (requestError) {
            error.textContent = requestError.message;
            error.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
<?php endif; ?>
<?php layout_end(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login();

$userId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($userId === false || $userId === null) {
    http_response_code(400);
    exit('Invalid user identifier.');
}

$currentUser = current_user();
$isAdmin = in_array($currentUser['role'], ['admin', 'super_admin'], true);
if (!$isAdmin && $currentUser['id'] !== $userId) {
    http_response_code(403);
    exit('You do not have access to this user history.');
}

$userStmt = $conn->prepare(
    'SELECT id, full_name, email, role, status, created_at
     FROM users
     WHERE id = ?'
);
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$profile = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!$profile) {
    http_response_code(404);
    exit('User not found.');
}

$devicesStmt = $conn->prepare(
    'SELECT l.id, l.asset_tag, l.brand, l.model, l.status,
            l.assigned_to = ? AS is_current,
            MIN(h.action_date) AS first_used,
            MAX(h.action_date) AS last_seen
     FROM laptops l
     LEFT JOIN laptop_history h ON h.laptop_id = l.id AND h.user_id = ?
     WHERE h.id IS NOT NULL OR l.assigned_to = ?
     GROUP BY l.id, l.asset_tag, l.brand, l.model, l.status, l.assigned_to
     ORDER BY is_current DESC, last_seen DESC'
);
$devicesStmt->bind_param('iii', $userId, $userId, $userId);
$devicesStmt->execute();
$devices = $devicesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$devicesStmt->close();

$historyStmt = $conn->prepare(
    "SELECT
        'Laptop' COLLATE utf8mb4_unicode_ci AS asset_type,
        l.id AS laptop_id,
        l.asset_tag COLLATE utf8mb4_unicode_ci AS asset_name,
        h.action_type COLLATE utf8mb4_unicode_ci AS action_type,
        a.full_name COLLATE utf8mb4_unicode_ci AS recorded_by,
        h.action_date
     FROM laptop_history h
     LEFT JOIN laptops l ON l.id = h.laptop_id
     LEFT JOIN users a ON a.id = h.admin_id
     WHERE h.user_id = ?

     UNION ALL

     SELECT
        'Software' COLLATE utf8mb4_unicode_ci AS asset_type,
        NULL AS laptop_id,
        s.software_name COLLATE utf8mb4_unicode_ci AS asset_name,
        sh.action_type COLLATE utf8mb4_unicode_ci AS action_type,
        a.full_name COLLATE utf8mb4_unicode_ci AS recorded_by,
        sh.action_date
     FROM software_history sh
     LEFT JOIN softwares s ON s.id = sh.software_id
     LEFT JOIN users a ON a.id = sh.admin_id
     WHERE sh.user_id = ?
     ORDER BY action_date DESC"
);
$historyStmt->bind_param('ii', $userId, $userId);
$historyStmt->execute();
$history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();

layout_start('User history');
?>
<section class="hero">
    <div>
        <div class="eyebrow">USER PROFILE &amp; ACTIVITY</div>
        <h1><?= e($profile['full_name']) ?></h1>
        <p><?= e($profile['email']) ?> · <?= e(ucwords(str_replace('_', ' ', $profile['role']))) ?> · <?= e(ucfirst($profile['status'])) ?></p>
    </div>
    <a class="button secondary" href="<?= $isAdmin ? 'users.php' : 'dashboard.php' ?>">&larr; Back</a>
</section>

<section class="metrics-grid history-metrics" aria-label="User history summary">
    <article class="metric-card">
        <div class="metric-top"><span>Devices used</span><span class="metric-icon">▱</span></div>
        <strong class="metric-number"><?= count($devices) ?></strong>
        <span class="metric-label">Current and previous laptop assignments</span>
    </article>
    <article class="metric-card">
        <div class="metric-top"><span>Activity records</span><span class="metric-icon">◷</span></div>
        <strong class="metric-number"><?= count($history) ?></strong>
        <span class="metric-label">Laptop and software history events</span>
    </article>
    <article class="metric-card">
        <div class="metric-top"><span>Registered</span><span class="metric-icon">♙</span></div>
        <strong class="metric-number"><?= e(date('M Y', strtotime($profile['created_at']))) ?></strong>
        <span class="metric-label">Account creation date</span>
    </article>
</section>

<section class="panel">
    <div class="panel-header">
        <div><h2>Devices used</h2><span>Every laptop with a recorded activity for this user, including their current assignment.</span></div>
    </div>
    <?php if ($devices): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Laptop</th><th>Status</th><th>First recorded use</th><th>Last recorded activity</th><th>Assignment</th></tr></thead>
                <tbody>
                <?php foreach ($devices as $device): ?>
                    <?php
                    $firstUsed = $device['first_used'] ? date('M j, Y', strtotime($device['first_used'])) : '—';
                    $lastSeen = $device['last_seen'] ? date('M j, Y, g:i a', strtotime($device['last_seen'])) : '—';
                    ?>
                    <tr>
                        <td>
                            <a class="asset-name" href="history.php?laptop_id=<?= (int) $device['id'] ?>"><?= e($device['asset_tag']) ?></a>
                            <br><small><?= e($device['brand'] . ' ' . $device['model']) ?></small>
                        </td>
                        <td><?= e($device['status']) ?></td>
                        <td><?= e($firstUsed) ?></td>
                        <td><?= e($lastSeen) ?></td>
                        <td><?= (int) $device['is_current'] === 1 ? 'Currently assigned' : 'Previous user' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><strong>No devices recorded</strong>This user has no laptop assignments in the available history.</div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-header">
        <div><h2>Complete activity history</h2><span>All laptop and software events associated with this user.</span></div>
    </div>
    <?php if ($history): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Asset type</th><th>Asset</th><th>Action</th><th>Recorded by</th><th>Date and time</th></tr></thead>
                <tbody>
                <?php foreach ($history as $event): ?>
                    <?php
                    $eventTimestamp = strtotime($event['action_date']);
                    $eventDate = $eventTimestamp !== false ? date('M j, Y, g:i a', $eventTimestamp) : $event['action_date'];
                    ?>
                    <tr>
                        <td><?= e($event['asset_type']) ?></td>
                        <td>
                            <?php if ($event['laptop_id'] !== null): ?>
                                <a class="asset-name" href="history.php?laptop_id=<?= (int) $event['laptop_id'] ?>"><?= e($event['asset_name'] ?? '') ?: 'Unavailable' ?></a>
                            <?php else: ?>
                                <?= e($event['asset_name'] ?? '') ?: 'Unavailable' ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="action-badge"><?= e($event['action_type']) ?></span></td>
                        <td><?= e($event['recorded_by'] ?? '') ?: '—' ?></td>
                        <td class="date-cell"><?= e($eventDate) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><strong>No activity recorded</strong>User activity will appear here when assets or software are assigned or updated.</div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>

<?php
declare(strict_types=1);

function laptop_portal_schema_ready(mysqli $conn): bool
{
    $requiredTables = ['laptop_issues', 'laptop_issue_updates', 'user_notifications'];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT table_name) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ($placeholders)"
    );
    [$issuesTable, $updatesTable, $notificationsTable] = $requiredTables;
    $stmt->bind_param('sss', $issuesTable, $updatesTable, $notificationsTable);
    $stmt->execute();
    $tablesReady = (int) $stmt->get_result()->fetch_assoc()['total'] === count($requiredTables);
    $stmt->close();

    if (!$tablesReady) {
        return false;
    }

    $columns = ['responsible_admin_id', 'virtual_power_state'];
    $columnPlaceholders = implode(',', array_fill(0, count($columns), '?'));
    $columnStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT column_name) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'laptops'
           AND column_name IN ($columnPlaceholders)"
    );
    [$responsibleAdminColumn, $virtualPowerColumn] = $columns;
    $columnStmt->bind_param('ss', $responsibleAdminColumn, $virtualPowerColumn);
    $columnStmt->execute();
    $columnsReady = (int) $columnStmt->get_result()->fetch_assoc()['total'] === count($columns);
    $columnStmt->close();

    if (!$columnsReady) {
        return false;
    }

    $maintenanceStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'maintenance_records'
           AND column_name = 'source_issue_id'"
    );
    $maintenanceStmt->execute();
    $maintenanceReady = (int) $maintenanceStmt->get_result()->fetch_assoc()['total'] === 1;
    $maintenanceStmt->close();

    return $maintenanceReady;
}

function create_user_notification(
    mysqli $conn,
    int $userId,
    ?int $issueId,
    string $title,
    string $message
): void {
    $stmt = $conn->prepare(
        'INSERT INTO user_notifications (user_id, issue_id, title, message) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('iiss', $userId, $issueId, $title, $message);
    $stmt->execute();
    $stmt->close();
}

function record_laptop_issue_update(
    mysqli $conn,
    int $issueId,
    ?int $actorId,
    ?string $oldStatus,
    string $newStatus,
    ?string $note
): void {
    $stmt = $conn->prepare(
        'INSERT INTO laptop_issue_updates (issue_id, actor_id, old_status, new_status, note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisss', $issueId, $actorId, $oldStatus, $newStatus, $note);
    $stmt->execute();
    $stmt->close();
}

function laptop_issue_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

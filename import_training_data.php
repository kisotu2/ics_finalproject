<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/db.php';

$csvFile = __DIR__ . '/ira_100_device_maintenance_dataset.csv';

if (!file_exists($csvFile)) {
    exit('CSV file not found. Put ira_100_device_maintenance_dataset.csv inside the Asset folder.');
}

$handle = fopen($csvFile, 'r');

if (!$handle) {
    exit('Unable to open the CSV file.');
}

$headers = fgetcsv($handle);

if (!$headers) {
    fclose($handle);
    exit('The CSV file is empty.');
}

$headers = array_map('trim', $headers);

$required = [
    'asset_tag',
    'device_type',
    'brand',
    'model',
    'department',
    'assigned_user',
    'location',
    'latitude',
    'longitude',
    'purchase_date',
    'warranty_expiry',
    'repair_count',
    'open_repair_count',
    'maintenance_date',
    'maintenance_issue',
    'repair_cost_kes',
    'maintenance_status',
    'active_hours_30d',
    'crash_count_30d',
    'battery_health_percent',
    'failed_within_90_days'
];

foreach ($required as $column) {
    if (!in_array($column, $headers, true)) {
        fclose($handle);
        exit("Missing CSV column: {$column}");
    }
}

$inserted = 0;
$skipped = 0;
$errors = [];

$conn->begin_transaction();

try {

    while (($data = fgetcsv($handle)) !== false) {

        if (count($data) !== count($headers)) {
            $skipped++;
            continue;
        }

        $row = array_combine($headers, $data);

        $assetTag = trim($row['asset_tag']);

        if ($assetTag === '') {
            $skipped++;
            continue;
        }

        /*
         * Check whether this asset already exists.
         */
        $check = $conn->prepare(
            "SELECT id FROM laptops WHERE asset_tag = ? LIMIT 1"
        );

        $check->bind_param('s', $assetTag);
        $check->execute();

        $existing = $check->get_result()->fetch_assoc();

        if ($existing) {
            $skipped++;
            continue;
        }

        /*
         * Generate a unique serial number.
         */
        $serialNumber = 'IRA-SN-' . $assetTag;

        /*
         * Insert laptop.
         */
        $stmt = $conn->prepare("
            INSERT INTO laptops
            (
                asset_tag,
                serial_number,
                brand,
                model,
                department,
                status,
                purchase_date,
                warranty_expiry
            )
            VALUES (?, ?, ?, ?, ?, 'Available', ?, ?)
        ");

        $stmt->bind_param(
            'sssssss',
            $assetTag,
            $serialNumber,
            $row['brand'],
            $row['model'],
            $row['department'],
            $row['purchase_date'],
            $row['warranty_expiry']
        );

        $stmt->execute();

        $laptopId = $conn->insert_id;

        /*
         * Insert maintenance history.
         *
         * The CSV contains the total number of repairs.
         * We create one representative maintenance record
         * for assets that have repair history.
         */
        $repairCount = (int)$row['repair_count'];
        $openRepairs = (int)$row['open_repair_count'];

        if ($repairCount > 0) {

            $maintenanceStatus = 'resolved';

            if ($openRepairs > 0) {
                $maintenanceStatus = 'open';
            }

            $reportedBy = null;

            $issue = $row['maintenance_issue'];
            $repairCost = (float)$row['repair_cost_kes'];

            $maintenanceDate = null;

            if (!empty($row['maintenance_date'])) {
                $maintenanceDate = $row['maintenance_date'] . ' 00:00:00';
            }

            $maintenance = $conn->prepare("
                INSERT INTO maintenance_records
                (
                    laptop_id,
                    reported_by,
                    issue_description,
                    repair_cost,
                    repaired_at,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $maintenance->bind_param(
                'iisdss',
                $laptopId,
                $reportedBy,
                $issue,
                $repairCost,
                $maintenanceDate,
                $maintenanceStatus
            );

            $maintenance->execute();

            /*
             * Create additional records for repeated repairs.
             *
             * This makes the database reflect the repair_count
             * used by the ML model.
             */
            for ($r = 1; $r < $repairCount; $r++) {

                $extraIssue = 'Previous maintenance record';
                $extraCost = 0.00;
                $extraStatus = 'resolved';

                $extra = $conn->prepare("
                    INSERT INTO maintenance_records
                    (
                        laptop_id,
                        reported_by,
                        issue_description,
                        repair_cost,
                        repaired_at,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $extra->bind_param(
                    'iisdss',
                    $laptopId,
                    $reportedBy,
                    $extraIssue,
                    $extraCost,
                    $maintenanceDate,
                    $extraStatus
                );

                $extra->execute();
            }
        }

        /*
         * Insert usage information.
         *
         * The 30-day values from the CSV are stored
         * as one representative usage record.
         */
        $usageDate = date('Y-m-d');

        $activeHours = (float)$row['active_hours_30d'];
        $crashes = (int)$row['crash_count_30d'];
        $battery = (int)$row['battery_health_percent'];

        $usage = $conn->prepare("
            INSERT INTO device_usage_daily
            (
                laptop_id,
                usage_date,
                active_hours,
                crash_count,
                battery_health_percent
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $usage->bind_param(
            'isdii',
            $laptopId,
            $usageDate,
            $activeHours,
            $crashes,
            $battery
        );

        $usage->execute();

        /*
         * Insert GPS location.
         *
         * Since this is synthetic project data,
         * the location is marked as managed_client.
         */
        $latitude = (float)$row['latitude'];
        $longitude = (float)$row['longitude'];
        $accuracy = 10.00;
        $capturedAt = date('Y-m-d H:i:s');

        /*
         * Find an existing system user to associate
         * with the imported location.
         */
        $userResult = $conn->query("
            SELECT id
            FROM users
            ORDER BY id ASC
            LIMIT 1
        ");

        $user = $userResult->fetch_assoc();

        if (!$user) {
            throw new Exception(
                'At least one user must exist before importing device locations.'
            );
        }

        $createdBy = (int)$user['id'];

        $location = $conn->prepare("
            INSERT INTO device_locations
            (
                laptop_id,
                latitude,
                longitude,
                accuracy_meters,
                captured_at,
                source,
                consent_status,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, 'managed_client', 'granted', ?)
        ");

        $location->bind_param(
            'idddsi',
            $laptopId,
            $latitude,
            $longitude,
            $accuracy,
            $capturedAt,
            $createdBy
        );

        $location->execute();

        /*
         * Calculate the ML features.
         */
        $purchaseDate = new DateTime($row['purchase_date']);
        $today = new DateTime();

        $ageDays = $purchaseDate->diff($today)->days;
        $assetAgeYears = round($ageDays / 365.25, 2);

        $warrantyDate = new DateTime($row['warranty_expiry']);

        $warrantyDays = (int)$today->diff(
            $warrantyDate
        )->format('%r%a');

        $failed = (int)$row['failed_within_90_days'];

        /*
         * Insert training data.
         */
        $training = $conn->prepare("
            INSERT INTO maintenance_training_data
            (
                laptop_id,
                repair_count,
                open_repair_count,
                asset_age_years,
                warranty_days_remaining,
                active_hours_30d,
                crash_count_30d,
                battery_health_percent,
                failed_within_90_days
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $training->bind_param(
            'iiidididi',
            $laptopId,
            $repairCount,
            $openRepairs,
            $assetAgeYears,
            $warrantyDays,
            $activeHours,
            $crashes,
            $battery,
            $failed
        );

        $training->execute();

        $inserted++;
    }

    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();

    fclose($handle);

    exit(
        '<h2>Import failed</h2>' .
        '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
    );
}

fclose($handle);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Data Import</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 40px;
        }

        .box {
            max-width: 650px;
            margin: auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,.08);
        }

        h1 {
            margin-top: 0;
        }

        .success {
            color: #237a3b;
        }

        .warning {
            color: #a56a00;
        }
    </style>
</head>

<body>

<div class="box">

    <h1>Device Data Import</h1>

    <p class="success">
        Import completed successfully.
    </p>

    <p>
        <strong><?= $inserted ?></strong>
        devices imported.
    </p>

    <p class="warning">
        <?= $skipped ?> records skipped.
    </p>

    <hr>

    <p>
        The imported data has been added to:
    </p>

    <ul>
        <li>laptops</li>
        <li>maintenance_records</li>
        <li>device_usage_daily</li>
        <li>device_locations</li>
        <li>maintenance_training_data</li>
    </ul>

    <p>
        You can now train the predictive maintenance model.
    </p>

</div>

</body>
</html>
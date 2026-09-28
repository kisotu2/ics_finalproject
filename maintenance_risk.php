<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require __DIR__ . '/bootstrap.php';

require_login(['admin', 'super_admin']);

require_once __DIR__ . '/backend/maintenance_risk.php';


/*
|--------------------------------------------------------------------------
| Get Laptop ID
|--------------------------------------------------------------------------
*/

$laptopId = (int)($_GET['laptop_id'] ?? 0);

if ($laptopId <= 0) {

    flash('No valid laptop was selected.');

    header('Location: maintenance.php');

    exit;
}


/*
|--------------------------------------------------------------------------
| Get Laptop Details
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        asset_tag,
        serial_number,
        brand,
        model,
        department,
        processor_tier,
        ram_gb,
        storage_gb,
        assigned_to,
        status,
        purchase_date,
        warranty_expiry,
        created_at
    FROM laptops
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $laptopId
);

$stmt->execute();

$result = $stmt->get_result();

$laptop = $result->fetch_assoc();

$stmt->close();


if (!$laptop) {

    flash('Laptop not found.');

    header('Location: maintenance.php');

    exit;
}


/*
|--------------------------------------------------------------------------
| Generate Maintenance Risk
|--------------------------------------------------------------------------
|
| true = save the prediction to maintenance_risk_predictions.
|
*/

$prediction = maintenance_risk(
    $laptopId,
    true
);


/*
|--------------------------------------------------------------------------
| Maintenance History
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        m.id,
        m.issue_description,
        m.repair_cost,
        m.status,
        m.repaired_at,
        m.created_at
    FROM maintenance_records m
    WHERE m.laptop_id = ?
    ORDER BY m.created_at DESC
");

$stmt->bind_param(
    'i',
    $laptopId
);

$stmt->execute();

$maintenanceHistory = $stmt->get_result();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Device Usage History
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        usage_date,
        active_hours,
        crash_count,
        battery_health_percent
    FROM device_usage_daily
    WHERE laptop_id = ?
    ORDER BY usage_date DESC
    LIMIT 30
");

$stmt->bind_param(
    'i',
    $laptopId
);

$stmt->execute();

$usageHistory = $stmt->get_result();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Latest Saved Prediction
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        risk_score,
        risk_level,
        model_version,
        factors,
        predicted_at
    FROM maintenance_risk_predictions
    WHERE laptop_id = ?
    ORDER BY predicted_at DESC
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $laptopId
);

$stmt->execute();

$latestPrediction = $stmt->get_result()->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

layout_start('Maintenance Risk');

?>

<div class="hero">

    <div>

        <h1>
            Maintenance Risk Details
        </h1>

        <p class="muted">
            Predictive maintenance analysis for
            <strong>
                <?= e($laptop['asset_tag']) ?>
            </strong>
        </p>

    </div>

    <div>

        <a
            href="maintenance.php"
            style="
                display:inline-block;
                text-decoration:none;
            "
        >
            ← Back to Maintenance
        </a>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| Laptop Information
|--------------------------------------------------------------------------
-->

<section style="margin-top:24px">

    <div class="panel">

        <h2>
            Laptop Details
        </h2>

        <table>

            <tbody>

                <tr>
                    <th>Asset Tag</th>

                    <td>
                        <?= e($laptop['asset_tag']) ?>
                    </td>
                </tr>

                <tr>
                    <th>Serial Number</th>

                    <td>
                        <?= e($laptop['serial_number']) ?>
                    </td>
                </tr>

                <tr>
                    <th>Brand</th>

                    <td>
                        <?= e($laptop['brand']) ?>
                    </td>
                </tr>

                <tr>
                    <th>Model</th>

                    <td>
                        <?= e($laptop['model']) ?>
                    </td>
                </tr>

                <tr>
                    <th>Department</th>

                    <td>
                        <?= e(
                            $laptop['department']
                            ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Processor Tier</th>

                    <td>
                        <?= e(
                            $laptop['processor_tier']
                            ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>RAM</th>

                    <td>

                        <?php if ($laptop['ram_gb'] !== null): ?>

                            <?= e($laptop['ram_gb']) ?> GB

                        <?php else: ?>

                            Not recorded

                        <?php endif; ?>

                    </td>
                </tr>

                <tr>
                    <th>Storage</th>

                    <td>

                        <?php if ($laptop['storage_gb'] !== null): ?>

                            <?= e($laptop['storage_gb']) ?> GB

                        <?php else: ?>

                            Not recorded

                        <?php endif; ?>

                    </td>
                </tr>

                <tr>
                    <th>Assigned To</th>

                    <td>

                        <?= $laptop['assigned_to']
                            ? e($laptop['assigned_to'])
                            : 'Not assigned'
                        ?>

                    </td>
                </tr>

                <tr>
                    <th>Status</th>

                    <td>
                        <strong>
                            <?= e($laptop['status']) ?>
                        </strong>
                    </td>
                </tr>

                <tr>
                    <th>Purchase Date</th>

                    <td>
                        <?= e(
                            $laptop['purchase_date']
                            ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Warranty Expiry</th>

                    <td>
                        <?= e(
                            $laptop['warranty_expiry']
                            ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Created At</th>

                    <td>
                        <?= e($laptop['created_at']) ?>
                    </td>
                </tr>

            </tbody>

        </table>

    </div>

</section>


<!--
|--------------------------------------------------------------------------
| Prediction Result
|--------------------------------------------------------------------------
-->

<section style="margin-top:24px">

    <div class="panel">

        <h2>
            Predictive Maintenance Result
        </h2>

        <?php if ($prediction['success'] ?? false): ?>

            <?php

            $level =
                $prediction['level']
                ?? 'Unknown';

            $score =
                (float)(
                    $prediction['score']
                    ?? 0
                );

            $riskClass = '';

            if ($level === 'High') {

                $riskClass = 'danger';

            } elseif ($level === 'Medium') {

                $riskClass = 'warning';
            }

            ?>

            <div
                class="<?= e($riskClass) ?>"
                style="
                    padding:20px;
                    margin-bottom:20px;
                    border-radius:10px;
                "
            >

                <h2 style="margin-top:0">

                    <?= e($level) ?> Risk

                </h2>

                <p style="font-size:20px">

                    Maintenance Risk Score:

                    <strong>

                        <?= number_format(
                            $score * 100,
                            2
                        ) ?>%

                    </strong>

                </p>

                <p class="muted">

                    Model:
                    <?= e(
                        $prediction['version']
                        ?? 'logistic-regression-v1'
                    ) ?>

                </p>

            </div>


            <!--
            |--------------------------------------------------------------------------
            | Prediction Features
            |--------------------------------------------------------------------------
            -->

            <h3>
                Prediction Factors
            </h3>

            <table>

                <thead>

                    <tr>

                        <th>
                            Feature
                        </th>

                        <th>
                            Value
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <tr>

                        <td>
                            Repair Count
                        </td>

                        <td>
                            <?= e(
                                $prediction['features']['repair_count']
                                ?? 0
                            ) ?>
                        </td>

                    </tr>

                    <tr>

                        <td>
                            Open Repair Count
                        </td>

                        <td>
                            <?= e(
                                $prediction['features']['open_repair_count']
                                ?? 0
                            ) ?>
                        </td>

                    </tr>

                    <tr>

                        <td>
                            Asset Age
                        </td>

                        <td>

                            <?= number_format(
                                (float)(
                                    $prediction['features']['asset_age_years']
                                    ?? 0
                                ),
                                2
                            ) ?>

                            years

                        </td>

                    </tr>

                    <tr>

                        <td>
                            Warranty Days Remaining
                        </td>

                        <td>

                            <?= e(
                                $prediction['features']['warranty_days_remaining']
                                ?? 0
                            ) ?>

                            days

                        </td>

                    </tr>

                    <tr>

                        <td>
                            Active Hours — Last 30 Days
                        </td>

                        <td>

                            <?= number_format(
                                (float)(
                                    $prediction['features']['active_hours_30d']
                                    ?? 0
                                ),
                                1
                            ) ?>

                            hours

                        </td>

                    </tr>

                    <tr>

                        <td>
                            Crashes — Last 30 Days
                        </td>

                        <td>

                            <?= e(
                                $prediction['features']['crash_count_30d']
                                ?? 0
                            ) ?>

                        </td>

                    </tr>

                    <tr>

                        <td>
                            Battery Health
                        </td>

                        <td>

                            <?php

                            $battery =
                                $prediction['features']['battery_health_percent']
                                ?? null;

                            ?>

                            <?= $battery !== null
                                ? e($battery) . '%'
                                : 'Not recorded'
                            ?>

                        </td>

                    </tr>

                </tbody>

            </table>


        <?php else: ?>

            <div class="danger">

                <h3>
                    Prediction Unavailable
                </h3>

                <p>

                    <?= e(
                        $prediction['error']
                        ?? 'Unable to generate prediction.'
                    ) ?>

                </p>

            </div>

        <?php endif; ?>

    </div>

</section>


<!--
|--------------------------------------------------------------------------
| Maintenance History
|--------------------------------------------------------------------------
-->

<section style="margin-top:24px">

    <div class="panel">

        <h2>
            Maintenance History
        </h2>

        <?php if ($maintenanceHistory->num_rows > 0): ?>

            <table>

                <thead>

                    <tr>

                        <th>
                            Date
                        </th>

                        <th>
                            Issue
                        </th>

                        <th>
                            Repair Cost
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Repaired At
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php while (
                        $m =
                        $maintenanceHistory->fetch_assoc()
                    ): ?>

                        <tr>

                            <td>
                                <?= e(
                                    $m['created_at']
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $m['issue_description']
                                ) ?>
                            </td>

                            <td>

                                KES
                                <?= number_format(
                                    (float)$m['repair_cost'],
                                    2
                                ) ?>

                            </td>

                            <td>
                                <?= e(
                                    $m['status']
                                ) ?>
                            </td>

                            <td>

                                <?= e(
                                    $m['repaired_at']
                                    ?: 'Not repaired'
                                ) ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                </tbody>

            </table>

        <?php else: ?>

            <p class="muted">
                No maintenance records have been recorded
                for this laptop.
            </p>

        <?php endif; ?>

    </div>

</section>


<!--
|--------------------------------------------------------------------------
| Device Usage
|--------------------------------------------------------------------------
-->

<section style="margin-top:24px">

    <div class="panel">

        <h2>
            Device Usage History
        </h2>

        <?php if ($usageHistory->num_rows > 0): ?>

            <table>

                <thead>

                    <tr>

                        <th>
                            Date
                        </th>

                        <th>
                            Active Hours
                        </th>

                        <th>
                            Crashes
                        </th>

                        <th>
                            Battery Health
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php while (
                        $u =
                        $usageHistory->fetch_assoc()
                    ): ?>

                        <tr>

                            <td>
                                <?= e(
                                    $u['usage_date']
                                ) ?>
                            </td>

                            <td>

                                <?= number_format(
                                    (float)$u['active_hours'],
                                    1
                                ) ?>

                                hrs

                            </td>

                            <td>
                                <?= e(
                                    $u['crash_count']
                                ) ?>
                            </td>

                            <td>

                                <?= $u['battery_health_percent'] !== null

                                    ? e(
                                        $u['battery_health_percent']
                                    ) . '%'

                                    : 'Not recorded'
                                ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                </tbody>

            </table>

        <?php else: ?>

            <p class="muted">

                No device usage records have been
                recorded for this laptop.

            </p>

        <?php endif; ?>

    </div>

</section>


<!--
|--------------------------------------------------------------------------
| Back Button
|--------------------------------------------------------------------------
-->

<div
    style="
        margin-top:25px;
        margin-bottom:30px;
    "
>

    <a
        href="maintenance.php"
        style="
            display:inline-block;
            text-decoration:none;
        "
    >

        ← Back to Maintenance

    </a>

</div>


<?php

layout_end();

?>
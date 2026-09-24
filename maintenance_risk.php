<?php

require_once __DIR__ . '/backend/maintenance_risk.php';
require_once __DIR__ . '/db.php';

$selectedLaptop = isset($_GET['laptop_id'])
    ? (int)$_GET['laptop_id']
    : 0;

$result = null;


/*
 * Run prediction when an asset has been selected.
 */
if ($selectedLaptop > 0) {
    $result = maintenance_risk($selectedLaptop);
}


/*
 * Get available laptops for selection.
 */
$laptops = [];

$query = $conn->query("
    SELECT
        id,
        asset_tag,
        brand,
        model,
        status
    FROM laptops
    ORDER BY asset_tag ASC
");

while ($row = $query->fetch_assoc()) {
    $laptops[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Predictive Maintenance Risk</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        .container {
            width: min(1100px, 92%);
            margin: 40px auto;
        }

        .header {
            margin-bottom: 25px;
        }

        .header h1 {
            margin-bottom: 8px;
        }

        .header p {
            color: #6b7280;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,.06);
        }

        .form-row {
            display: flex;
            gap: 12px;
            align-items: end;
        }

        .form-group {
            flex: 1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: white;
        }

        button {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .risk {
            font-size: 30px;
            font-weight: 700;
            margin: 10px 0;
        }

        .high {
            color: #dc2626;
        }

        .medium {
            color: #d97706;
        }

        .low {
            color: #16a34a;
        }

        .probability {
            font-size: 20px;
            color: #4b5563;
        }

        .asset-info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .info-box {
            background: #f8fafc;
            padding: 16px;
            border-radius: 10px;
        }

        .info-label {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .info-value {
            font-weight: 700;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f8fafc;
        }

        .notice {
            padding: 14px;
            border-radius: 8px;
            margin-top: 15px;
            background: #fff7ed;
            color: #9a3412;
        }

        .error {
            padding: 14px;
            border-radius: 8px;
            background: #fef2f2;
            color: #991b1b;
        }

        @media (max-width: 700px) {

            .form-row {
                flex-direction: column;
                align-items: stretch;
            }

            .asset-info {
                grid-template-columns: 1fr;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="header">

        <h1>Predictive Maintenance</h1>

        <p>
            Use the trained Logistic Regression model to assess
            the likelihood that an ICT asset will require
            maintenance within the next 30 days.
        </p>

    </div>


    <!-- Asset selection -->

    <div class="card">

        <form method="GET">

            <div class="form-row">

                <div class="form-group">

                    <label for="laptop_id">
                        Select Asset
                    </label>

                    <select
                        name="laptop_id"
                        id="laptop_id"
                        required
                    >

                        <option value="">
                            -- Select an asset --
                        </option>

                        <?php foreach ($laptops as $laptop): ?>

                            <option
                                value="<?= (int)$laptop['id'] ?>"
                                <?= $selectedLaptop === (int)$laptop['id']
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= htmlspecialchars(
                                    $laptop['asset_tag']
                                ) ?>

                                -
                                <?= htmlspecialchars(
                                    $laptop['brand']
                                ) ?>

                                <?= htmlspecialchars(
                                    $laptop['model']
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <button type="submit">
                    Predict Maintenance Risk
                </button>

            </div>

        </form>

    </div>


    <?php if (is_array($result)): ?>

        <?php if ($result['success']): ?>

            <?php

            $riskClass = strtolower(
                $result['level']
            );

            $percentage =
                $result['score'] * 100;

            ?>

            <!-- Prediction -->

            <div class="card">

                <h2>
                    Prediction Result
                </h2>

                <div class="asset-info">

                    <div class="info-box">

                        <div class="info-label">
                            Asset
                        </div>

                        <div class="info-value">

                            <?= htmlspecialchars(
                                $result['asset_tag']
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Device
                        </div>

                        <div class="info-value">

                            <?= htmlspecialchars(
                                $result['brand']
                            ) ?>

                            <?= htmlspecialchars(
                                $result['model']
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Current Status
                        </div>

                        <div class="info-value">

                            <?= htmlspecialchars(
                                $result['status']
                            ) ?>

                        </div>

                    </div>

                </div>


                <h3>
                    Maintenance Risk
                </h3>

                <div class="risk <?= htmlspecialchars($riskClass) ?>">

                    <?= htmlspecialchars(
                        $result['level']
                    ) ?>

                </div>


                <div class="probability">

                    Model probability:

                    <strong>
                        <?= number_format(
                            $percentage,
                            2
                        ) ?>%
                    </strong>

                </div>


                <?php if ($result['usage_records_30d'] === 0): ?>

                    <div class="notice">

                        No device usage records were recorded
                        for this asset during the last 30 days.
                        Active hours and crash count were therefore
                        recorded as zero for the current prediction.

                    </div>

                <?php endif; ?>

            </div>


            <!-- ML features -->

            <div class="card">

                <h2>
                    Prediction Inputs
                </h2>

                <table>

                    <thead>

                    <tr>
                        <th>Feature</th>
                        <th>Current Value</th>
                    </tr>

                    </thead>

                    <tbody>

                    <tr>
                        <td>Repair Count</td>
                        <td>
                            <?= htmlspecialchars(
                                $result['features']['repair_count']
                            ) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Open Repair Count</td>
                        <td>
                            <?= htmlspecialchars(
                                $result['features']['open_repair_count']
                            ) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Asset Age</td>
                        <td>
                            <?= number_format(
                                $result['features']['asset_age_years'],
                                2
                            ) ?>
                            years
                        </td>
                    </tr>

                    <tr>
                        <td>Warranty Remaining</td>
                        <td>
                            <?= htmlspecialchars(
                                $result['features']['warranty_days_remaining']
                            ) ?>
                            days
                        </td>
                    </tr>

                    <tr>
                        <td>Active Hours — Last 30 Days</td>
                        <td>
                            <?= number_format(
                                $result['features']['active_hours_30d'],
                                2
                            ) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Crashes — Last 30 Days</td>
                        <td>
                            <?= htmlspecialchars(
                                $result['features']['crash_count_30d']
                            ) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Battery Health</td>
                        <td>
                            <?= number_format(
                                $result['features']['battery_health_percent'],
                                0
                            ) ?>%
                        </td>
                    </tr>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="card">

                <div class="error">

                    <?= htmlspecialchars(
                        $result['error'] ?? 'Prediction failed.'
                    ) ?>

                </div>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

</body>
</html>
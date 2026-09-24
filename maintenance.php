<?php

error_reporting(E_ALL);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');


require __DIR__ . '/bootstrap.php';

require_login(['admin', 'super_admin']);

require_once __DIR__ . '/backend/maintenance_risk.php';


/*
|--------------------------------------------------------------------------
| Handle POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    $asset = (int)($_POST['asset_id'] ?? 0);

    $issue = trim($_POST['issue'] ?? '');

    $cost = (float)($_POST['cost'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | Record daily usage
    |--------------------------------------------------------------------------
    */

    if ($action === 'usage') {

        $asset =
            (int)($_POST['asset_id'] ?? 0);

        $date =
            $_POST['usage_date'] ?? '';

        $hours =
            max(
                0,
                min(
                    24,
                    (float)($_POST['active_hours'] ?? 0)
                )
            );

        $crashes =
            max(
                0,
                (int)($_POST['crash_count'] ?? 0)
            );


        $batteryInput =
            $_POST['battery_health_percent'] ?? '';


        $battery =
            $batteryInput === ''
                ? null
                : max(
                    0,
                    min(
                        100,
                        (int)$batteryInput
                    )
                );


        /*
         * Validate date.
         */

        $validDate = false;

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date
            )
        ) {

            $dateObject =
                DateTime::createFromFormat(
                    'Y-m-d',
                    $date
                );

            $validDate =
                $dateObject &&
                $dateObject->format('Y-m-d') === $date;
        }


        if ($asset && $validDate) {

            /*
             * Insert/update usage record.
             *
             * This assumes the unique key on
             * laptop_id + usage_date exists, as
             * your existing code indicates.
             */

            $stmt = $conn->prepare("
                INSERT INTO device_usage_daily
                (
                    laptop_id,
                    usage_date,
                    active_hours,
                    crash_count,
                    battery_health_percent
                )
                VALUES
                (?, ?, ?, ?, ?)

                ON DUPLICATE KEY UPDATE

                    active_hours =
                        VALUES(active_hours),

                    crash_count =
                        VALUES(crash_count),

                    battery_health_percent =
                        VALUES(battery_health_percent)
            ");


            $stmt->bind_param(
                'isdii',
                $asset,
                $date,
                $hours,
                $crashes,
                $battery
            );


            $stmt->execute();

            $stmt->close();


            audit(
                $conn,
                'usage_metrics_recorded',
                'laptop',
                $asset,
                [
                    'usage_date' => $date
                ]
            );


            flash(
                'Usage data recorded. The predictive maintenance inputs have been updated.'
            );
        }


    /*
    |--------------------------------------------------------------------------
    | Run and save an explicit prediction
    |--------------------------------------------------------------------------
    */

    } elseif ($action === 'predict') {

        if ($asset > 0) {

            $prediction =
                maintenance_risk(
                    $asset,
                    true
                );


            if (
                !empty($prediction['success']) &&
                $prediction['prediction_saved']
            ) {

                flash(
                    'Maintenance prediction generated and saved for asset ' .
                    ($prediction['asset_tag'] ?? $asset) .
                    '. Risk: ' .
                    ($prediction['level'] ?? 'Unknown') .
                    ' (' .
                    number_format(
                        ((float)$prediction['score']) * 100,
                        2
                    ) .
                    '%).'
                );

            } else {

                flash(
                    'Unable to save the maintenance prediction: ' .
                    ($prediction['error'] ?? 'Unknown error.')
                );
            }
        }


    /*
    |--------------------------------------------------------------------------
    | Create maintenance record
    |--------------------------------------------------------------------------
    */

    } elseif (
        $asset &&
        $issue !== ''
    ) {

        $user =
            (int)$_SESSION['user_id'];


        $stmt = $conn->prepare("
            INSERT INTO maintenance_records
            (
                laptop_id,
                reported_by,
                issue_description,
                repair_cost,
                status
            )
            VALUES
            (?, ?, ?, ?, 'open')
        ");


        $stmt->bind_param(
            'iisd',
            $asset,
            $user,
            $issue,
            $cost
        );


        $stmt->execute();

        $maintenanceRecordId =
            $conn->insert_id;

        $stmt->close();


        /*
         * Change lifecycle status to Maintenance.
         */

        $stmt = $conn->prepare("
            UPDATE laptops
            SET status = 'Maintenance'
            WHERE id = ?
        ");


        $stmt->bind_param(
            'i',
            $asset
        );


        $stmt->execute();

        $stmt->close();


        audit(
            $conn,
            'maintenance_reported',
            'maintenance_record',
            $maintenanceRecordId,
            [
                'asset_id' => $asset
            ]
        );


        flash(
            'Maintenance record created and asset marked for Maintenance.'
        );
    }


    /*
     * Prevent duplicate form submission.
     */

    header(
        'Location: maintenance.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Retrieve assets for forms
|--------------------------------------------------------------------------
*/

$assets = $conn->query("
    SELECT
        id,
        asset_tag,
        brand,
        model
    FROM laptops
    WHERE status NOT IN ('Retired', 'Disposed')
    ORDER BY asset_tag
");


/*
|--------------------------------------------------------------------------
| Retrieve assets for risk register
|--------------------------------------------------------------------------
|
| We intentionally retrieve the laptop information here and let
| maintenance_risk() obtain the actual ML features from the
| database. This prevents the page from relying on old local
| risk calculations.
|
*/

$risks = $conn->query("
    SELECT
        l.id,
        l.asset_tag,
        l.brand,
        l.model,
        l.status,
        l.warranty_expiry,

        COUNT(m.id) AS repairs,

        COALESCE(
            SUM(
                CASE
                    WHEN m.status IN ('open', 'in_progress')
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS open_repairs

    FROM laptops l

    LEFT JOIN maintenance_records m
        ON m.laptop_id = l.id

    WHERE l.status NOT IN ('Retired', 'Disposed')

    GROUP BY
        l.id,
        l.asset_tag,
        l.brand,
        l.model,
        l.status,
        l.warranty_expiry

    ORDER BY
        l.asset_tag ASC
");


/*
|--------------------------------------------------------------------------
| Page layout
|--------------------------------------------------------------------------
*/

layout_start('Maintenance');

?>


<div class="hero">

    <div>

        <h1>
            Maintenance and Lifecycle
        </h1>

        <p class="muted">

            The Logistic Regression model estimates
            maintenance risk using repair history,
            warranty status, asset age and the latest
            30 days of recorded device usage.

        </p>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| Forms
|--------------------------------------------------------------------------
-->

<section class="split">


    <!--
    |--------------------------------------------------------------------------
    | Report maintenance issue
    |--------------------------------------------------------------------------
    -->

    <div class="panel">

        <h2>
            Report Maintenance Issue
        </h2>


        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >


            <label>

                Asset

                <select
                    name="asset_id"
                    required
                >

                    <option value="">
                        Choose asset
                    </option>


                    <?php while (
                        $a = $assets->fetch_assoc()
                    ): ?>

                        <option
                            value="<?= (int)$a['id'] ?>"
                        >

                            <?= e(
                                $a['asset_tag'] .
                                ' — ' .
                                $a['brand'] .
                                ' ' .
                                $a['model']
                            ) ?>

                        </option>

                    <?php endwhile; ?>

                </select>

            </label>


            <label>

                Issue description

                <textarea
                    name="issue"
                    required
                ></textarea>

            </label>


            <label>

                Estimated repair cost

                <input
                    name="cost"
                    type="number"
                    step="0.01"
                    min="0"
                    value="0"
                >

            </label>


            <button type="submit">

                Create Maintenance Record

            </button>

        </form>

    </div>



    <!--
    |--------------------------------------------------------------------------
    | Record daily usage
    |--------------------------------------------------------------------------
    -->

    <div class="panel">

        <h2>
            Record Daily Usage
        </h2>


        <p class="muted">

            Enter observed daily values until
            a device-management agent supplies
            them automatically.

        </p>


        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >


            <input
                type="hidden"
                name="action"
                value="usage"
            >


            <label>

                Asset

                <select
                    name="asset_id"
                    required
                >

                    <option value="">
                        Choose asset
                    </option>


                    <?php

                    /*
                     * The assets result was consumed by the
                     * first form, so reset its pointer.
                     */

                    $assets->data_seek(0);

                    while (
                        $a = $assets->fetch_assoc()
                    ):

                    ?>

                        <option
                            value="<?= (int)$a['id'] ?>"
                        >

                            <?= e(
                                $a['asset_tag']
                            ) ?>

                        </option>

                    <?php endwhile; ?>

                </select>

            </label>


            <label>

                Date

                <input
                    name="usage_date"
                    type="date"
                    value="<?= date('Y-m-d') ?>"
                    required
                >

            </label>


            <label>

                Active Hours

                <input
                    name="active_hours"
                    type="number"
                    min="0"
                    max="24"
                    step="0.1"
                    required
                >

            </label>


            <label>

                System Crashes

                <input
                    name="crash_count"
                    type="number"
                    min="0"
                    value="0"
                    required
                >

            </label>


            <label>

                Battery Health (%)

                <input
                    name="battery_health_percent"
                    type="number"
                    min="0"
                    max="100"
                >

            </label>


            <button type="submit">

                Save Usage

            </button>

        </form>

    </div>

</section>

<!--
|--------------------------------------------------------------------------
| Manual Maintenance Risk Prediction
|--------------------------------------------------------------------------
-->
<section style="margin-top:24px">

    <div class="panel">

        <h2>Predict Maintenance Risk</h2>

        <p class="muted">
            Select an asset to generate and save a maintenance-risk prediction
            using the Logistic Regression model.
        </p>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="predict"
            >

            <label>
                Select Asset

                <select
                    name="asset_id"
                    required
                >

                    <option value="">
                        Choose an asset
                    </option>

                    <?php
                    $assets->data_seek(0);

                    while ($a = $assets->fetch_assoc()):
                    ?>

                        <option value="<?= (int)$a['id'] ?>">
                            <?= e(
                                $a['asset_tag'] .
                                ' — ' .
                                $a['brand'] .
                                ' ' .
                                $a['model']
                            ) ?>
                        </option>

                    <?php endwhile; ?>

                </select>

            </label>

            <button type="submit">
                Predict Maintenance Risk
            </button>

        </form>

    </div>

</section>

<!--
|--------------------------------------------------------------------------
| Predictive Maintenance Risk Register
|--------------------------------------------------------------------------
-->

<section style="margin-top:24px">

    <h2>
        Asset Risk Register
    </h2>


    <p class="muted">

        Risk values are generated by the Logistic Regression
        model through the Flask predictive-maintenance service.

    </p>


    <table>

        <thead>

            <tr>

                <th>
                    Asset
                </th>

                <th>
                    Status
                </th>

                <th>
                    Warranty
                </th>

                <th>
                    Repair Records
                </th>

                <th>
                    Open Repairs
                </th>

                <th>
                    30-Day Usage
                </th>

                <th>
                    Predicted Maintenance Risk
                </th>

                <th>
                    Action
                </th>

            </tr>

        </thead>


        <tbody>


        <?php while (
            $r = $risks->fetch_assoc()
        ): ?>


            <?php

            /*
             * Generate a prediction using the actual
             * database information.
             *
             * false = do not save automatically.
             */

            $prediction =
                maintenance_risk(
                    (int)$r['id'],
                    false
                );


            $level =
                $prediction['level'] ?? 'Unknown';


            $score =
                isset($prediction['score'])
                    ? (float)$prediction['score']
                    : 0;


            $version =
                $prediction['version']
                ?? 'logistic-regression-v1';


            $usageRecords =
                (int)(
                    $prediction['usage_records_30d']
                    ?? 0
                );


            $riskClass = '';

            if ($level === 'High') {

                $riskClass = 'danger';

            } elseif ($level === 'Medium') {

                $riskClass = 'warning';
            }

            ?>


            <tr>


                <!-- Asset -->

                <td>

                    <b>
                        <?= e(
                            $r['asset_tag']
                        ) ?>
                    </b>

                    <br>

                    <?= e(
                        $r['brand'] .
                        ' ' .
                        $r['model']
                    ) ?>

                </td>


                <!-- Status -->

                <td>

                    <?= e(
                        $r['status']
                    ) ?>

                </td>


                <!-- Warranty -->

                <td>

                    <?= e(
                        $r['warranty_expiry']
                        ?: 'Not recorded'
                    ) ?>

                </td>


                <!-- Repairs -->

                <td>

                    <?= (int)$r['repairs'] ?>

                </td>


                <!-- Open repairs -->

                <td>

                    <?= (int)$r['open_repairs'] ?>

                </td>


                <!-- 30-day usage -->

                <td>

                    <?php if (
                        $prediction['success'] ?? false
                    ): ?>

                        <?= number_format(
                            (float)$prediction['features']['active_hours_30d'],
                            1
                        ) ?>

                        hrs

                        <br>

                        <?= (int)$prediction['features']['crash_count_30d'] ?>

                        crashes


                        <?php if (
                            $usageRecords === 0
                        ): ?>

                            <br>

                            <small class="muted">

                                No records in last 30 days

                            </small>

                        <?php endif; ?>


                    <?php else: ?>

                        <span class="muted">
                            Unavailable
                        </span>

                    <?php endif; ?>

                </td>


                <!-- Prediction -->

                <td class="<?= e($riskClass) ?>">


                    <?php if (
                        $prediction['success'] ?? false
                    ): ?>


                        <b>

                            <?= e($level) ?>

                        </b>


                        —

                        <?= number_format(
                            $score * 100,
                            2
                        ) ?>%


                        <br>


                        <small>

                            <?= e(
                                $version
                            ) ?>

                        </small>


                    <?php else: ?>


                        <b>
                            Unavailable
                        </b>


                        <br>


                        <small>

                            <?= e(
                                $prediction['error']
                                ?? 'Prediction unavailable.'
                            ) ?>

                        </small>


                    <?php endif; ?>


                </td>


                <!-- Save prediction -->

                <td>

                    <?php if (
                        $prediction['success'] ?? false
                    ): ?>


                        <form
                            method="post"
                            style="margin:0"
                        >

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= e(csrf()) ?>"
                            >


                            <input
                                type="hidden"
                                name="action"
                                value="predict"
                            >


                            <input
                                type="hidden"
                                name="asset_id"
                                value="<?= (int)$r['id'] ?>"
                            >


                            <button
                                type="submit"
                            >

                                Save Prediction

                            </button>

                        </form>


                    <?php endif; ?>

                </td>

            </tr>


        <?php endwhile; ?>


        </tbody>

    </table>

</section>


<?php

layout_end();

?>
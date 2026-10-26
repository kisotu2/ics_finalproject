<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
/**
 * Smart Asset Management System
 * Maintenance Risk Details Page
 *
 * Displays:
 * - Laptop information
 * - Predictive maintenance risk
 * - Model feature values
 * - Maintenance history
 * - Device usage history
 * - Latest saved prediction
 */

require_once __DIR__ . '/bootstrap.php';

require_login(['admin', 'super_admin']);

require_once __DIR__ . '/backend/maintenance_risk.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('maintenance_page_escape')) {
    function maintenance_page_escape(mixed $value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

if (!function_exists('maintenance_page_query')) {
    /**
     * Execute a prepared query and return all rows.
     */
    function maintenance_page_query(
        mysqli $conn,
        string $sql,
        string $types = '',
        array $params = []
    ): array {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare database query: ' . $conn->error
            );
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                'Database query failed: ' . $error
            );
        }

        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        if ($result) {
            $result->free();
        }

        $stmt->close();

        return $rows;
    }
}

/*
|--------------------------------------------------------------------------
| Validate database connection
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('Maintenance risk page: database connection unavailable.');

    http_response_code(500);
    exit('The database service is currently unavailable.');
}

/*
|--------------------------------------------------------------------------
| Get Laptop ID
|--------------------------------------------------------------------------
*/

$laptopId = filter_var(
    $_GET['laptop_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($laptopId === false || $laptopId === null) {
    flash('Please select a valid laptop.');
    header('Location: maintenance.php');
    exit;
}

$laptopId = (int)$laptopId;

/*
|--------------------------------------------------------------------------
| Load Laptop Details
|--------------------------------------------------------------------------
*/

try {
    $laptopRows = maintenance_page_query(
        $conn,
        "SELECT
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
         LIMIT 1",
        'i',
        [$laptopId]
    );

    if (!$laptopRows) {
        flash('Laptop not found.');
        header('Location: maintenance.php');
        exit;
    }

    $laptop = $laptopRows[0];
} catch (Throwable $e) {
    error_log('Maintenance risk page - laptop lookup: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to load laptop details. Please try again later.');
}

/*
|--------------------------------------------------------------------------
| Generate Current Prediction
|--------------------------------------------------------------------------
|
| Passing true saves the successful prediction to
| maintenance_risk_predictions.
|
*/

try {
    $prediction = maintenance_risk($laptopId, true);

    if (!is_array($prediction)) {
        $prediction = [
            'success' => false,
            'error' => 'The prediction service returned an unexpected result.',
        ];
    }
} catch (Throwable $e) {
    error_log('Maintenance risk page - prediction: ' . $e->getMessage());

    $prediction = [
        'success' => false,
        'error' => 'Unable to generate a maintenance prediction.',
    ];
}

/*
|--------------------------------------------------------------------------
| Validate Prediction Values for Display
|--------------------------------------------------------------------------
*/

$predictionAvailable = ($prediction['success'] ?? false) === true;

$level = 'Unknown';
$score = null;
$scorePercentage = null;
$modelVersion = 'Not available';
$features = [];
$predictionSaved = false;

if ($predictionAvailable) {
    $rawScore = $prediction['score'] ?? null;
    $rawLevel = $prediction['level'] ?? null;

    if (
        is_numeric($rawScore) &&
        is_finite((float)$rawScore) &&
        (float)$rawScore >= 0 &&
        (float)$rawScore <= 1 &&
        is_string($rawLevel) &&
        in_array($rawLevel, ['Low', 'Medium', 'High'], true)
    ) {
        $score = (float)$rawScore;
        $scorePercentage = $score * 100;
        $level = $rawLevel;

        $modelVersion = (string)(
            $prediction['version'] ?? 'logistic-regression-v1'
        );

        $features = is_array($prediction['features'] ?? null)
            ? $prediction['features']
            : [];

        $predictionSaved = ($prediction['prediction_saved'] ?? false) === true;
    } else {
        $predictionAvailable = false;
        $prediction['error'] =
            'The prediction result contains invalid score or risk-level values.';
    }
}

/*
|--------------------------------------------------------------------------
| Risk Badge Class
|--------------------------------------------------------------------------
*/

$riskClass = 'risk-unknown';

if ($level === 'Low') {
    $riskClass = 'risk-low';
} elseif ($level === 'Medium') {
    $riskClass = 'risk-medium';
} elseif ($level === 'High') {
    $riskClass = 'risk-high';
}

/*
|--------------------------------------------------------------------------
| Load Maintenance History
|--------------------------------------------------------------------------
*/

try {
    $maintenanceHistory = maintenance_page_query(
        $conn,
        "SELECT
            id,
            issue_description,
            repair_cost,
            status,
            repaired_at,
            created_at
         FROM maintenance_records
         WHERE laptop_id = ?
         ORDER BY created_at DESC
         LIMIT 100",
        'i',
        [$laptopId]
    );
} catch (Throwable $e) {
    error_log('Maintenance risk page - history: ' . $e->getMessage());

    $maintenanceHistory = [];
    $maintenanceHistoryError = true;
}

/*
|--------------------------------------------------------------------------
| Load Device Usage History
|--------------------------------------------------------------------------
*/

try {
    $usageHistory = maintenance_page_query(
        $conn,
        "SELECT
            usage_date,
            active_hours,
            crash_count,
            battery_health_percent
         FROM device_usage_daily
         WHERE laptop_id = ?
         ORDER BY usage_date DESC, id DESC
         LIMIT 30",
        'i',
        [$laptopId]
    );
} catch (Throwable $e) {
    error_log('Maintenance risk page - usage: ' . $e->getMessage());

    $usageHistory = [];
    $usageHistoryError = true;
}

/*
|--------------------------------------------------------------------------
| Load Latest Saved Prediction
|--------------------------------------------------------------------------
|
| This is queried separately so the page can show the latest database
| record even if the current prediction request failed.
|
*/

try {
    $savedPredictionRows = maintenance_page_query(
        $conn,
        "SELECT
            risk_score,
            risk_level,
            model_version,
            factors,
            predicted_at
         FROM maintenance_risk_predictions
         WHERE laptop_id = ?
         ORDER BY predicted_at DESC
         LIMIT 1",
        'i',
        [$laptopId]
    );

    $latestPrediction = $savedPredictionRows[0] ?? null;
} catch (Throwable $e) {
    error_log('Maintenance risk page - saved prediction: ' . $e->getMessage());

    $latestPrediction = null;
    $savedPredictionError = true;
}

/*
|--------------------------------------------------------------------------
| Decode Saved Feature Values
|--------------------------------------------------------------------------
*/

$savedFactors = [];

if ($latestPrediction && !empty($latestPrediction['factors'])) {
    $decodedFactors = json_decode(
        (string)$latestPrediction['factors'],
        true
    );

    if (is_array($decodedFactors)) {
        $savedFactors = $decodedFactors;
    }
}

/*
|--------------------------------------------------------------------------
| Format Helpers
|--------------------------------------------------------------------------
*/

$formatDate = static function ($value): string {
    if (empty($value)) {
        return 'Not recorded';
    }

    return (string)$value;
};

$formatNumber = static function ($value, int $decimals = 2): string {
    if (!is_numeric($value)) {
        return 'Not recorded';
    }

    return number_format((float)$value, $decimals);
};

/*
|--------------------------------------------------------------------------
| Render Page
|--------------------------------------------------------------------------
*/

layout_start('Maintenance Risk');

?>

<style>
    .maintenance-page {
        max-width: 1200px;
        margin: 0 auto;
        padding-bottom: 30px;
    }

    .maintenance-hero {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 24px;
    }

    .maintenance-hero h1 {
        margin-bottom: 8px;
    }

    .maintenance-muted {
        opacity: 0.75;
    }

    .maintenance-panel {
        margin-top: 22px;
        padding: 22px;
        border-radius: 12px;
        overflow-x: auto;
    }

    .maintenance-panel h2 {
        margin-top: 0;
        margin-bottom: 18px;
    }

    .maintenance-table {
        width: 100%;
        border-collapse: collapse;
    }

    .maintenance-table th,
    .maintenance-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(128, 128, 128, 0.22);
        vertical-align: top;
    }

    .maintenance-table th {
        font-weight: 600;
    }

    .maintenance-table tr:last-child td {
        border-bottom: none;
    }

    .maintenance-button {
        display: inline-block;
        padding: 10px 15px;
        border-radius: 8px;
        text-decoration: none;
        border: 1px solid rgba(128, 128, 128, 0.4);
    }

    .maintenance-button:hover {
        opacity: 0.82;
    }

    .maintenance-risk-card {
        padding: 22px;
        border-radius: 12px;
        margin-bottom: 20px;
        border: 1px solid rgba(128, 128, 128, 0.25);
    }

    .maintenance-risk-card h3 {
        margin-top: 0;
    }

    .risk-low {
        border-left: 5px solid #16a34a;
    }

    .risk-medium {
        border-left: 5px solid #d97706;
    }

    .risk-high {
        border-left: 5px solid #dc2626;
    }

    .risk-unknown {
        border-left: 5px solid #6b7280;
    }

    .maintenance-risk-score {
        font-size: 28px;
        font-weight: 700;
        margin: 12px 0;
    }

    .maintenance-risk-label {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 6px;
        background: rgba(128, 128, 128, 0.15);
        font-weight: 600;
    }

    .maintenance-notice {
        padding: 15px;
        border-radius: 9px;
        margin: 14px 0;
        border: 1px solid rgba(128, 128, 128, 0.3);
    }

    .maintenance-feature-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        margin: 18px 0;
    }

    .maintenance-feature {
        padding: 14px;
        border-radius: 9px;
        border: 1px solid rgba(128, 128, 128, 0.22);
    }

    .maintenance-feature-label {
        font-size: 13px;
        opacity: 0.75;
        margin-bottom: 7px;
    }

    .maintenance-feature-value {
        font-size: 20px;
        font-weight: 650;
        overflow-wrap: anywhere;
    }

    .maintenance-section-note {
        margin-top: 12px;
        font-size: 13px;
        opacity: 0.75;
    }

    @media (max-width: 650px) {
        .maintenance-panel {
            padding: 15px;
        }

        .maintenance-table th,
        .maintenance-table td {
            padding: 9px;
        }

        .maintenance-risk-score {
            font-size: 23px;
        }
    }
</style>

<div class="maintenance-page">

    <header class="maintenance-hero">
        <div>
            <h1>Maintenance Risk Details</h1>

            <p class="maintenance-muted">
                Predictive maintenance analysis for
                <strong>
                    <?= maintenance_page_escape($laptop['asset_tag'] ?? '') ?>
                </strong>
            </p>
        </div>

        <a class="maintenance-button" href="maintenance.php">
            &larr; Back to Maintenance
        </a>
    </header>

    <!-- Laptop details -->

    <section class="panel maintenance-panel">
        <h2>Laptop Details</h2>

        <table class="maintenance-table">
            <tbody>
                <tr>
                    <th>Asset Tag</th>
                    <td><?= maintenance_page_escape($laptop['asset_tag']) ?></td>
                </tr>

                <tr>
                    <th>Serial Number</th>
                    <td>
                        <?= maintenance_page_escape(
                            $laptop['serial_number'] ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Brand</th>
                    <td><?= maintenance_page_escape($laptop['brand']) ?></td>
                </tr>

                <tr>
                    <th>Model</th>
                    <td><?= maintenance_page_escape($laptop['model']) ?></td>
                </tr>

                <tr>
                    <th>Department</th>
                    <td>
                        <?= maintenance_page_escape(
                            $laptop['department'] ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Processor Tier</th>
                    <td>
                        <?= maintenance_page_escape(
                            $laptop['processor_tier'] ?: 'Not recorded'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>RAM</th>
                    <td>
                        <?= $laptop['ram_gb'] !== null
                            ? maintenance_page_escape($laptop['ram_gb']) . ' GB'
                            : 'Not recorded' ?>
                    </td>
                </tr>

                <tr>
                    <th>Storage</th>
                    <td>
                        <?= $laptop['storage_gb'] !== null
                            ? maintenance_page_escape($laptop['storage_gb']) . ' GB'
                            : 'Not recorded' ?>
                    </td>
                </tr>

                <tr>
                    <th>Assigned To</th>
                    <td>
                        <?= maintenance_page_escape(
                            $laptop['assigned_to'] ?: 'Not assigned'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Status</th>
                    <td>
                        <?= maintenance_page_escape($laptop['status']) ?>
                    </td>
                </tr>

                <tr>
                    <th>Purchase Date</th>
                    <td>
                        <?= maintenance_page_escape(
                            $formatDate($laptop['purchase_date'])
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Warranty Expiry</th>
                    <td>
                        <?= maintenance_page_escape(
                            $formatDate($laptop['warranty_expiry'])
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <th>Registered At</th>
                    <td>
                        <?= maintenance_page_escape(
                            $formatDate($laptop['created_at'])
                        ) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <!-- Current prediction -->

    <section class="panel maintenance-panel">
        <h2>Predictive Maintenance Result</h2>

        <?php if ($predictionAvailable): ?>

            <div class="maintenance-risk-card <?= maintenance_page_escape($riskClass) ?>">
                <h3>
                    <span class="maintenance-risk-label">
                        <?= maintenance_page_escape($level) ?> Risk
                    </span>
                </h3>

                <div class="maintenance-risk-score">
                    <?= number_format((float)$scorePercentage, 2) ?>%
                </div>

                <p class="maintenance-muted">
                    Estimated model probability of maintenance being required
                    within the model's target period.
                </p>

                <p>
                    <strong>Model version:</strong>
                    <?= maintenance_page_escape($modelVersion) ?>
                </p>

                <p>
                    <strong>Prediction saved:</strong>
                    <?= $predictionSaved ? 'Yes' : 'No' ?>
                </p>
            </div>

            <h3>Prediction Factors</h3>

            <div class="maintenance-feature-grid">
                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">Repair Count</div>
                    <div class="maintenance-feature-value">
                        <?= maintenance_page_escape(
                            $features['repair_count'] ?? 'Not available'
                        ) ?>
                    </div>
                </div>

                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">Open Repairs</div>
                    <div class="maintenance-feature-value">
                        <?= maintenance_page_escape(
                            $features['open_repair_count'] ?? 'Not available'
                        ) ?>
                    </div>
                </div>

                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">Asset Age</div>
                    <div class="maintenance-feature-value">
                        <?= maintenance_page_escape(
                            $formatNumber($features['asset_age_years'] ?? null)
                        ) ?>
                        years
                    </div>
                </div>

                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">Warranty Remaining</div>
                    <div class="maintenance-feature-value">
                        <?= maintenance_page_escape(
                            $features['warranty_days_remaining'] ?? 'Not available'
                        ) ?>
                        days
                    </div>
                </div>

                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">
                        Active Hours — Last 30 Days
                    </div>
                    <div class="maintenance-feature-value">
                        <?= maintenance_page_escape(
                            $formatNumber($features['active_hours_30d'] ?? null, 1)
                        ) ?>
                        hrs
                    </div>
                </div>

                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">
                        Crashes — Last 30 Days
                    </div>
                    <div class="maintenance-feature-value">
                        <?= maintenance_page_escape(
                            $features['crash_count_30d'] ?? 'Not available'
                        ) ?>
                    </div>
                </div>

                <div class="maintenance-feature">
                    <div class="maintenance-feature-label">Battery Health</div>
                    <div class="maintenance-feature-value">
                        <?php if (isset($features['battery_health_percent'])): ?>
                            <?= maintenance_page_escape(
                                $formatNumber($features['battery_health_percent'], 1)
                            ) ?>%
                        <?php else: ?>
                            Not available
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <p class="maintenance-section-note">
                The score is a model estimate, not a guarantee of future failure.
                Risk categories depend on the thresholds configured by the model API.
            </p>

        <?php else: ?>

            <div class="maintenance-notice">
                <h3>Prediction Unavailable</h3>

                <p>
                    <?= maintenance_page_escape(
                        $prediction['error']
                        ?? 'Unable to generate a maintenance prediction.'
                    ) ?>
                </p>

                <p class="maintenance-muted">
                    Check that the Flask service is running and that the laptop
                    has the required maintenance and usage data.
                </p>
            </div>

        <?php endif; ?>
    </section>

    <!-- Latest saved prediction -->

    <section class="panel maintenance-panel">
        <h2>Latest Saved Prediction</h2>

        <?php if (!empty($savedPredictionError)): ?>

            <p class="maintenance-muted">
                The saved prediction history could not be retrieved.
            </p>

        <?php elseif ($latestPrediction): ?>

            <?php
            $savedScore = $latestPrediction['risk_score'] ?? null;
            $savedScoreValid = is_numeric($savedScore)
                && is_finite((float)$savedScore)
                && (float)$savedScore >= 0
                && (float)$savedScore <= 1;
            ?>

            <table class="maintenance-table">
                <tbody>
                    <tr>
                        <th>Risk Level</th>
                        <td>
                            <?= maintenance_page_escape(
                                $latestPrediction['risk_level'] ?? 'Unknown'
                            ) ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Risk Score</th>
                        <td>
                            <?= $savedScoreValid
                                ? number_format((float)$savedScore * 100, 2) . '%'
                                : 'Invalid or unavailable' ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Model Version</th>
                        <td>
                            <?= maintenance_page_escape(
                                $latestPrediction['model_version'] ?? 'Not recorded'
                            ) ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Predicted At</th>
                        <td>
                            <?= maintenance_page_escape(
                                $latestPrediction['predicted_at'] ?? 'Not recorded'
                            ) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($savedFactors): ?>
                <details style="margin-top: 16px;">
                    <summary>View saved prediction features</summary>

                    <pre style="white-space: pre-wrap; overflow-wrap: anywhere;"><?= maintenance_page_escape(
                        json_encode(
                            $savedFactors,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                        )
                    ) ?></pre>
                </details>
            <?php endif; ?>

        <?php else: ?>

            <p class="maintenance-muted">
                No saved prediction is available for this laptop yet.
            </p>

        <?php endif; ?>
    </section>

    <!-- Maintenance history -->

    <section class="panel maintenance-panel">
        <h2>Maintenance History</h2>

        <?php if (!empty($maintenanceHistoryError)): ?>

            <p class="maintenance-muted">
                Maintenance history could not be loaded.
            </p>

        <?php elseif ($maintenanceHistory): ?>

            <table class="maintenance-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Issue</th>
                        <th>Repair Cost</th>
                        <th>Status</th>
                        <th>Repaired At</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($maintenanceHistory as $record): ?>
                        <tr>
                            <td>
                                <?= maintenance_page_escape(
                                    $record['created_at'] ?? 'Not recorded'
                                ) ?>
                            </td>

                            <td>
                                <?= nl2br(maintenance_page_escape(
                                    $record['issue_description'] ?? 'Not recorded'
                                )) ?>
                            </td>

                            <td>
                                KES
                                <?= number_format(
                                    (float)($record['repair_cost'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= maintenance_page_escape(
                                    $record['status'] ?? 'Unknown'
                                ) ?>
                            </td>

                            <td>
                                <?= maintenance_page_escape(
                                    $formatDate($record['repaired_at'] ?? null)
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>

            <p class="maintenance-muted">
                No maintenance records have been recorded for this laptop.
            </p>

        <?php endif; ?>
    </section>

    <!-- Device usage history -->

    <section class="panel maintenance-panel">
        <h2>Device Usage History</h2>

        <?php if (!empty($usageHistoryError)): ?>

            <p class="maintenance-muted">
                Device usage history could not be loaded.
            </p>

        <?php elseif ($usageHistory): ?>

            <table class="maintenance-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Active Hours</th>
                        <th>Crashes</th>
                        <th>Battery Health</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($usageHistory as $usage): ?>
                        <tr>
                            <td>
                                <?= maintenance_page_escape(
                                    $usage['usage_date'] ?? 'Not recorded'
                                ) ?>
                            </td>

                            <td>
                                <?= maintenance_page_escape(
                                    $formatNumber($usage['active_hours'] ?? null, 1)
                                ) ?>
                                hrs
                            </td>

                            <td>
                                <?= maintenance_page_escape(
                                    $usage['crash_count'] ?? 'Not recorded'
                                ) ?>
                            </td>

                            <td>
                                <?= $usage['battery_health_percent'] !== null
                                    ? maintenance_page_escape(
                                        $formatNumber(
                                            $usage['battery_health_percent'],
                                            1
                                        )
                                    ) . '%'
                                    : 'Not recorded' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>

            <p class="maintenance-muted">
                No device usage records have been recorded for this laptop.
            </p>

        <?php endif; ?>
    </section>

    <div style="margin-top: 24px;">
        <a class="maintenance-button" href="maintenance.php">
            &larr; Back to Maintenance
        </a>
    </div>

</div>

<?php
layout_end();
?>

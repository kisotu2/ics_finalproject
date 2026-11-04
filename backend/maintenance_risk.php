<?php

/**
 * Predictive Maintenance Risk
 *
 * The PHP application retrieves current asset information from MySQL,
 * prepares the seven features required by the trained Logistic
 * Regression model, and sends them to the Flask ML API.
 *
 * The Flask service performs predictions when available. PHP uses the
 * same model artefact locally if the API cannot be reached.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/maintenance_model.php';

function maintenance_risk_notify_admins(
    mysqli $conn,
    int $laptopId,
    string $assetTag,
    float $score,
    string $episodeStart
): int {
    $stmt = $conn->prepare(
        "SELECT u.id
         FROM laptops l
         JOIN users u ON u.id = l.responsible_admin_id
         WHERE l.id = ?
           AND u.role IN ('admin', 'super_admin')
           AND u.status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param('i', $laptopId);
    $stmt->execute();
    $recipients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($recipients === []) {
        $admins = $conn->query(
            "SELECT id
             FROM users
             WHERE role = 'super_admin'
               AND status = 'active'"
        );
        $recipients = $admins->fetch_all(MYSQLI_ASSOC);
    }

    if ($recipients === []) {
        throw new RuntimeException('No active administrator is available for notifications.');
    }

    $title = 'High maintenance risk detected';
    $message = sprintf(
        '%s has a high predicted maintenance risk (%.1f%%). Review the maintenance risk register.',
        $assetTag,
        $score * 100
    );
    $notification = $conn->prepare(
        'INSERT INTO user_notifications (user_id, issue_id, title, message)
         VALUES (?, NULL, ?, ?)'
    );
    $existingNotification = $conn->prepare(
        'SELECT id
         FROM user_notifications
         WHERE user_id = ?
           AND title = ?
           AND LEFT(message, CHAR_LENGTH(?)) = ?
           AND created_at >= ?
         LIMIT 1'
    );
    $messagePrefix = $assetTag . ' has a high predicted maintenance risk (';
    $sentCount = 0;

    foreach ($recipients as $recipient) {
        $userId = (int) $recipient['id'];
        $existingNotification->bind_param(
            'issss',
            $userId,
            $title,
            $messagePrefix,
            $messagePrefix,
            $episodeStart
        );
        $existingNotification->execute();

        if ($existingNotification->get_result()->fetch_assoc()) {
            continue;
        }

        $notification->bind_param('iss', $userId, $title, $message);
        $notification->execute();
        $sentCount++;
    }

    $existingNotification->close();
    $notification->close();

    return $sentCount;
}


/**
 * Calculate predictive maintenance risk.
 *
 * Supports both:
 *
 *     maintenance_risk(10)
 *
 * and the existing application's:
 *
 *     maintenance_risk($asset)
 *
 * where $asset contains either [id] or [laptop_id].
 *
 * @param int|array $asset
 * @param bool $savePrediction
 * @return array
 */
function maintenance_risk(
    int|array $asset,
    bool $savePrediction = false
): array {

    global $conn;


    /*
     * ---------------------------------------------------------
     * 1. Resolve laptop ID
     * ---------------------------------------------------------
     */

    if (is_array($asset)) {

        $laptopId = (int)(
            $asset['laptop_id']
            ?? $asset['id']
            ?? 0
        );

    } else {

        $laptopId = (int)$asset;
    }


    if ($laptopId <= 0) {

        return [
            'success' => false,
            'error' => 'A valid laptop ID was not provided.',
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => []
        ];
    }


    /*
     * ---------------------------------------------------------
     * 2. Retrieve laptop information
     * ---------------------------------------------------------
     */

    try {

        $stmt = $conn->prepare("
            SELECT
                id,
                asset_tag,
                brand,
                model,
                status,
                purchase_date,
                warranty_expiry
            FROM laptops
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $laptopId);

        $stmt->execute();

        $result = $stmt->get_result();

        $laptop = $result->fetch_assoc();

        $stmt->close();

    } catch (Throwable $e) {

        return [
            'success' => false,
            'error' => 'Unable to retrieve laptop information.',
            'details' => $e->getMessage(),
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => []
        ];
    }


    if (!$laptop) {

        return [
            'success' => false,
            'error' => 'Laptop not found.',
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => []
        ];
    }


    /*
     * ---------------------------------------------------------
     * 3. Calculate asset age
     * ---------------------------------------------------------
     */

    $assetAgeYears = 0;

    if (!empty($laptop['purchase_date'])) {

        try {

            $purchaseDate = new DateTime(
                $laptop['purchase_date']
            );

            $today = new DateTime();

            $days = $purchaseDate->diff($today)->days;

            $assetAgeYears = $days / 365.25;

        } catch (Throwable $e) {

            $assetAgeYears = 0;
        }
    }


    /*
     * ---------------------------------------------------------
     * 4. Calculate warranty days remaining
     * ---------------------------------------------------------
     */

    $warrantyDaysRemaining = 0;

    if (!empty($laptop['warranty_expiry'])) {

        try {

            $today = new DateTime();

            $warrantyExpiry = new DateTime(
                $laptop['warranty_expiry']
            );

            if ($warrantyExpiry > $today) {

                $warrantyDaysRemaining =
                    $today->diff($warrantyExpiry)->days;
            }

        } catch (Throwable $e) {

            $warrantyDaysRemaining = 0;
        }
    }


    /*
     * ---------------------------------------------------------
     * 5. Maintenance history
     * ---------------------------------------------------------
     */

    $repairCount = 0;
    $openRepairCount = 0;

    try {

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS repair_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status IN ('open', 'in_progress')
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS open_repair_count

            FROM maintenance_records

            WHERE laptop_id = ?
        ");

        $stmt->bind_param("i", $laptopId);

        $stmt->execute();

        $result = $stmt->get_result();

        $maintenance = $result->fetch_assoc();

        $stmt->close();

        $repairCount =
            (int)($maintenance['repair_count'] ?? 0);

        $openRepairCount =
            (int)($maintenance['open_repair_count'] ?? 0);

    } catch (Throwable $e) {

        return [
            'success' => false,
            'error' => 'Unable to retrieve maintenance history.',
            'details' => $e->getMessage(),
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => []
        ];
    }


    /*
     * ---------------------------------------------------------
     * 6. Usage data for the last 30 days
     * ---------------------------------------------------------
     */

    $usageRecords30d = 0;
    $activeHours30d = 0;
    $crashCount30d = 0;

    try {

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS usage_records_30d,

                COALESCE(
                    SUM(active_hours),
                    0
                ) AS active_hours_30d,

                COALESCE(
                    SUM(crash_count),
                    0
                ) AS crash_count_30d

            FROM device_usage_daily

            WHERE laptop_id = ?

              AND usage_date >=
                  DATE_SUB(CURDATE(), INTERVAL 30 DAY)

              AND usage_date <= CURDATE()
        ");

        $stmt->bind_param("i", $laptopId);

        $stmt->execute();

        $result = $stmt->get_result();

        $usage = $result->fetch_assoc();

        $stmt->close();

        $usageRecords30d =
            (int)($usage['usage_records_30d'] ?? 0);

        $activeHours30d =
            (float)($usage['active_hours_30d'] ?? 0);

        $crashCount30d =
            (int)($usage['crash_count_30d'] ?? 0);

    } catch (Throwable $e) {

        return [
            'success' => false,
            'error' => 'Unable to retrieve device usage information.',
            'details' => $e->getMessage(),
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => []
        ];
    }


    /*
     * ---------------------------------------------------------
     * 7. Latest battery health
     * ---------------------------------------------------------
     */

    $batteryHealth = 100;

    try {

        $stmt = $conn->prepare("
            SELECT
                battery_health_percent

            FROM device_usage_daily

            WHERE laptop_id = ?

              AND battery_health_percent IS NOT NULL

            ORDER BY
                usage_date DESC,
                id DESC

            LIMIT 1
        ");

        $stmt->bind_param("i", $laptopId);

        $stmt->execute();

        $result = $stmt->get_result();

        $battery = $result->fetch_assoc();

        $stmt->close();

        if (
            $battery &&
            $battery['battery_health_percent'] !== null
        ) {

            $batteryHealth =
                (float)$battery['battery_health_percent'];
        }

    } catch (Throwable $e) {

        $batteryHealth = 100;
    }


    /*
     * ---------------------------------------------------------
     * 8. Build exactly the seven model features
     * ---------------------------------------------------------
     */

    $features = [

        'repair_count' =>
            $repairCount,

        'open_repair_count' =>
            $openRepairCount,

        'asset_age_years' =>
            round($assetAgeYears, 4),

        'warranty_days_remaining' =>
            $warrantyDaysRemaining,

        'active_hours_30d' =>
            round($activeHours30d, 2),

        'crash_count_30d' =>
            $crashCount30d,

        'battery_health_percent' =>
            $batteryHealth
    ];


    /*
     * ---------------------------------------------------------
     * 9. Call Flask ML API
     * ---------------------------------------------------------
     */

    $url = 'http://127.0.0.1:5000/predict';

    $payload = json_encode(
        $features,
        JSON_UNESCAPED_SLASHES
    );


    if ($payload === false) {

        return [
            'success' => false,
            'error' => 'Unable to encode prediction data.',
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => $features
        ];
    }


    $ch = curl_init($url);

    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $payload,

        CURLOPT_HTTPHEADER => [

            'Content-Type: application/json',

            'Accept: application/json'
        ],

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 1,

        CURLOPT_TIMEOUT => 3
    ]);

    $predictionSource = 'flask_api';
    $response = curl_exec($ch);

    if ($response === false) {

        $serviceError = curl_error($ch);

        curl_close($ch);

        error_log(
            'Predictive maintenance API unavailable; using local model: ' .
            $serviceError
        );

        try {
            $prediction = maintenance_risk_local_prediction($features);
            $predictionSource = 'local_model_fallback';
            $httpCode = 200;
        } catch (Throwable $e) {
            error_log('Local maintenance model failed: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'The prediction API and local model are unavailable.',
                'details' => $e->getMessage(),
                'score' => 0,
                'level' => 'Unknown',
                'version' => 'logistic-regression-v1',
                'features' => $features,
                'usage_records_30d' => $usageRecords30d
            ];
        }
    } else {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $prediction = json_decode($response, true);

        $validResponse =
            $httpCode === 200 &&
            is_array($prediction) &&
            is_numeric($prediction['probability'] ?? null) &&
            is_finite((float) $prediction['probability']) &&
            (float) $prediction['probability'] >= 0 &&
            (float) $prediction['probability'] <= 1 &&
            in_array($prediction['risk_level'] ?? null, ['Low', 'Medium', 'High'], true) &&
            in_array($prediction['prediction'] ?? null, [0, 1, '0', '1'], true);

        if (!$validResponse && $httpCode >= 500) {
            error_log(
                'Predictive maintenance API returned HTTP ' .
                $httpCode .
                '; using local model.'
            );

            try {
                $prediction = maintenance_risk_local_prediction($features);
                $predictionSource = 'local_model_fallback';
                $httpCode = 200;
            } catch (Throwable $e) {
                error_log('Local maintenance model failed: ' . $e->getMessage());

                return [
                    'success' => false,
                    'error' => 'The prediction API and local model are unavailable.',
                    'details' => $e->getMessage(),
                    'score' => 0,
                    'level' => 'Unknown',
                    'version' => 'logistic-regression-v1',
                    'features' => $features,
                    'usage_records_30d' => $usageRecords30d
                ];
            }
        } elseif (!$validResponse) {
            return [
                'success' => false,
                'error' => 'The predictive maintenance service returned an invalid response.',
                'http_code' => $httpCode,
                'response' => $response,
                'score' => 0,
                'level' => 'Unknown',
                'version' => 'logistic-regression-v1',
                'features' => $features,
                'usage_records_30d' => $usageRecords30d
            ];
        }
    }

    /*
     * ---------------------------------------------------------
     * 10. Extract prediction
     * ---------------------------------------------------------
     */

    $score = (float) $prediction['probability'];

    $level = (string) $prediction['risk_level'];

    $predictionValue = (int) $prediction['prediction'];


    /*
     * ---------------------------------------------------------
     * 11. Model version
     * ---------------------------------------------------------
     */

    $modelVersion =
        'logistic-regression-v1';


    /*
     * ---------------------------------------------------------
     * 12. Save prediction when explicitly requested
     * ---------------------------------------------------------
     */

    $predictionSaved = false;
    $notificationSent = false;
    $notificationError = '';

    if ($savePrediction) {

        $factors = json_encode(
            $features,
            JSON_UNESCAPED_SLASHES
        );


        try {
            $stmt = $conn->prepare("
                INSERT INTO maintenance_risk_predictions
                (
                    laptop_id,
                    risk_score,
                    risk_level,
                    model_version,
                    factors,
                    predicted_at
                )
                VALUES
                (?, ?, ?, ?, ?, NOW())
            ");


            $stmt->bind_param(
                "idsss",
                $laptopId,
                $score,
                $level,
                $modelVersion,
                $factors
            );


            $stmt->execute();

            $stmt->close();

            $predictionSaved = true;

            if ($level === 'High') {
                try {
                    $lowRiskStmt = $conn->prepare("
                        SELECT predicted_at
                        FROM maintenance_risk_predictions
                        WHERE laptop_id = ?
                          AND risk_level <> 'High'
                        ORDER BY predicted_at DESC, id DESC
                        LIMIT 1
                    ");
                    $lowRiskStmt->bind_param('i', $laptopId);
                    $lowRiskStmt->execute();
                    $lastLowRisk = $lowRiskStmt
                        ->get_result()
                        ->fetch_assoc();
                    $lowRiskStmt->close();

                    if ($lastLowRisk) {
                        $episodeStart = (string) $lastLowRisk['predicted_at'];
                    } else {
                        $firstHighStmt = $conn->prepare("
                            SELECT predicted_at
                            FROM maintenance_risk_predictions
                            WHERE laptop_id = ?
                              AND risk_level = 'High'
                            ORDER BY predicted_at ASC, id ASC
                            LIMIT 1
                        ");
                        $firstHighStmt->bind_param('i', $laptopId);
                        $firstHighStmt->execute();
                        $firstHigh = $firstHighStmt
                            ->get_result()
                            ->fetch_assoc();
                        $firstHighStmt->close();

                        if (!$firstHigh) {
                            throw new RuntimeException(
                                'The high-risk prediction could not be located for notification deduplication.'
                            );
                        }

                        $episodeStart = (string) $firstHigh['predicted_at'];
                    }

                    $recipientCount = maintenance_risk_notify_admins(
                        $conn,
                        $laptopId,
                        (string) $laptop['asset_tag'],
                        $score,
                        $episodeStart
                    );
                    $notificationSent = $recipientCount > 0;
                } catch (Throwable $e) {
                    $notificationError = 'The risk was saved, but its notification could not be sent.';
                    error_log(
                        'High maintenance risk notification failed for laptop ' .
                        $laptopId .
                        ': ' .
                        $e->getMessage()
                    );
                }
            }

        } catch (Throwable $e) {

            return [
                'success' => false,
                'error' =>
                    'Prediction was generated but could not be saved.',
                'details' => $e->getMessage(),
                'score' => $score,
                'level' => $level,
                'version' => $modelVersion,
                'features' => $features,
                'usage_records_30d' => $usageRecords30d
            ];
        }
    }


    /*
     * ---------------------------------------------------------
     * 13. Return complete result
     * ---------------------------------------------------------
     */

    return [

        'success' => true,

        'laptop_id' =>
            $laptopId,

        'asset_tag' =>
            $laptop['asset_tag'],

        'brand' =>
            $laptop['brand'],

        'model' =>
            $laptop['model'],

        'status' =>
            $laptop['status'],

        'prediction' =>
            $predictionValue,

        'score' =>
            $score,

        'level' =>
            $level,

        'version' =>
            $modelVersion,

        'prediction_source' =>
            $predictionSource,

        'features' =>
            $features,

        'usage_records_30d' =>
            $usageRecords30d,

        'prediction_saved' =>
            $predictionSaved,

        'notification_sent' =>
            $notificationSent,

        'notification_error' =>
            $notificationError
    ];
}
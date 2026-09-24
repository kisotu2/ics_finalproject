<?php

/**
 * Predictive Maintenance Risk
 *
 * The PHP application retrieves current asset information from MySQL,
 * prepares the seven features required by the trained Logistic
 * Regression model, and sends them to the Flask ML API.
 *
 * The Flask service is responsible for loading the trained model
 * and performing the prediction.
 */

require_once __DIR__ . '/../db.php';


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

        CURLOPT_CONNECTTIMEOUT => 5,

        CURLOPT_TIMEOUT => 15
    ]);


    $response = curl_exec($ch);


    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        return [
            'success' => false,
            'error' =>
                'The predictive maintenance service is unavailable.',
            'details' => $error,
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => $features,
            'usage_records_30d' => $usageRecords30d
        ];
    }


    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);


    /*
     * ---------------------------------------------------------
     * 10. Decode Flask response
     * ---------------------------------------------------------
     */

    $prediction =
        json_decode(
            $response,
            true
        );


    if (
        $httpCode !== 200 ||
        !is_array($prediction)
    ) {

        return [
            'success' => false,
            'error' =>
                'The predictive maintenance service returned an invalid response.',
            'http_code' => $httpCode,
            'response' => $response,
            'score' => 0,
            'level' => 'Unknown',
            'version' => 'logistic-regression-v1',
            'features' => $features,
            'usage_records_30d' => $usageRecords30d
        ];
    }


    /*
     * ---------------------------------------------------------
     * 11. Extract prediction
     * ---------------------------------------------------------
     */

    $score =
        (float)(
            $prediction['probability']
            ?? 0
        );

    $level =
        (string)(
            $prediction['risk_level']
            ?? 'Unknown'
        );

    $predictionValue =
        (int)(
            $prediction['prediction']
            ?? 0
        );


    /*
     * ---------------------------------------------------------
     * 12. Model version
     * ---------------------------------------------------------
     */

    $modelVersion =
        'logistic-regression-v1';


    /*
     * ---------------------------------------------------------
     * 13. Save prediction when explicitly requested
     * ---------------------------------------------------------
     */

    $predictionSaved = false;

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
     * 14. Return complete result
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

        'features' =>
            $features,

        'usage_records_30d' =>
            $usageRecords30d,

        'prediction_saved' =>
            $predictionSaved
    ];
}
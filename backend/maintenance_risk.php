<?php

/**
 * Predictive Maintenance Risk
 *
 * Retrieves current asset information from MySQL,
 * prepares the seven ML features, sends them to the
 * Flask Logistic Regression API, and optionally stores
 * the prediction in maintenance_risk_predictions.
 */

require_once __DIR__ . '/../db.php';


function maintenance_risk(
    int $laptopId,
    bool $savePrediction = false
): array {

    global $conn;


    /*
     * ---------------------------------------------------------
     * 1. Get laptop information
     * ---------------------------------------------------------
     */

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

    if (!$laptop) {
        return [
            'success' => false,
            'error' => 'Laptop not found.'
        ];
    }


    /*
     * ---------------------------------------------------------
     * 2. Calculate asset age
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

        } catch (Exception $e) {

            $assetAgeYears = 0;
        }
    }


    /*
     * ---------------------------------------------------------
     * 3. Calculate remaining warranty
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

        } catch (Exception $e) {

            $warrantyDaysRemaining = 0;
        }
    }


    /*
     * ---------------------------------------------------------
     * 4. Maintenance history
     * ---------------------------------------------------------
     */

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


    /*
     * ---------------------------------------------------------
     * 5. Usage during the last 30 days
     * ---------------------------------------------------------
     */

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS usage_records_30d,
            COALESCE(SUM(active_hours), 0)
                AS active_hours_30d,
            COALESCE(SUM(crash_count), 0)
                AS crash_count_30d
        FROM device_usage_daily
        WHERE laptop_id = ?
          AND usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
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


    /*
     * ---------------------------------------------------------
     * 6. Latest battery health
     * ---------------------------------------------------------
     */

    $stmt = $conn->prepare("
        SELECT
            battery_health_percent
        FROM device_usage_daily
        WHERE laptop_id = ?
          AND battery_health_percent IS NOT NULL
        ORDER BY usage_date DESC, id DESC
        LIMIT 1
    ");

    $stmt->bind_param("i", $laptopId);
    $stmt->execute();

    $result = $stmt->get_result();
    $battery = $result->fetch_assoc();

    $stmt->close();

    $batteryHealth = isset(
        $battery['battery_health_percent']
    )
        ? (float)$battery['battery_health_percent']
        : 100;


    /*
     * ---------------------------------------------------------
     * 7. Build the seven ML features
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
     * 8. Send features to Flask
     * ---------------------------------------------------------
     */

    $url = 'http://127.0.0.1:5000/predict';

    $payload = json_encode($features);

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
                'Unable to connect to predictive maintenance service.',
            'details' => $error
        ];
    }


    $httpCode =
        curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);


    /*
     * ---------------------------------------------------------
     * 9. Process Flask response
     * ---------------------------------------------------------
     */

    $prediction =
        json_decode($response, true);


    if (
        $httpCode !== 200 ||
        !is_array($prediction)
    ) {

        return [
            'success' => false,
            'error' =>
                'Predictive maintenance service returned an invalid response.',
            'http_code' => $httpCode,
            'response' => $response
        ];
    }


    $score =
        (float)($prediction['probability'] ?? 0);

    $level =
        $prediction['risk_level'] ?? 'Unknown';


    /*
     * ---------------------------------------------------------
     * 10. Save prediction if requested
     * ---------------------------------------------------------
     */

    $predictionSaved = false;

    if ($savePrediction) {

        /*
         * The factors column stores the exact feature values
         * used for this prediction.
         */
        $factors = json_encode(
            $features,
            JSON_UNESCAPED_SLASHES
        );

        /*
         * Deployment/model version.
         *
         * Keep this identifier consistent with the model
         * deployed in flask_api/maintenance_model.json.
         */
        $modelVersion = 'logistic-regression-v1';

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
    }


    /*
     * ---------------------------------------------------------
     * 11. Return complete result
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
            (int)($prediction['prediction'] ?? 0),

        'score' =>
            $score,

        'level' =>
            $level,

        'features' =>
            $features,

        'usage_records_30d' =>
            $usageRecords30d,

        'prediction_saved' =>
            $predictionSaved
    ];
}
<?php

function predictMaintenance(array $asset): array
{
    $url = 'http://127.0.0.1:5000/predict';

    $payload = [
        'repair_count' => (float)($asset['repair_count'] ?? 0),
        'open_repair_count' => (float)($asset['open_repair_count'] ?? 0),
        'asset_age_years' => (float)($asset['asset_age_years'] ?? 0),
        'warranty_days_remaining' => (float)($asset['warranty_days_remaining'] ?? 0),
        'active_hours_30d' => (float)($asset['active_hours_30d'] ?? 0),
        'crash_count_30d' => (float)($asset['crash_count_30d'] ?? 0),
        'battery_health_percent' => (float)($asset['battery_health_percent'] ?? 0)
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
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
            'error' => 'Unable to connect to ML service: ' . $error
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode !== 200 || !is_array($result)) {
        return [
            'success' => false,
            'error' => 'ML service returned an invalid response.',
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'prediction' => $result['prediction'] ?? null,
        'probability' => $result['probability'] ?? null,
        'risk_level' => $result['risk_level'] ?? null
    ];
}
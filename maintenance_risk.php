<?php
/** Explainable local inference for maintenance risk. */

function maintenance_risk(array $asset): array
{
    $repairs = (int)($asset['repairs'] ?? 0);
    $openRepairs = (int)($asset['open_repairs'] ?? 0);
    $assetAge = (float)($asset['asset_age_years'] ?? 0);
    $warrantyDays = (int)($asset['warranty_days_remaining'] ?? 0);
    $activeHours = (float)($asset['active_hours_30d'] ?? 0);
    $crashes = (int)($asset['crash_count_30d'] ?? 0);
    $batteryHealth = (float)($asset['battery_health_percent'] ?? 100);

    $features = [
        'repair_count' => min(10, max(0, $repairs)) / 10,

        'open_repair_count' => min(5, max(0, $openRepairs)) / 5,

        'asset_age_years' => min(12, max(0, $assetAge)) / 12,

        'warranty_days_remaining' =>
            max(-730, min(730, $warrantyDays)) / 730,

        'active_hours_30d' =>
            min(720, max(0, $activeHours)) / 720,

        'crash_count_30d' =>
            min(30, max(0, $crashes)) / 30,

        'battery_health_percent' =>
            min(100, max(0, $batteryHealth)) / 100,
    ];

    $fallback = [
        'version' => 'baseline-logistic-v1',

        'intercept' => -3.0,

        'weights' => [
            'repair_count' => 1.35,
            'open_repair_count' => 2.25,
            'asset_age_years' => 1.10,
            'warranty_days_remaining' => -0.85,
            'active_hours_30d' => 0.40,
            'crash_count_30d' => 1.20,
            'battery_health_percent' => -0.75,
        ],
    ];

    $modelFile = __DIR__ . '/ml/maintenance_model.json';

    $model = null;

    if (is_file($modelFile)) {
        $model = json_decode(
            (string)file_get_contents($modelFile),
            true
        );
    }

    if (
        !is_array($model) ||
        !isset($model['intercept']) ||
        !isset($model['weights'])
    ) {
        $model = $fallback;
    }

    $logit = (float)$model['intercept'];

    foreach ($features as $name => $value) {
        $weight = (float)($model['weights'][$name] ?? 0);
        $logit += $weight * $value;
    }

    $logit = max(-30, min(30, $logit));

    $score = 1 / (1 + exp(-$logit));

    if ($score >= 0.70) {
        $level = 'High';
    } elseif ($score >= 0.35) {
        $level = 'Medium';
    } else {
        $level = 'Low';
    }

    return [
        'score' => $score,
        'level' => $level,
        'version' => (string)($model['version'] ?? 'custom-logistic'),
        'features' => $features
    ];
}
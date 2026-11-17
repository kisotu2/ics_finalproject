<?php
declare(strict_types=1);

function maintenance_model_artifact(): array
{
    static $model = null;
    if (is_array($model)) {
        return $model;
    }

    $modelPath = __DIR__ . '/../flask_api/maintenance_model.json';
    $modelJson = file_get_contents($modelPath);
    if ($modelJson === false) {
        throw new RuntimeException('Unable to read the local maintenance model.');
    }

    $loaded = json_decode($modelJson, true, 512, JSON_THROW_ON_ERROR);
    $expectedFeatures = [
        'repair_count',
        'open_repair_count',
        'asset_age_years',
        'warranty_days_remaining',
        'active_hours_30d',
        'crash_count_30d',
        'battery_health_percent',
    ];
    $features = $loaded['features'] ?? null;
    $coefficients = $loaded['coefficients'] ?? null;
    $means = $loaded['scaler_mean'] ?? null;
    $scales = $loaded['scaler_scale'] ?? null;

    if (
        $features !== $expectedFeatures ||
        !is_array($coefficients) ||
        !is_array($means) ||
        !is_array($scales) ||
        count($coefficients) !== count($expectedFeatures) ||
        count($means) !== count($expectedFeatures) ||
        count($scales) !== count($expectedFeatures) ||
        !is_numeric($loaded['intercept'] ?? null) ||
        !is_finite((float) $loaded['intercept'])
    ) {
        throw new RuntimeException('The local maintenance model has an incompatible feature schema.');
    }

    foreach ($expectedFeatures as $index => $_feature) {
        if (
            !is_numeric($coefficients[$index] ?? null) ||
            !is_finite((float) $coefficients[$index]) ||
            !is_numeric($means[$index] ?? null) ||
            !is_finite((float) $means[$index]) ||
            !is_numeric($scales[$index] ?? null) ||
            !is_finite((float) $scales[$index]) ||
            (float) $scales[$index] <= 0
        ) {
            throw new RuntimeException('The local maintenance model contains invalid scaler values.');
        }
    }

    $model = $loaded;
    return $model;
}

function maintenance_model_feature_means(): array
{
    $model = maintenance_model_artifact();
    return array_combine(
        $model['features'],
        array_map('floatval', $model['scaler_mean'])
    );
}

function maintenance_risk_local_prediction(array $features): array
{
    $model = maintenance_model_artifact();
    $logit = (float) $model['intercept'];

    foreach ($model['features'] as $index => $featureName) {
        $value = $features[$featureName] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new RuntimeException('The local maintenance model inputs are invalid.');
        }

        $logit += (float) $model['coefficients'][$index]
            * (((float) $value - (float) $model['scaler_mean'][$index])
                / (float) $model['scaler_scale'][$index]);
    }

    $probability = $logit >= 0
        ? 1 / (1 + exp(-$logit))
        : exp($logit) / (1 + exp($logit));

    $riskLevel = $probability >= 0.70
        ? 'High'
        : ($probability >= 0.40 ? 'Medium' : 'Low');

    return [
        'prediction' => $probability >= 0.5 ? 1 : 0,
        'probability' => round($probability, 4),
        'risk_level' => $riskLevel,
        'model_version' => $model['model_version'] ?? 'logistic-regression-v1',
    ];
}

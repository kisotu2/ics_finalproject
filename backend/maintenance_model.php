<?php
declare(strict_types=1);

function maintenance_risk_local_prediction(array $features): array
{
    $modelPath = __DIR__ . '/../flask_api/maintenance_model.json';
    $modelJson = file_get_contents($modelPath);

    if ($modelJson === false) {
        throw new RuntimeException('Unable to read the local maintenance model.');
    }

    $model = json_decode($modelJson, true, 512, JSON_THROW_ON_ERROR);
    $modelFeatures = $model['features'] ?? null;
    $coefficients = $model['coefficients'] ?? null;
    $means = $model['scaler_mean'] ?? null;
    $scales = $model['scaler_scale'] ?? null;

    if (
        !is_array($modelFeatures) ||
        !is_array($coefficients) ||
        !is_array($means) ||
        !is_array($scales) ||
        count($modelFeatures) === 0 ||
        count($modelFeatures) !== count($coefficients) ||
        count($modelFeatures) !== count($means) ||
        count($modelFeatures) !== count($scales) ||
        !is_numeric($model['intercept'] ?? null)
    ) {
        throw new RuntimeException('The local maintenance model is invalid.');
    }

    $logit = (float) $model['intercept'];

    foreach ($modelFeatures as $index => $featureName) {
        $value = $features[$featureName] ?? null;
        $coefficient = $coefficients[$index] ?? null;
        $mean = $means[$index] ?? null;
        $scale = $scales[$index] ?? null;

        if (
            !is_numeric($value) ||
            !is_numeric($coefficient) ||
            !is_numeric($mean) ||
            !is_numeric($scale) ||
            (float) $scale == 0.0
        ) {
            throw new RuntimeException('The local maintenance model inputs are invalid.');
        }

        $logit += (float) $coefficient
            * (((float) $value - (float) $mean) / (float) $scale);
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
    ];
}

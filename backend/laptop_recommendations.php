<?php
declare(strict_types=1);

function laptop_profile_for(string $rank, string $workType): array
{
    $highDemand = in_array($workType, ['Development', 'Design', 'Data analysis'], true);
    $portable = in_array($rank, ['Manager', 'Senior manager', 'Director'], true);
    if ($highDemand || in_array($rank, ['Director', 'Senior manager'], true)) {
        return ['tier' => 'Performance', 'ram' => 16, 'storage' => 512, 'portable' => $portable];
    }
    if (in_array($rank, ['Manager', 'Officer'], true)) {
        return ['tier' => 'Standard', 'ram' => 8, 'storage' => 256, 'portable' => $portable];
    }

    return ['tier' => 'Standard', 'ram' => 8, 'storage' => 256, 'portable' => $portable];
}

function laptop_recommendation_score(array $laptop, array $need, string $department): int
{
    $tierPoints = ['Standard' => 1, 'Performance' => 2, 'Workstation' => 3];
    $score = ($laptop['department'] === '' || $laptop['department'] === $department) ? 25 : 10;
    if ($laptop['ram_gb'] !== null) {
        $score += min(35, max(0, ((int) $laptop['ram_gb'] - $need['ram'] + 8) * 4));
    }
    if ($laptop['storage_gb'] !== null) {
        $score += min(25, max(0, ((int) $laptop['storage_gb'] - $need['storage'] + 128) / 16));
    }
    $score += (($tierPoints[$laptop['processor_tier']] ?? 1) >= ($tierPoints[$need['tier']] ?? 1)) ? 15 : 0;
    $score = min(100, (int) $score);
    if ($need['portable']) {
        $weightScore = $laptop['weight_kg'] !== null
            ? max(0, min(10, (2.5 - (float) $laptop['weight_kg']) * 10))
            : 0;
        $sizeScore = $laptop['screen_size_inches'] !== null
            ? max(0, min(10, (17 - (float) $laptop['screen_size_inches']) * (10 / 3)))
            : 0;
        $score = (int) round(($score * 0.8) + $weightScore + $sizeScore);
    }

    return $score;
}

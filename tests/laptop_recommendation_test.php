<?php
declare(strict_types=1);

require __DIR__ . '/../backend/laptop_recommendations.php';

$baseLaptop = [
    'department' => 'Finance',
    'ram_gb' => 8,
    'storage_gb' => 256,
    'processor_tier' => 'Standard',
    'weight_kg' => 1.4,
    'screen_size_inches' => 13.3,
];
$managerNeed = laptop_profile_for('Manager', 'Finance');
$lightLaptop = $baseLaptop;
$heavyLaptop = array_merge($baseLaptop, ['weight_kg' => 2.4, 'screen_size_inches' => 16.0]);

if (! $managerNeed['portable']) {
    throw new RuntimeException('Manager profiles must include portability scoring.');
}
if (
    laptop_recommendation_score($lightLaptop, $managerNeed, 'Finance')
    <= laptop_recommendation_score($heavyLaptop, $managerNeed, 'Finance')
) {
    throw new RuntimeException('Lighter, smaller laptops must rank higher for managers.');
}

$staffNeed = laptop_profile_for('Staff', 'Finance');
if ($staffNeed['portable']) {
    throw new RuntimeException('Staff profiles must retain the existing non-portability score.');
}
if (
    laptop_recommendation_score($lightLaptop, $staffNeed, 'Finance')
    !== laptop_recommendation_score($heavyLaptop, $staffNeed, 'Finance')
) {
    throw new RuntimeException('Portability measurements must not change non-senior scores.');
}

echo "Laptop recommendation checks passed.\n";

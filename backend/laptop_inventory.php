<?php
declare(strict_types=1);

function next_laptop_asset_tag(mysqli $conn): string
{
    $result = $conn->query(
        "SELECT asset_tag
         FROM laptops
         WHERE asset_tag >= 'IRA-LAP-' AND asset_tag < 'IRA-LAQ-'
         ORDER BY asset_tag
         FOR UPDATE"
    );

    $usedNumbers = [];
    while ($row = $result->fetch_assoc()) {
        if (preg_match('/^IRA-LAP-(\d+)$/', (string) $row['asset_tag'], $matches)) {
            $usedNumbers[(int) $matches[1]] = true;
        }
    }

    for ($number = 1; ; $number++) {
        if (!isset($usedNumbers[$number])) {
            return sprintf('IRA-LAP-%03d', $number);
        }
    }
}

<?php
declare(strict_types=1);

function next_laptop_asset_tag(mysqli $conn): string
{
    $result = $conn->query(
        "SELECT tag.asset_tag
         FROM laptop_asset_tag_pool AS tag
         LEFT JOIN laptops AS laptop ON laptop.asset_tag = tag.asset_tag
         WHERE tag.is_assigned = 0 AND laptop.id IS NULL
         ORDER BY tag.asset_tag
         LIMIT 1"
    );
    $row = $result->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('No unused laptop asset tags remain in the tag pool.');
    }

    return (string) $row['asset_tag'];
}

function claim_laptop_asset_tag(mysqli $conn, string $assetTag): void
{
    $select = $conn->prepare(
        "SELECT tag.asset_tag
         FROM laptop_asset_tag_pool AS tag
         LEFT JOIN laptops AS laptop ON laptop.asset_tag = tag.asset_tag
         WHERE tag.asset_tag = ? AND tag.is_assigned = 0 AND laptop.id IS NULL
         FOR UPDATE"
    );
    $select->bind_param('s', $assetTag);
    $select->execute();
    $availableTag = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$availableTag) {
        throw new InvalidArgumentException('That asset tag is no longer available. Review the current tag and submit again.');
    }

    $claim = $conn->prepare(
        'UPDATE laptop_asset_tag_pool SET is_assigned = 1 WHERE asset_tag = ? AND is_assigned = 0'
    );
    $claim->bind_param('s', $assetTag);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) {
        throw new InvalidArgumentException('That asset tag was just assigned to another device. Review the current tag and submit again.');
    }
}

function record_laptop_asset_tag_assignment(mysqli $conn, string $assetTag, int $laptopId): void
{
    $update = $conn->prepare(
        'UPDATE laptop_asset_tag_pool
         SET laptop_id = ?, assigned_at = CURRENT_TIMESTAMP
         WHERE asset_tag = ? AND is_assigned = 1 AND laptop_id IS NULL'
    );
    $update->bind_param('is', $laptopId, $assetTag);
    $update->execute();
    $updated = $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
        throw new RuntimeException('The assigned asset tag could not be linked to the laptop record.');
    }
}

function parse_laptop_measurement(string $value, float $minimum, float $maximum, string $label): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum) {
        throw new InvalidArgumentException(
            $label . ' must be a number between ' . $minimum . ' and ' . $maximum . '.'
        );
    }

    return round((float) $value, 2);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/asset_dates.php';

function ad_sync_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function ad_sync_record_label(mixed $record, int $index): string
{
    if (is_array($record) && isset($record['computer_name']) && is_string($record['computer_name'])) {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', trim($record['computer_name']));
        if ($name !== '') {
            return substr($name, 0, 63);
        }
    }

    return 'record ' . ($index + 1);
}

function validate_ad_sync_batch(array $payload): array
{
    if (!isset($payload['version']) || $payload['version'] !== 1) {
        throw new InvalidArgumentException('Payload version must be 1.');
    }
    if (!isset($payload['computers']) || !is_array($payload['computers']) || !ad_sync_is_list($payload['computers'])) {
        throw new InvalidArgumentException('Payload must contain a computers array.');
    }

    $input = $payload['computers'];
    if (count($input) < 1 || count($input) > 500) {
        throw new InvalidArgumentException('A sync must contain between 1 and 500 computers.');
    }

    $guidCounts = [];
    $nameCounts = [];
    foreach ($input as $record) {
        if (!is_array($record)) {
            continue;
        }
        $guidValue = $record['ad_guid'] ?? null;
        $guid = is_string($guidValue) ? strtolower(trim($guidValue)) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid)) {
            $guidCounts[$guid] = ($guidCounts[$guid] ?? 0) + 1;
        }
        $nameValue = $record['computer_name'] ?? null;
        $name = is_string($nameValue) ? strtoupper(trim($nameValue)) : '';
        if ($name !== '') {
            $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
        }
    }

    $records = [];
    $failed = [];
    foreach ($input as $index => $record) {
        $label = ad_sync_record_label($record, $index);
        if (is_array($record)) {
            $guidValue = $record['ad_guid'] ?? null;
            $nameValue = $record['computer_name'] ?? null;
            $guid = is_string($guidValue) ? strtolower(trim($guidValue)) : '';
            $name = is_string($nameValue) ? strtoupper(trim($nameValue)) : '';
            if (($guidCounts[$guid] ?? 0) > 1) {
                $failed[] = ['computer_name' => $label, 'message' => 'Duplicate AD object GUID in this batch.'];
                continue;
            }
            if (($nameCounts[$name] ?? 0) > 1) {
                $failed[] = ['computer_name' => $label, 'message' => 'Duplicate computer name in this batch.'];
                continue;
            }
        }

        try {
            $validated = validate_ad_sync_payload([
                'version' => 1,
                'computers' => [$record],
            ]);
            $records[] = $validated[0];
        } catch (InvalidArgumentException $exception) {
            $failed[] = ['computer_name' => $label, 'message' => $exception->getMessage()];
        }
    }

    return [$records, $failed];
}

function validate_ad_sync_payload(array $payload): array
{
    if (!isset($payload['version']) || $payload['version'] !== 1) {
        throw new InvalidArgumentException('Payload version must be 1.');
    }

    if (!isset($payload['computers']) || !is_array($payload['computers']) || !ad_sync_is_list($payload['computers'])) {
        throw new InvalidArgumentException('Payload must contain a computers array.');
    }

    if (count($payload['computers']) < 1 || count($payload['computers']) > 500) {
        throw new InvalidArgumentException('A sync must contain between 1 and 500 computers.');
    }

    $records = [];
    $required = [
        'ad_guid',
        'computer_name',
        'distinguished_name',
        'enabled',
        'operating_system',
        'operating_system_version',
        'manufacturer',
        'model',
        'manufacturer_serial',
        'processor',
        'ram_gb',
        'storage_gb',
    ];
    $limits = [
        'computer_name' => 63,
        'distinguished_name' => 1024,
        'operating_system' => 255,
        'operating_system_version' => 100,
        'manufacturer' => 100,
        'model' => 120,
        'manufacturer_serial' => 120,
        'processor' => 255,
    ];

    foreach ($payload['computers'] as $index => $record) {
        if (!is_array($record) || $record === [] || ad_sync_is_list($record)) {
            throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' must be an object.');
        }

        foreach ($required as $field) {
            if (!array_key_exists($field, $record)) {
                throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' is missing ' . $field . '.');
            }
        }

        if (!is_string($record['ad_guid'])) {
            throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' has an invalid AD object GUID.');
        }
        $guid = strtolower(trim($record['ad_guid']));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid)) {
            throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' has an invalid AD object GUID.');
        }

        if (!is_string($record['computer_name'])) {
            throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' has an invalid computer name.');
        }
        $computerName = trim($record['computer_name']);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $computerName)) {
            throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' has an invalid computer name.');
        }

        if (!is_bool($record['enabled'])) {
            throw new InvalidArgumentException('Computer record ' . ($index + 1) . ' must include a boolean enabled value.');
        }

        $normalized = [
            'ad_guid' => $guid,
            'computer_name' => $computerName,
            'distinguished_name' => validate_ad_text($record['distinguished_name'], 'distinguished_name', $limits['distinguished_name'], false),
            'enabled' => $record['enabled'],
            'operating_system' => validate_ad_text($record['operating_system'], 'operating_system', $limits['operating_system'], true),
            'operating_system_version' => validate_ad_text($record['operating_system_version'], 'operating_system_version', $limits['operating_system_version'], true),
            'manufacturer' => validate_ad_text($record['manufacturer'], 'manufacturer', $limits['manufacturer'], true),
            'model' => validate_ad_text($record['model'], 'model', $limits['model'], true),
            'manufacturer_serial' => validate_ad_text($record['manufacturer_serial'], 'manufacturer_serial', $limits['manufacturer_serial'], true),
            'processor' => validate_ad_text($record['processor'], 'processor', $limits['processor'], true),
            'ram_gb' => validate_ad_integer($record['ram_gb'], 'ram_gb', 1, 255),
            'storage_gb' => validate_ad_integer($record['storage_gb'], 'storage_gb', 1, 65535),
            'purchase_date' => validate_purchase_date(
                validate_optional_date_value($record['purchase_date'] ?? null, 'purchase_date')
            ),
            'warranty_expiry' => validate_warranty_expiry(
                validate_optional_date_value($record['warranty_expiry'] ?? null, 'warranty_expiry')
            ),
        ];

        $records[] = $normalized;
    }

    return $records;
}

function validate_optional_date_value(mixed $value, string $field): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a date string or null.');
    }

    return $value;
}

function ad_sync_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $matched = preg_match_all('/./us', $value, $characters);
    return $matched === false ? strlen($value) : $matched;
}

function validate_ad_text(mixed $value, string $field, int $maxLength, bool $nullable): ?string
{
    if ($value === null && $nullable) {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a string or null.');
    }

    $value = trim($value);
    if ($value === '' && $nullable) {
        return null;
    }

    if ($value === '' || ad_sync_text_length($value) > $maxLength) {
        throw new InvalidArgumentException($field . ' is required and must be at most ' . $maxLength . ' characters.');
    }

    return $value;
}

function validate_ad_integer(mixed $value, string $field, int $minimum, int $maximum): ?int
{
    if ($value === null) {
        return null;
    }

    if (!is_int($value) || $value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException($field . ' must be null or an integer from ' . $minimum . ' to ' . $maximum . '.');
    }

    return $value;
}

function ad_sync_database_schema_issues(mysqli $conn): array
{
    $expected = [
        'laptops' => [
            'asset_tag' => 'varchar(80)', 'serial_number' => 'varchar(120)',
            'manufacturer_serial' => 'varchar(120)', 'ad_object_guid' => 'char(36)',
            'ad_computer_name' => 'varchar(63)', 'ad_distinguished_name' => 'varchar(1024)',
            'ad_enabled' => 'tinyint(1)', 'ad_operating_system' => 'varchar(255)',
            'ad_operating_system_version' => 'varchar(100)', 'ad_last_synced_at' => 'datetime(6)',
            'brand' => 'varchar(100)', 'model' => 'varchar(120)', 'processor_description' => 'varchar(255)',
            'ram_gb' => 'tinyint unsigned', 'storage_gb' => 'smallint unsigned',
            'status' => "enum('available','assigned','maintenance','retired','disposed')",
            'purchase_date' => 'date', 'warranty_expiry' => 'date',
        ],
        'ad_sync_events' => [
            'id' => 'bigint', 'source_ip' => 'varchar(45)', 'received_count' => 'int unsigned',
            'created_count' => 'int unsigned', 'updated_count' => 'int unsigned',
            'failed_count' => 'int unsigned', 'created_at' => 'timestamp',
        ],
    ];
    $issues = [];
    foreach ($expected as $table => $columns) {
        $stmt = $conn->prepare('SELECT column_name, column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $actual = [];
        foreach ($stmt->get_result() as $column) {
            $actual[$column['column_name']] = strtolower((string) $column['column_type']);
        }
        foreach ($columns as $name => $type) {
            if (!isset($actual[$name])) {
                $issues[] = $table . '.' . $name . ' missing';
            } elseif (($table === 'ad_sync_events' && $name === 'summary'
                    && !in_array($actual[$name] ?? '', ['json', 'longtext'], true))
                || (isset($actual[$name]) && $actual[$name] !== $type)) {
                $issues[] = $table . '.' . $name . ' type mismatch';
            }
        }
        if ($table === 'ad_sync_events') {
            $hasSummary = isset($actual['summary']) && in_array($actual['summary'], ['json', 'longtext'], true);
            if (!$hasSummary) {
                $issues[] = 'ad_sync_events.summary missing or incompatible';
            }
        }
    }

    $requiredUnique = ['laptops' => ['asset_tag', 'serial_number', 'ad_object_guid']];
    foreach ($requiredUnique as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $conn->prepare("SELECT COUNT(DISTINCT s.index_name) AS total
                FROM information_schema.statistics s
                WHERE s.table_schema = DATABASE() AND s.table_name = ?
                  AND s.column_name = ? AND s.seq_in_index = 1 AND s.non_unique = 0
                  AND (SELECT COUNT(*) FROM information_schema.statistics s2
                       WHERE s2.table_schema = s.table_schema AND s2.table_name = s.table_name
                         AND s2.index_name = s.index_name) = 1");
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            if ((int) $stmt->get_result()->fetch_assoc()['total'] === 0) {
                $issues[] = $table . '.' . $column . ' unique index missing';
            }
        }
    }

    $primary = $conn->query("SELECT COUNT(*) AS total FROM information_schema.key_column_usage
        WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events'
          AND constraint_name = 'PRIMARY' AND column_name = 'id' AND ordinal_position = 1");
    if ((int) $primary->fetch_assoc()['total'] === 0) {
        $issues[] = 'ad_sync_events.id primary key missing';
    }
    $eventIndex = $conn->query("SELECT COUNT(*) AS total FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events'
          AND column_name = 'created_at' AND seq_in_index = 1");
    if ((int) $eventIndex->fetch_assoc()['total'] === 0) {
        $issues[] = 'ad_sync_events.created_at index missing';
    }

    return array_values(array_unique($issues));
}

function sync_ad_computer(mysqli $conn, array $record): array
{
    $assetTag = 'AD-' . strtoupper($record['computer_name']);
    $placeholderSerial = 'AD-PENDING-' . strtoupper(str_replace('-', '', $record['ad_guid']));
    $brand = $record['manufacturer'] ?? 'Not reported';
    $model = $record['model'] ?? 'Not reported';
    $enabled = (int) $record['enabled'];
    $ramGb = $record['ram_gb'];
    $storageGb = $record['storage_gb'];
    $purchaseDate = $record['purchase_date'];
    $warrantyExpiry = $record['warranty_expiry'];

    $conn->begin_transaction();

    try {
        $assetCheck = $conn->prepare('SELECT id, ad_object_guid FROM laptops WHERE asset_tag = ? LIMIT 1 FOR UPDATE');
        $assetCheck->bind_param('s', $assetTag);
        $assetCheck->execute();
        $assetOwner = $assetCheck->get_result()->fetch_assoc();

        if ($assetOwner && $assetOwner['ad_object_guid'] !== $record['ad_guid']) {
            throw new RuntimeException('The AD computer name conflicts with an existing asset tag.');
        }

        $serialCheck = $conn->prepare('SELECT id, ad_object_guid FROM laptops WHERE serial_number = ? LIMIT 1 FOR UPDATE');
        $serialCheck->bind_param('s', $placeholderSerial);
        $serialCheck->execute();
        $serialOwner = $serialCheck->get_result()->fetch_assoc();

        if ($serialOwner && $serialOwner['ad_object_guid'] !== $record['ad_guid']) {
            throw new RuntimeException('Temporary serial number conflicts with an existing inventory serial.');
        }

        $guidCheck = $conn->prepare('SELECT id FROM laptops WHERE ad_object_guid = ? LIMIT 1 FOR UPDATE');
        $guidCheck->bind_param('s', $record['ad_guid']);
        $guidCheck->execute();
        $existing = $guidCheck->get_result()->fetch_assoc();

        if ($existing) {
            $laptopId = (int) $existing['id'];
            $update = $conn->prepare("
                UPDATE laptops
                SET asset_tag = ?,
                    brand = COALESCE(NULLIF(?, 'Not reported'), brand),
                    model = COALESCE(NULLIF(?, 'Not reported'), model),
                    processor_description = COALESCE(?, processor_description),
                    ram_gb = COALESCE(?, ram_gb),
                    storage_gb = COALESCE(?, storage_gb),
                    purchase_date = COALESCE(?, purchase_date),
                    warranty_expiry = COALESCE(?, warranty_expiry),
                    manufacturer_serial = COALESCE(?, manufacturer_serial),
                    ad_computer_name = ?,
                    ad_distinguished_name = ?,
                    ad_enabled = ?,
                    ad_operating_system = COALESCE(?, ad_operating_system),
                    ad_operating_system_version = COALESCE(?, ad_operating_system_version),
                    ad_last_synced_at = UTC_TIMESTAMP(6)
                WHERE id = ?
            ");
            $updateTypes = 'ssssii' . 'sssss' . 'i' . 'ss' . 'i';
            $update->bind_param(
                $updateTypes,
                $assetTag,
                $brand,
                $model,
                $record['processor'],
                $ramGb,
                $storageGb,
                $purchaseDate,
                $warrantyExpiry,
                $record['manufacturer_serial'],
                $record['computer_name'],
                $record['distinguished_name'],
                $enabled,
                $record['operating_system'],
                $record['operating_system_version'],
                $laptopId
            );
            $update->execute();
            $created = false;
        } else {
            $insert = $conn->prepare("
                INSERT INTO laptops
                    (asset_tag, serial_number, brand, model, processor_description, ram_gb, storage_gb,
                     status, purchase_date, warranty_expiry, manufacturer_serial, ad_object_guid, ad_computer_name,
                     ad_distinguished_name, ad_enabled, ad_operating_system, ad_operating_system_version,
                     ad_last_synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Available', ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
            ");
            $insert->bind_param(
                'sssssiissssssiss',
                $assetTag,
                $placeholderSerial,
                $brand,
                $model,
                $record['processor'],
                $ramGb,
                $storageGb,
                $purchaseDate,
                $warrantyExpiry,
                $record['manufacturer_serial'],
                $record['ad_guid'],
                $record['computer_name'],
                $record['distinguished_name'],
                $enabled,
                $record['operating_system'],
                $record['operating_system_version']
            );
            $insert->execute();
            $laptopId = (int) $conn->insert_id;
            $created = true;
        }

        $serialNumber = ensure_inventory_serial($conn, $laptopId);

        $conn->commit();

        return [
            'id' => $laptopId,
            'asset_tag' => $assetTag,
            'serial_number' => $serialNumber,
            'created' => $created,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function ensure_inventory_serial(mysqli $conn, int $laptopId): string
{
    $base = 'IRA-LAP-' . str_pad((string) $laptopId, 6, '0', STR_PAD_LEFT);

    for ($suffix = 0; $suffix < 1000; $suffix++) {
        $candidate = $suffix === 0 ? $base : $base . '-' . $suffix;
        $check = $conn->prepare('SELECT id FROM laptops WHERE serial_number = ? AND id <> ? LIMIT 1');
        $check->bind_param('si', $candidate, $laptopId);
        $check->execute();

        if (!$check->get_result()->fetch_assoc()) {
            $update = $conn->prepare('UPDATE laptops SET serial_number = ? WHERE id = ?');
            $update->bind_param('si', $candidate, $laptopId);
            $update->execute();
            return $candidate;
        }
    }

    throw new RuntimeException('Could not allocate a unique inventory serial after 1000 attempts.');
}

function log_ad_sync_event(mysqli $conn, string $remoteAddress, int $received, array $summary): void
{
    $encodedSummary = json_encode($summary, JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare("
        INSERT INTO ad_sync_events
            (source_ip, received_count, created_count, updated_count, failed_count, summary)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'siiiis',
        $remoteAddress,
        $received,
        $summary['created'],
        $summary['updated'],
        $summary['failed'],
        $encodedSummary
    );
    $stmt->execute();
}

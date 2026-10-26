<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/ad_sync.php';

$tests = 0;

function assert_true(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_throws(callable $callback, string $expectedMessage): void
{
    global $tests;
    $tests++;

    try {
        $callback();
    } catch (Throwable $exception) {
        if (str_contains($exception->getMessage(), $expectedMessage)) {
            return;
        }

        throw new RuntimeException(
            'Expected message containing "' . $expectedMessage . '", got "' . $exception->getMessage() . '".'
        );
    }

    throw new RuntimeException('Expected validation to fail with: ' . $expectedMessage);
}

function valid_record(): array
{
    return [
        'ad_guid' => 'a6f33f8b-f62d-4acb-96be-8e1ef6ab0c21',
        'computer_name' => 'WS-ACCOUNTING-01',
        'distinguished_name' => 'CN=WS-ACCOUNTING-01,OU=Workstations,DC=example,DC=test',
        'enabled' => true,
        'operating_system' => 'Windows 11 Enterprise',
        'operating_system_version' => '10.0.26100',
        'manufacturer' => 'Example Manufacturer',
        'model' => 'Example Model',
        'manufacturer_serial' => null,
        'processor' => 'Example CPU',
        'ram_gb' => 16,
        'storage_gb' => 512,
        'purchase_date' => null,
        'warranty_expiry' => null,
    ];
}

$valid = validate_ad_sync_payload([
    'version' => 1,
    'computers' => [valid_record()],
]);
assert_true(count($valid) === 1, 'Valid directory record should be accepted.');
assert_true($valid[0]['manufacturer_serial'] === null, 'Missing manufacturer serial must remain null.');
assert_true($valid[0]['purchase_date'] === null, 'Unknown purchase date must remain null.');

$tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$futureRecord = valid_record();
$futureRecord['purchase_date'] = $tomorrow;
assert_throws(
    static fn() => validate_ad_sync_payload(['version' => 1, 'computers' => [$futureRecord]]),
    'cannot be in the future'
);

$malformedJson = '{"version":1,"computers":[';
assert_throws(
    static fn() => json_decode($malformedJson, true, 512, JSON_THROW_ON_ERROR),
    'Syntax error'
);

$missingFieldRecord = valid_record();
unset($missingFieldRecord['ad_guid']);
assert_throws(
    static fn() => validate_ad_sync_payload(['version' => 1, 'computers' => [$missingFieldRecord]]),
    'missing ad_guid'
);

$invalidDateRecord = valid_record();
$invalidDateRecord['purchase_date'] = '2026-02-30';
assert_throws(
    static fn() => validate_ad_sync_payload(['version' => 1, 'computers' => [$invalidDateRecord]]),
    'valid YYYY-MM-DD'
);

$futureWarrantyRecord = valid_record();
$futureWarrantyRecord['warranty_expiry'] = '2099-12-31';
$futureWarranty = validate_ad_sync_payload([
    'version' => 1,
    'computers' => [$futureWarrantyRecord],
]);
assert_true($futureWarranty[0]['warranty_expiry'] === '2099-12-31', 'Future warranty expiry must remain valid.');

fwrite(STDOUT, 'Passed ' . $tests . " AD sync validation tests.\n");

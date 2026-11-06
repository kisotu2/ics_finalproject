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

$invalidGuidRecord = valid_record();
$invalidGuidRecord['computer_name'] = 'WS-INVALID-01';
$invalidGuidRecord['ad_guid'] = 'not-a-guid';
[$partialRecords, $partialFailures] = validate_ad_sync_batch([
    'version' => 1,
    'computers' => [valid_record(), $invalidGuidRecord],
]);
assert_true(count($partialRecords) === 1, 'A malformed computer must not block a valid peer.');
assert_true(count($partialFailures) === 1, 'The malformed computer must be reported as failed.');
assert_true(str_contains($partialFailures[0]['message'], 'invalid AD object GUID'), 'The failure should identify the GUID problem.');

$duplicateA = valid_record();
$duplicateB = valid_record();
$duplicateB['computer_name'] = 'WS-ACCOUNTING-02';
[$duplicateRecords, $duplicateFailures] = validate_ad_sync_batch([
    'version' => 1,
    'computers' => [$duplicateA, $duplicateB],
]);
assert_true(count($duplicateRecords) === 0, 'Duplicate AD GUIDs must not be synchronized.');
assert_true(count($duplicateFailures) === 2, 'Both duplicate identities must be reported.');
assert_true(ad_sync_text_length('設備A') === 3, 'UTF-8 character limits must count characters.');

$wrongTypeRecord = valid_record();
$wrongTypeRecord['ad_guid'] = ['unexpected'];
[$typedRecords, $typedFailures] = validate_ad_sync_batch(['version' => 1, 'computers' => [$wrongTypeRecord]]);
assert_true($typedRecords === [] && count($typedFailures) === 1, 'Wrongly typed fields must fail cleanly without warnings.');

$metadataRecord = valid_record();
$metadataRecord['enabled'] = false;
$metadataRecord['endpoint_status'] = 'unreachable';
$metadataRecord['endpoint_check_reason'] = 'WinRM authorization denied.';
$metadataRecord['dns_hostname'] = 'ws-accounting-01.example.test';
$metadataRecord['last_logon_at'] = '2026-11-06T08:30:00Z';
$metadataRecord['account_created_at'] = '2025-01-02T03:04:05Z';
$metadataRecord['hardware_last_scanned_at'] = null;
$metadata = validate_ad_sync_payload(['version'=>1,'computers'=>[$metadataRecord]])[0];
assert_true($metadata['enabled'] === false && $metadata['endpoint_status'] === 'unreachable', 'AD account and endpoint states must remain separate validated values.');
assert_true($metadata['last_logon_at'] === '2026-11-06 08:30:00.000000', 'AD timestamps must normalize to UTC MySQL datetime values.');
$emptyFinal = validate_ad_sync_batch(['version'=>1,'run_id'=>'a6f33f8b-f62d-4acb-96be-8e1ef6ab0c21','scope_id'=>'OU=Workstations,DC=example,DC=test','final_batch'=>true,'full_scope'=>true,'computers'=>[]]);
assert_true($emptyFinal[0] === [] && $emptyFinal[1] === [], 'A complete empty scope must be accepted for a final run batch.');
$badEndpoint = valid_record(); $badEndpoint['endpoint_status'] = 'powered_off';
assert_throws(static fn()=>validate_ad_sync_payload(['version'=>1,'computers'=>[$badEndpoint]]), 'endpoint_status must be');

fwrite(STDOUT, 'Passed ' . $tests . " AD sync validation tests.\n");

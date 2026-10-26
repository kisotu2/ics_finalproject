<?php
declare(strict_types=1);

const AD_SYNC_MAX_BODY_BYTES = 2097152;

function ad_sync_respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ad_sync_authorization_header(): string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }

    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return '';
}

function ad_sync_is_secure_transport(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return getenv('AD_SYNC_ALLOW_INSECURE_LOCAL') === '1'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

$expectedToken = getenv('AD_SYNC_TOKEN');
if ($expectedToken === false || strlen($expectedToken) < 32) {
    error_log('AD sync rejected: AD_SYNC_TOKEN is not configured with at least 32 characters.');
    ad_sync_respond(503, ['error' => 'AD sync is not configured.']);
}

if (!ad_sync_is_secure_transport()) {
    ad_sync_respond(400, ['error' => 'HTTPS is required for directory synchronization.']);
}

$providedHeader = ad_sync_authorization_header();
if (!preg_match('/^Bearer ([^\s]+)$/', $providedHeader, $matches) || !hash_equals($expectedToken, $matches[1])) {
    error_log('AD sync rejected: invalid bearer token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    ad_sync_respond(401, ['error' => 'A valid bearer token is required.']);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    ad_sync_respond(415, ['error' => 'Content-Type must be application/json.']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > AD_SYNC_MAX_BODY_BYTES) {
    ad_sync_respond(413, ['error' => 'Request body exceeds the 2 MiB limit.']);
}

$body = file_get_contents('php://input', false, null, 0, AD_SYNC_MAX_BODY_BYTES + 1);
if ($body === false || strlen($body) > AD_SYNC_MAX_BODY_BYTES) {
    ad_sync_respond(413, ['error' => 'Request body exceeds the 2 MiB limit.']);
}

try {
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    ad_sync_respond(400, ['error' => 'Request body contains malformed JSON.']);
}

if (!is_array($payload) || ($payload !== [] && array_keys($payload) === range(0, count($payload) - 1))) {
    ad_sync_respond(422, ['error' => 'JSON body must be an object containing computers.']);
}

require_once __DIR__ . '/backend/ad_sync.php';

try {
    $records = validate_ad_sync_payload($payload);
} catch (InvalidArgumentException $exception) {
    ad_sync_respond(422, ['error' => $exception->getMessage()]);
}

require __DIR__ . '/db.php';

$created = 0;
$updated = 0;
$failed = [];
$missingManufacturerSerials = [];
$serialCounts = [];

foreach ($records as $record) {
    $serial = $record['manufacturer_serial'];
    if ($serial !== null) {
        $serialKey = strtolower($serial);
        $serialCounts[$serialKey] = ($serialCounts[$serialKey] ?? 0) + 1;
    } else {
        $missingManufacturerSerials[] = $record['computer_name'];
    }
}

$duplicateManufacturerSerials = [];
foreach ($records as $record) {
    $serial = $record['manufacturer_serial'];
    if ($serial === null || isset($duplicateManufacturerSerials[$record['ad_guid']])) {
        continue;
    }

    $duplicateInPayload = ($serialCounts[strtolower($serial)] ?? 0) > 1;
    $check = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM laptops
        WHERE manufacturer_serial = ?
          AND (ad_object_guid IS NULL OR ad_object_guid <> ?)
    ");
    $check->bind_param('ss', $serial, $record['ad_guid']);
    $check->execute();
    $duplicateInDatabase = (int) $check->get_result()->fetch_assoc()['total'] > 0;

    if ($duplicateInPayload || $duplicateInDatabase) {
        $duplicateManufacturerSerials[$record['ad_guid']] = [
            'computer_name' => $record['computer_name'],
            'manufacturer_serial' => $serial,
        ];
    }
}

foreach ($records as $record) {
    try {
        $outcome = sync_ad_computer($conn, $record);
        if ($outcome['created']) {
            $created++;
        } else {
            $updated++;
        }
    } catch (Throwable $exception) {
        error_log(
            'AD sync failed for ' . $record['computer_name'] . ' (' . $record['ad_guid'] . '): '
            . $exception->getMessage()
        );
        $failed[] = [
            'computer_name' => $record['computer_name'],
            'message' => 'This computer could not be synchronized; check the server log.',
        ];
    }
}

$summary = [
    'created' => $created,
    'updated' => $updated,
    'failed' => count($failed),
    'missing_manufacturer_serials' => $missingManufacturerSerials,
    'duplicate_manufacturer_serials' => array_values($duplicateManufacturerSerials),
    'errors' => $failed,
];

try {
    log_ad_sync_event(
        $conn,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45),
        count($records),
        $summary
    );
} catch (Throwable $exception) {
    error_log('AD sync could not write its event log: ' . $exception->getMessage());
    ad_sync_respond(500, ['error' => 'Synchronization ran, but the event log could not be written. Retrying is safe.']);
}

$status = $failed ? 207 : 200;
ad_sync_respond($status, ['status' => $failed ? 'partial' : 'complete'] + $summary);

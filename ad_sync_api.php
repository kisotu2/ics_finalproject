<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/ad_sync_security.php';

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


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ad_sync_respond(405, ['error' => 'Only POST requests are accepted.']);
}

$expectedToken = getenv('AD_SYNC_TOKEN');
if ($expectedToken === false || strlen($expectedToken) < 32 || preg_match('/\s/', $expectedToken)) {
    error_log('AD sync rejected: AD_SYNC_TOKEN is not configured with at least 32 characters.');
    ad_sync_respond(503, ['error' => 'AD sync is not configured.']);
}

if (!ad_sync_is_secure_transport()) {
    ad_sync_respond(400, ['error' => 'HTTPS is required for directory synchronization.']);
}

$rateAllowed = ad_sync_rate_limit((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if ($rateAllowed === null) {
    ad_sync_respond(503, ['error' => 'Request throttling is temporarily unavailable.']);
}
if (!$rateAllowed) {
    header('Retry-After: 60');
    ad_sync_respond(429, ['error' => 'Request rate limit exceeded; retry after 60 seconds.']);
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
    [$records, $failed] = validate_ad_sync_batch($payload);
} catch (InvalidArgumentException $exception) {
    ad_sync_respond(422, ['error' => $exception->getMessage()]);
}


$runId = $payload['run_id'] ?? null;
$scopeId = $payload['scope_id'] ?? null;
$finalBatch = $payload['final_batch'] ?? false;
$fullScope = $payload['full_scope'] ?? false;
$requestId = $payload['request_id'] ?? null;
if (!is_string($runId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)
    || !is_string($scopeId) || trim($scopeId) === '' || strlen($scopeId) > 1024 || !is_bool($finalBatch) || !is_bool($fullScope)
    || ($requestId !== null && (!is_int($requestId) || $requestId < 1))) {
    ad_sync_respond(422, ['error' => 'run_id, scope_id, final_batch and optional request_id are invalid.']);
}
foreach ($records as &$record) { $record['scope_id'] = $fullScope ? $scopeId : null; }
unset($record);
if ($records === [] && ($payload['computers'] !== [] || !$finalBatch || !$fullScope || $failed !== [])) {
    ad_sync_respond(422, ['status' => 'failed', 'errors' => $failed]);
}

define('AD_SYNC_API_REQUEST', true);
require __DIR__ . '/db.php';
$schemaIssues = ad_sync_database_schema_issues($conn);
if ($schemaIssues !== []) {
    error_log('AD sync schema check failed: ' . implode(', ', $schemaIssues));
    ad_sync_respond(503, ['error' => 'AD sync database schema is incomplete or incompatible. Apply the migration and check the administrator dashboard.']);
}
try {
    $run = $conn->prepare("INSERT INTO ad_sync_runs (run_id, scope_id, status, started_at) VALUES (?, ?, 'running', UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE run_id = VALUES(run_id)");
    $run->bind_param('ss', $runId, $scopeId); $run->execute(); $run->close();
    $scopeCheck = $conn->prepare('SELECT scope_id FROM ad_sync_runs WHERE run_id = ?');
    $scopeCheck->bind_param('s', $runId); $scopeCheck->execute();
    if ((string)$scopeCheck->get_result()->fetch_assoc()['scope_id'] !== $scopeId) { ad_sync_respond(409, ['error' => 'Run identifier was already used for a different scope.']); }
    $scopeCheck->close();
    if ($requestId !== null) {
        $jobCheck=$conn->prepare("SELECT scope_id,status,run_id FROM ad_sync_requests WHERE id=?"); $jobCheck->bind_param('i',$requestId); $jobCheck->execute(); $job=$jobCheck->get_result()->fetch_assoc(); $jobCheck->close();
        if (!$job || $job['status']!=='running' || $job['scope_id']!==$scopeId || ($job['run_id']!==null && $job['run_id']!==$runId)) { ad_sync_respond(409,['error'=>'The sync request is not running for this scope.']); }
        $link=$conn->prepare("UPDATE ad_sync_requests SET run_id=? WHERE id=? AND status='running' AND (run_id IS NULL OR run_id=?)"); $link->bind_param('sis',$runId,$requestId,$runId); $link->execute(); $link->close();
    }
} catch (Throwable $exception) {
    error_log('AD sync run initialization failed: ' . $exception->getMessage());
    ad_sync_respond(503, ['error' => 'Synchronization run tracking is unavailable.']);
}

$created = 0;
$updated = 0;
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
        $seen = $conn->prepare('INSERT IGNORE INTO ad_sync_run_devices (run_id, ad_object_guid) VALUES (?, ?)');
        $seen->bind_param('ss', $runId, $record['ad_guid']); $seen->execute(); $seen->close();
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

$received = count($payload['computers']);
try {
    $batchFailed = count($failed);
    $aggregate = $conn->prepare('UPDATE ad_sync_runs SET received_count=received_count+?, created_count=created_count+?, updated_count=updated_count+?, failed_count=failed_count+? WHERE run_id=?');
    $aggregate->bind_param('iiiis', $received, $created, $updated, $batchFailed, $runId); $aggregate->execute(); $aggregate->close();
    if ($finalBatch) {
        $runTotals = $conn->prepare('SELECT failed_count, started_at, received_count, created_count, updated_count FROM ad_sync_runs WHERE run_id=?');
        $runTotals->bind_param('s', $runId); $runTotals->execute(); $totals = $runTotals->get_result()->fetch_assoc(); $runTotals->close();
        $runStatus = (int)$totals['failed_count'] === 0 ? 'successful' : 'partial';
        if ((int)$totals['failed_count'] === 0 && $fullScope) {
            $missing = $conn->prepare("UPDATE laptops l SET l.ad_missing_since=COALESCE(l.ad_missing_since, UTC_TIMESTAMP(6)), l.endpoint_status='unknown', l.endpoint_check_reason='Not found in the latest complete Active Directory scope.' WHERE l.inventory_source='active_directory' AND l.ad_scope_id=? AND (l.ad_last_seen_at IS NULL OR l.ad_last_seen_at < ?) AND NOT EXISTS (SELECT 1 FROM ad_sync_run_devices d WHERE d.run_id=? AND d.ad_object_guid=l.ad_object_guid)");
            $missing->bind_param('sss', $scopeId, $totals['started_at'], $runId); $missing->execute(); $missing->close();
        }
        $summaryJson = json_encode(['run_id'=>$runId,'status'=>$runStatus,'received'=>(int)$totals['received_count'],'created'=>(int)$totals['created_count'],'updated'=>(int)$totals['updated_count'],'failed'=>(int)$totals['failed_count']]);
        $finish = $conn->prepare('UPDATE ad_sync_runs SET status=?, completed_at=UTC_TIMESTAMP(6), summary=? WHERE run_id=?');
        $finish->bind_param('sss', $runStatus, $summaryJson, $runId); $finish->execute(); $finish->close();
        $agentDone = $conn->prepare('UPDATE ad_sync_agent_state SET last_sync_at=UTC_TIMESTAMP(6), last_error=NULL WHERE id=1'); $agentDone->execute(); $agentDone->close();
        if ($requestId !== null) {
            $request = $conn->prepare('UPDATE ad_sync_requests SET status=?, finished_at=UTC_TIMESTAMP(6), run_id=?, summary=? WHERE id=?');
            $request->bind_param('sssi', $runStatus, $runId, $summaryJson, $requestId); $request->execute(); $request->close();
        }
    }
} catch (Throwable $exception) {
    error_log('AD run aggregation failed: ' . $exception->getMessage());
    ad_sync_respond(500, ['error' => 'Records were processed but run status could not be updated.']);
}
$summary = [
    'total_received' => $received,
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
        $received,
        $summary
    );
} catch (Throwable $exception) {
    error_log('AD sync could not write its event log: ' . $exception->getMessage());
    ad_sync_respond(500, ['error' => 'Synchronization ran, but the event log could not be written. Retrying is safe.']);
}

$status = $failed ? 207 : 200;
ad_sync_respond($status, ['status' => $failed ? 'partial' : 'complete', 'run_id' => $runId, 'final_batch' => $finalBatch] + $summary);

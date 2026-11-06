<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

require_once __DIR__ . '/backend/ad_sync_security.php';

$expectedColumns = [
    'asset_tag' => 'varchar(80)',
    'serial_number' => 'varchar(120)',
    'manufacturer_serial' => 'varchar(120)',
    'ad_object_guid' => 'char(36)',
    'ad_computer_name' => 'varchar(63)',
    'ad_distinguished_name' => 'varchar(1024)',
    'ad_enabled' => 'tinyint(1)',
    'ad_operating_system' => 'varchar(255)',
    'ad_operating_system_version' => 'varchar(100)',
    'ad_last_synced_at' => 'datetime(6)',
    'brand' => 'varchar(100)',
    'model' => 'varchar(120)',
    'processor_description' => 'varchar(255)',
    'ram_gb' => 'tinyint unsigned',
    'storage_gb' => 'smallint unsigned',
    'status' => "enum('available','assigned','maintenance','retired','disposed')",
    'purchase_date' => 'date',
    'warranty_expiry' => 'date',
    'ad_dns_hostname' => 'varchar(255)', 'ad_organizational_unit' => 'varchar(1024)',
    'ad_last_logon_at' => 'datetime(6)', 'ad_account_created_at' => 'datetime(6)',
    'ad_account_modified_at' => 'datetime(6)', 'endpoint_status' => "enum('online','offline','unreachable','unknown')",
    'endpoint_checked_at' => 'datetime(6)', 'last_successful_connectivity_at' => 'datetime(6)',
    'hardware_last_scanned_at' => 'datetime(6)', 'endpoint_check_reason' => 'varchar(500)',
    'ad_sync_error' => 'varchar(500)', 'inventory_source' => "enum('manual','active_directory','other')",
    'ad_scope_id' => 'varchar(1024)', 'ad_last_seen_at' => 'datetime(6)', 'ad_missing_since' => 'datetime(6)',
];
$schemaCheck = $conn->prepare("
    SELECT column_name, column_type
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'laptops'
");
$schemaCheck->execute();
$actualColumns = [];
foreach ($schemaCheck->get_result() as $column) {
    $actualColumns[$column['column_name']] = strtolower((string) $column['column_type']);
}
$schemaProblems = [];
foreach ($expectedColumns as $name => $type) {
    if (!isset($actualColumns[$name])) {
        $schemaProblems[] = 'laptops.' . $name . ' is missing';
    } elseif ($actualColumns[$name] !== $type) {
        $schemaProblems[] = 'laptops.' . $name . ' has an incompatible type';
    }
}
$serialIndex = $conn->query("
    SELECT COUNT(DISTINCT s.index_name) AS total
    FROM information_schema.statistics s
    WHERE s.table_schema = DATABASE() AND s.table_name = 'laptops'
      AND s.column_name = 'serial_number' AND s.seq_in_index = 1 AND s.non_unique = 0
      AND (SELECT COUNT(*) FROM information_schema.statistics s2
           WHERE s2.table_schema = s.table_schema AND s2.table_name = s.table_name
             AND s2.index_name = s.index_name) = 1
")->fetch_assoc();
if ((int) $serialIndex['total'] === 0) {
    $schemaProblems[] = 'laptops.serial_number needs a unique index';
}
$identityIndex = $conn->query("
    SELECT COUNT(DISTINCT s.index_name) AS total
    FROM information_schema.statistics s
    WHERE s.table_schema = DATABASE() AND s.table_name = 'laptops'
      AND s.column_name = 'ad_object_guid' AND s.seq_in_index = 1 AND s.non_unique = 0
      AND (SELECT COUNT(*) FROM information_schema.statistics s2
           WHERE s2.table_schema = s.table_schema AND s2.table_name = s.table_name
             AND s2.index_name = s.index_name) = 1
")->fetch_assoc();
if ((int) $identityIndex['total'] === 0) {
    $schemaProblems[] = 'laptops.ad_object_guid needs a unique index';
}
$schemaReady = $schemaProblems === [];

$requiredEventColumns = [
    'id' => 'bigint', 'source_ip' => 'varchar(45)', 'received_count' => 'int unsigned',
    'created_count' => 'int unsigned', 'updated_count' => 'int unsigned',
    'failed_count' => 'int unsigned', 'summary' => 'longtext', 'created_at' => 'timestamp',
];
$eventCheck = $conn->prepare("
    SELECT column_name, column_type
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events'
");
$eventCheck->execute();
$actualEventColumns = [];
foreach ($eventCheck->get_result() as $column) {
    $actualEventColumns[$column['column_name']] = strtolower((string) $column['column_type']);
}
$eventProblems = [];
foreach ($requiredEventColumns as $name => $type) {
    if (!isset($actualEventColumns[$name])) {
        $eventProblems[] = 'ad_sync_events.' . $name . ' is missing';
    } elseif (($name === 'summary' && !in_array($actualEventColumns[$name], ['json', 'longtext'], true))
        || ($name !== 'summary' && $actualEventColumns[$name] !== $type)) {
        $eventProblems[] = 'ad_sync_events.' . $name . ' has an incompatible type';
    }
}
$primaryKeyCheck = $conn->query("
    SELECT COUNT(*) AS total FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events'
      AND constraint_name = 'PRIMARY' AND column_name = 'id' AND ordinal_position = 1
")->fetch_assoc();
if ((int) $primaryKeyCheck['total'] === 0) {
    $eventProblems[] = 'ad_sync_events.id must be the primary key';
}
$eventIndexCheck = $conn->query("
    SELECT COUNT(*) AS total FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ad_sync_events'
      AND column_name = 'created_at' AND seq_in_index = 1
")->fetch_assoc();
if ((int) $eventIndexCheck['total'] === 0) {
    $eventProblems[] = 'ad_sync_events needs the created_at history index';
}
$schemaProblems = array_merge($schemaProblems, $eventProblems);
$controlTables = (int)$conn->query("SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ad_sync_runs','ad_sync_run_devices','ad_sync_requests','ad_sync_agent_state')")->fetch_assoc()['total'];
if ($controlTables !== 4) $schemaProblems[] = 'AD run tracking/request tables are missing; apply the endpoint monitoring migration';
$schemaReady = $schemaReady && $eventProblems === [] && $controlTables === 4;

$requestMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_sync') {
    verify_csrf();
    if (!$schemaReady) { http_response_code(503); $requestMessage = 'Apply the AD monitoring migration before requesting a sync.'; }
    else {
        $scope = trim((string)($_POST['scope_id'] ?? ''));
        if ($scope === '' || mb_strlen($scope, 'UTF-8') > 1024 || preg_match('/[\r\n\x00]/', $scope)) { http_response_code(422); $requestMessage = 'Enter a valid distinguished name search base.'; }
        else {
            $requestedBy = (int) current_user()['id'];
            $request = $conn->prepare("INSERT INTO ad_sync_requests(requested_by,scope_id) VALUES(?,?)");
            $request->bind_param('is', $requestedBy, $scope); $request->execute();
            $newRequestId = (int)$conn->insert_id;
            header('Location: directory_sync.php?request_id=' . $newRequestId); exit;
        }
    }
}

$token = getenv('AD_SYNC_TOKEN');
$tokenConfigured = $token !== false && strlen($token) >= 32;
$httpsDetected = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
$localHttpAllowed = getenv('AD_SYNC_ALLOW_INSECURE_LOCAL') === '1'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$trustedProxyHttps = !$httpsDetected && !$localHttpAllowed && ad_sync_is_secure_transport();
$transportReady = $httpsDetected || $localHttpAllowed || $trustedProxyHttps;
$historyPage = max(1, (int) ($_GET['history_page'] ?? 1));
$historyPageSize = 10;
$history = [];
$historyTotal = 0;
$latestEvent = null;
if ($schemaReady) {
    $historyTotal = (int) $conn->query('SELECT COUNT(*) AS total FROM ad_sync_events')->fetch_assoc()['total'];
    $historyPage = min($historyPage, max(1, (int) ceil($historyTotal / $historyPageSize)));
    $latestEvent = $conn->query("
        SELECT id, created_at, source_ip, received_count, created_count, updated_count, failed_count, summary
        FROM ad_sync_events ORDER BY id DESC LIMIT 1
    ")->fetch_assoc() ?: null;
    $historyStmt = $conn->prepare("
        SELECT id, created_at, source_ip, received_count, created_count, updated_count, failed_count, summary
        FROM ad_sync_events ORDER BY id DESC LIMIT ? OFFSET ?
    ");
    $offset = ($historyPage - 1) * $historyPageSize;
    $historyStmt->bind_param('ii', $historyPageSize, $offset);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function ad_sync_event_state(array $event): string
{
    if ((int) $event['failed_count'] === 0) {
        return 'Successful';
    }
    if ((int) $event['failed_count'] >= (int) $event['received_count']) {
        return 'Failed';
    }
    return 'Completed with errors';
}

function ad_sync_event_errors(array $event): array
{
    $summary = json_decode((string) ($event['summary'] ?? ''), true);
    return is_array($summary) && is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
}

$assetStats = [];
$deviceRows = [];
$devicePage = max(1, (int)($_GET['device_page'] ?? 1)); $deviceLimit = 20;
$filterEndpoint = in_array($_GET['endpoint'] ?? '', ['online','offline','unreachable','unknown'], true) ? (string)$_GET['endpoint'] : '';
$filterAccount = in_array($_GET['account'] ?? '', ['enabled','disabled'], true) ? (string)$_GET['account'] : '';
$filterIssue = in_array($_GET['issue'] ?? '', ['errors','missing'], true) ? (string)$_GET['issue'] : '';
$filterOu = substr(trim((string)($_GET['ou'] ?? '')),0,200); $filterOs = substr(trim((string)($_GET['os'] ?? '')),0,200); $filterManufacturer = substr(trim((string)($_GET['manufacturer'] ?? '')),0,100);
$latestRun = null; $lastSuccessfulRun = null; $agentState = null; $queuedRequest = null;
if ($schemaReady) {
    $stat = $conn->query("SELECT COUNT(*) total, SUM(ad_enabled=1) enabled_count, SUM(ad_enabled=0) disabled_count, SUM(endpoint_status='online') online_count, SUM(endpoint_status='offline') offline_count, SUM(endpoint_status='unreachable') unreachable_count, SUM(ad_missing_since IS NOT NULL) missing_count, SUM(manufacturer_serial IS NULL OR processor_description IS NULL OR ram_gb IS NULL OR storage_gb IS NULL OR brand='Not reported' OR model='Not reported') hardware_missing FROM laptops WHERE inventory_source='active_directory'")->fetch_assoc(); $assetStats = $stat ?: [];
    $latestRun = $conn->query('SELECT run_id,status,started_at,completed_at,received_count,created_count,updated_count,failed_count FROM ad_sync_runs ORDER BY started_at DESC LIMIT 1')->fetch_assoc() ?: null;
    $lastSuccessfulRun = $conn->query("SELECT completed_at FROM ad_sync_runs WHERE status='successful' ORDER BY completed_at DESC LIMIT 1")->fetch_assoc() ?: null;
    $agentState = $conn->query('SELECT last_poll_at,last_sync_at,last_error FROM ad_sync_agent_state WHERE id=1')->fetch_assoc() ?: null;
    $queuedRequest = $conn->query("SELECT id,status,scope_id,requested_at FROM ad_sync_requests WHERE status IN ('queued','running') ORDER BY requested_at DESC LIMIT 1")->fetch_assoc() ?: null;
    $requestStatusId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT) ?: (int)($queuedRequest['id'] ?? 0);
    if ($requestStatusId > 0 && isset($_GET['request_id'])) { $requestLookup=$conn->prepare('SELECT id,status,scope_id,requested_at FROM ad_sync_requests WHERE id=?'); $requestLookup->bind_param('i',$requestStatusId); $requestLookup->execute(); $queuedRequest=$requestLookup->get_result()->fetch_assoc() ?: $queuedRequest; $requestLookup->close(); }
    $where = "inventory_source='active_directory' AND (?='' OR endpoint_status=?) AND (?='' OR (ad_enabled=1 AND ?='enabled') OR (ad_enabled=0 AND ?='disabled')) AND (?='' OR ad_organizational_unit LIKE ?) AND (?='' OR ad_operating_system LIKE ?) AND (?='' OR brand LIKE ?) AND (?='' OR (?='errors' AND (ad_sync_error IS NOT NULL OR endpoint_check_reason IS NOT NULL)) OR (?='missing' AND ad_missing_since IS NOT NULL))";
    $likeOu=$filterOu===''?'':'%'.$filterOu.'%';$likeOs=$filterOs===''?'':'%'.$filterOs.'%';$likeManufacturer=$filterManufacturer===''?'':'%'.$filterManufacturer.'%';
    $countStmt=$conn->prepare('SELECT COUNT(*) total FROM laptops WHERE '.$where);$countStmt->bind_param('ssssssssssssss',$filterEndpoint,$filterEndpoint,$filterAccount,$filterAccount,$filterAccount,$filterOu,$likeOu,$filterOs,$likeOs,$filterManufacturer,$likeManufacturer,$filterIssue,$filterIssue,$filterIssue);$countStmt->execute();$deviceTotal=(int)$countStmt->get_result()->fetch_assoc()['total'];
    $devicePage=min($devicePage,max(1,(int)ceil($deviceTotal/$deviceLimit)));$offset=($devicePage-1)*$deviceLimit;
    $deviceStmt=$conn->prepare('SELECT id,ad_computer_name,ad_enabled,endpoint_status,brand,model,manufacturer_serial,ad_operating_system,ad_last_logon_at,endpoint_checked_at,hardware_last_scanned_at,ad_last_synced_at,ad_organizational_unit,ad_sync_error,ad_missing_since,endpoint_check_reason FROM laptops WHERE '.$where.' ORDER BY ad_computer_name LIMIT ? OFFSET ?');
    $deviceStmt->bind_param('ssssssssssssssii',$filterEndpoint,$filterEndpoint,$filterAccount,$filterAccount,$filterAccount,$filterOu,$likeOu,$filterOs,$likeOs,$filterManufacturer,$likeManufacturer,$filterIssue,$filterIssue,$filterIssue,$deviceLimit,$offset);$deviceStmt->execute();$deviceRows=$deviceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$endpointPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/')
    . '/ad_sync_api.php';
layout_start('Directory sync');
?>
<style>
.directory-sync .hero {
    align-items: flex-start;
}

.directory-sync .hero h1 {
    margin: 0;
}

.directory-sync .hero p {
    max-width: 640px;
}

.directory-sync .setup-list {
    margin: 0;
    padding-left: 22px;
}

.directory-sync .setup-list li {
    padding: 0 0 15px 5px;
}

.directory-sync .setup-list li:last-child {
    padding-bottom: 0;
}

.directory-sync .setup-list strong {
    display: block;
    margin-bottom: 3px;
}

.directory-sync .setup-list p {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
}

.directory-sync code {
    display: inline-block;
    padding: 3px 7px;
    border-radius: 5px;
    background: #f1f3ed;
    color: #46513e;
    font-size: 11px;
    overflow-wrap: anywhere;
}

.directory-sync .sync-status {
    margin: 0;
    padding: 13px 15px;
    border-left: 3px solid var(--warning);
    border-radius: 8px;
    background: #fbf7eb;
}

.directory-sync .sync-status.ready {
    border-left-color: var(--success);
    background: #eff7f0;
}

.directory-sync .sync-status.failed {
    border-left-color: #b42318;
    background: #fff1f0;
}

.directory-sync .status-chip {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: #f1f3ed;
}

.directory-sync .event-errors { margin: 8px 0 0; padding-left: 18px; }
.directory-sync .event-errors li + li { margin-top: 4px; }
.directory-sync table { width: 100%; border-collapse: collapse; }
.directory-sync th, .directory-sync td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #e8eae4; vertical-align: top; }
.directory-sync th { color: var(--muted); font-size: 11px; }
.directory-sync .history-pager { display:flex; gap:12px; justify-content:flex-end; padding-top:12px; }

.directory-sync .sync-status strong {
    display: block;
    margin-bottom: 3px;
}

.directory-sync .sync-status span {
    color: var(--muted);
    font-size: 12px;
}

.directory-sync .limitations {
    padding-left: 20px;
    color: var(--muted);
}

.directory-sync .limitations li + li {
    margin-top: 8px;
}
</style>

<div class="directory-sync">
    <section class="hero">
        <div>
            <div class="eyebrow">INVENTORY SOURCE</div>
            <h1>Active Directory sync</h1>
            <p>Computer identity comes from Active Directory. Hardware details are collected separately from reachable Windows endpoints through the authorized PowerShell connector.</p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Server readiness</h2>
                <span>Readiness checks do not imply that a connector has successfully synchronized.</span>
            </div>
        </div>
        <p class="sync-status <?= $schemaReady && $tokenConfigured && $transportReady ? 'ready' : 'failed' ?>">
            <strong><?= $schemaReady && $tokenConfigured && $transportReady ? 'Configuration ready' : 'Configuration incomplete' ?></strong>
            <span>Database schema: <?= $schemaReady ? 'ready' : 'incomplete' ?> · Server token: <?= $tokenConfigured ? 'configured' : 'missing or too short' ?> · Transport: <?= $httpsDetected ? 'HTTPS' : ($trustedProxyHttps ? 'HTTPS through trusted proxy' : ($localHttpAllowed ? 'development-only localhost HTTP' : 'HTTPS not detected')) ?></span>
        </p>
        <?php if ($schemaProblems): ?>
            <ul class="limitations"><?php foreach ($schemaProblems as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <p class="muted" style="margin:10px 0 0;font-size:12px">Apache/firewall must also restrict this endpoint to approved connector addresses. See the troubleshooting and Apache instructions below.</p>
        <p style="margin:16px 0 0"><strong>Endpoint path</strong><br><code><?= e($endpointPath) ?></code></p>
        <p style="margin:12px 0 0"><strong>Most recent synchronization</strong><br>
            <?php if ($latestEvent): ?>
                <span class="status-chip"><?= e(ad_sync_event_state($latestEvent)) ?></span><br>
                <?= e($latestEvent['created_at']) ?> · <?= (int) $latestEvent['received_count'] ?> received,
                <?= (int) $latestEvent['created_count'] ?> created, <?= (int) $latestEvent['updated_count'] ?> updated,
                <?= (int) $latestEvent['failed_count'] ?> failed
            <?php else: ?>No synchronization history is available.<?php endif; ?>
        </p>
    </section>

    <?php if ($requestMessage): ?><p class="sync-status"><?= e($requestMessage) ?></p><?php endif; ?>
    <section class="panel">
      <div class="panel-header"><div><h2>Automated device inventory</h2><span>AD account state and endpoint connectivity are separate measurements.</span></div></div>
      <?php if ($schemaReady): ?>
        <p><strong><?= (int)($assetStats['total']??0) ?></strong> computers · <?= (int)($assetStats['enabled_count']??0) ?> enabled · <?= (int)($assetStats['disabled_count']??0) ?> disabled · <?= (int)($assetStats['online_count']??0) ?> online · <?= (int)($assetStats['offline_count']??0) ?> offline · <?= (int)($assetStats['unreachable_count']??0) ?> unreachable · <?= (int)($assetStats['hardware_missing']??0) ?> with incomplete hardware · <?= (int)($assetStats['missing_count']??0) ?> missing from scope</p>
        <p>Latest run: <?= e($latestRun['completed_at'] ?? ($latestRun['started_at'] ?? 'Not yet synchronized')) ?><?= $latestRun ? ' · '.e($latestRun['status']).' · '.(int)$latestRun['created_count'].' new · '.(int)$latestRun['failed_count'].' failed' : '' ?> · Last successful sync: <?= e($lastSuccessfulRun['completed_at'] ?? 'None yet') ?> · Agent last poll: <?= e($agentState['last_poll_at'] ?? 'No agent check-in') ?> · Next scheduled run: approximately 15 minutes after the agent’s previous scheduled run.</p>
        <?php if (!empty($agentState['last_error'])): ?><p class="sync-status failed"><?= e($agentState['last_error']) ?></p><?php endif; ?>
        <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="request_sync"><label>AD search base (OU or domain DN)<input name="scope_id" required maxlength="1024" placeholder="OU=Computers,DC=example,DC=com"></label><button class="button" type="submit">Synchronise Now</button><?php if ($queuedRequest): ?><span id="sync-request-status" data-request-id="<?= (int)$requestStatusId ?>">Request #<?= (int)$queuedRequest['id'] ?> <?= e($queuedRequest['status']) ?> · scope <?= e($queuedRequest['scope_id']) ?></span><?php elseif (!empty($requestStatusId)): ?><span id="sync-request-status" data-request-id="<?= (int)$requestStatusId ?>">Checking request status…</span><?php endif; ?></form>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px"><select name="endpoint"><option value="">Any endpoint state</option><?php foreach(['online','offline','unreachable','unknown'] as $x): ?><option <?= $filterEndpoint===$x?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?></select><select name="account"><option value="">Any AD account</option><option value="enabled" <?= $filterAccount==='enabled'?'selected':'' ?>>Enabled</option><option value="disabled" <?= $filterAccount==='disabled'?'selected':'' ?>>Disabled</option></select><input name="ou" placeholder="OU contains" value="<?= e($filterOu) ?>"><input name="os" placeholder="OS contains" value="<?= e($filterOs) ?>"><input name="manufacturer" placeholder="Manufacturer contains" value="<?= e($filterManufacturer) ?>"><select name="issue"><option value="">Any sync status</option><option value="errors" <?= $filterIssue==='errors'?'selected':'' ?>>Sync errors</option><option value="missing" <?= $filterIssue==='missing'?'selected':'' ?>>Missing from AD</option></select><button class="button secondary">Filter</button></form>
        <div style="overflow-x:auto"><table><thead><tr><th>Computer</th><th>AD</th><th>Endpoint</th><th>Hardware</th><th>OS</th><th>AD last logon</th><th>Last check</th><th>Hardware scan</th><th>Last sync</th><th>OU / issue</th></tr></thead><tbody><?php foreach($deviceRows as $d): ?><tr><td><?= e($d['ad_computer_name']) ?></td><td><?= (int)$d['ad_enabled']?'Enabled':'Disabled' ?></td><td><?= e($d['endpoint_status']) ?></td><td><?= e(trim($d['brand'].' '.$d['model'])) ?> · <?= e($d['manufacturer_serial']?:'No BIOS serial') ?></td><td><?= e($d['ad_operating_system']?:'Unknown') ?></td><td><?= e($d['ad_last_logon_at']?:'Unknown') ?></td><td><?= e($d['endpoint_checked_at']?:'Not checked') ?></td><td><?= e($d['hardware_last_scanned_at']?:'Not scanned') ?></td><td><?= e($d['ad_last_synced_at']?:'Not synced') ?></td><td><?= e($d['ad_organizational_unit']?:'—') ?><?= $d['ad_missing_since']?' · Missing since '.e($d['ad_missing_since']):'' ?><?= $d['ad_sync_error']?' · '.e($d['ad_sync_error']):($d['endpoint_check_reason']?' · '.e($d['endpoint_check_reason']):'') ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php if (($devicePages ?? 1) > 1): ?><nav class="history-pager"><?php if($devicePage>1): ?><a href="?<?= e(http_build_query(array_merge($_GET,['device_page'=>$devicePage-1]))) ?>">← Previous</a><?php endif; ?><span>Page <?= (int)$devicePage ?> of <?= (int)$devicePages ?> · <?= (int)$deviceTotal ?> computers</span><?php if($devicePage<$devicePages): ?><a href="?<?= e(http_build_query(array_merge($_GET,['device_page'=>$devicePage+1]))) ?>">Next →</a><?php endif; ?></nav><?php endif; ?>
      <?php else: ?><p class="sync-status failed">Apply the monitoring migration to enable live AD inventory and manual request queue.</p><?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-header"><div><h2>Synchronization history</h2><span><?= (int) $historyTotal ?> recorded batches</span></div></div>
        <?php if (!$schemaReady): ?>
            <p class="sync-status failed"><strong>History is unavailable</strong><span>Apply the migration and correct the schema issues listed above.</span></p>
        <?php elseif (!$history): ?>
            <p class="muted">No synchronization has been logged yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto"><table><thead><tr><th>Date and time</th><th>Connector peer</th><th>Status</th><th>Received</th><th>Created</th><th>Updated</th><th>Failed</th><th>Failure summary</th></tr></thead><tbody>
            <?php foreach ($history as $event): $errors = ad_sync_event_errors($event); ?>
                <tr><td><?= e($event['created_at']) ?></td><td><?= e($event['source_ip']) ?></td><td><?= e(ad_sync_event_state($event)) ?></td><td><?= (int) $event['received_count'] ?></td><td><?= (int) $event['created_count'] ?></td><td><?= (int) $event['updated_count'] ?></td><td><?= (int) $event['failed_count'] ?></td><td>
                    <?php if ($errors): ?><details><summary><?= count($errors) ?> issue(s)</summary><ul class="event-errors"><?php foreach ($errors as $error): ?><li><strong><?= e((string) ($error['computer_name'] ?? 'Computer')) ?>:</strong> <?= e((string) ($error['message'] ?? 'Synchronization failed.')) ?></li><?php endforeach; ?></ul></details><?php else: ?>—<?php endif; ?>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php $historyPages = max(1, (int) ceil($historyTotal / $historyPageSize)); ?>
            <div class="history-pager">
                <?php if ($historyPage > 1): ?><a href="?history_page=<?= $historyPage - 1 ?>">← Newer</a><?php endif; ?>
                <span>Page <?= $historyPage ?> of <?= $historyPages ?></span>
                <?php if ($historyPage < $historyPages): ?><a href="?history_page=<?= $historyPage + 1 ?>">Older →</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Connector setup</h2>
                <span>Run these steps on an authorized, domain-joined Windows host.</span>
            </div>
        </div>
        <ol class="setup-list">
            <li>
                <strong>Back up the database and apply the migration</strong>
                <p>Apply <code>migrations/20261026_active_directory_inventory.sql</code> and <code>migrations/20261106_ad_endpoint_monitoring.sql</code> to the database configured for this application.</p>
            </li>
            <li>
                <strong>Configure the server token</strong>
                <p>Set a random token of at least 32 characters in the PHP/Apache environment as <code>AD_SYNC_TOKEN</code>. Do not place it in this project, URL parameters, or the PowerShell script.</p>
            </li>
            <li>
                <strong>Restrict access and enable HTTPS</strong>
                <p>Allow only the connector host's address at Apache/firewall level and serve this endpoint over HTTPS. Local HTTP is only accepted with the explicit development-only <code>AD_SYNC_ALLOW_INSECURE_LOCAL=1</code> setting.</p>
            </li>
            <li>
                <strong>Preview, then send</strong>
                <p>Install the AD PowerShell module on an authorized domain joined Windows host, then use <code>scripts/Run-ActiveDirectoryInventoryService.ps1</code> for queue polling and automatic 15-minute syncs. Register it at startup with <code>scripts/Register-ActiveDirectoryInventoryTask.ps1</code>. The regular job runs every 15 minutes and manual requests are claimed by the same service. For a Mac with an AD computer object, <a href="scripts/Send-ThisMacInventory.ps1" download>download the macOS PowerShell sender</a>; provide the device's AD identity and HTTPS endpoint when running it. <code>scripts/Send-ThisLaptopInventory.ps1</code> is for Windows.</p>
            </li>
        </ol>
    </section>

    <section class="panel">
        <h2>Troubleshooting</h2>
        <ul class="limitations">
            <li><strong>Database schema incomplete:</strong> back up the configured database, apply the migration once, then reload this page. Review the exact missing fields shown above.</li>
            <li><strong>HTTPS not detected:</strong> configure TLS in the Apache VirtualHost. For a reverse proxy, set <code>AD_SYNC_TRUSTED_PROXY_IPS</code> to the immediate proxy IPs and forward <code>X-Forwarded-Proto: https</code>; arbitrary clients are not trusted.</li>
            <li><strong>Token missing or too short:</strong> configure a randomly generated 32-character or longer token in the PHP/Apache process environment and restart Apache. Rotate any token exposed during troubleshooting.</li>
            <li><strong>HTTP 401:</strong> check that the connector process received the same secret using its protected environment. Never put it in a URL or script.</li>
            <li><strong>HTTP 429:</strong> the server allows 30 authenticated requests per connector peer per minute; wait and retry.</li>
            <li><strong>HTTP 207:</strong> some records completed and others failed; inspect the batch response or failure summaries above.</li>
        </ul>
    </section>

    <section class="panel">
        <h2>Collection boundaries</h2>
        <ul class="limitations">
            <li>Active Directory supplies computer identity and directory metadata; it generally does not contain current RAM, BIOS serial, processor, or storage data.</li>
            <li>Hardware collection requires the endpoint to be online and the connector account to have approved remote CIM/WinRM access. Firewall rules, remoting policy, and least-privilege permissions must be configured by the domain administrator.</li>
            <li>Offline or unreachable endpoints are reported by the connector and omitted from that run; their existing inventory rows are not deleted.</li>
            <li>Manufacturer serials are retained separately from generated inventory serials. Missing or duplicate manufacturer serials are reported, not fabricated.</li>
            <li>AD enabled state is stored as directory metadata; it does not automatically retire an asset or change its assignment.</li>
        </ul>
    </section>
</div>
<?php layout_end(); ?>

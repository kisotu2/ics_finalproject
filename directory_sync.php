<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

$requiredColumns = [
    'manufacturer_serial',
    'ad_object_guid',
    'ad_computer_name',
    'ad_distinguished_name',
    'ad_enabled',
    'ad_operating_system',
    'ad_operating_system_version',
    'ad_last_synced_at',
    'processor_description',
];
$columnList = implode(',', array_fill(0, count($requiredColumns), '?'));
$columnTypes = str_repeat('s', count($requiredColumns));
$schemaCheck = $conn->prepare("
    SELECT COUNT(DISTINCT column_name) AS total
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'laptops'
      AND column_name IN ($columnList)
");
$schemaCheck->bind_param($columnTypes, ...$requiredColumns);
$schemaCheck->execute();
$schemaReady = (int) $schemaCheck->get_result()->fetch_assoc()['total'] === count($requiredColumns);

$eventTableCheck = $conn->query("
    SELECT COUNT(*) AS total
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'ad_sync_events'
");
$schemaReady = $schemaReady && (int) $eventTableCheck->fetch_assoc()['total'] === 1;
$token = getenv('AD_SYNC_TOKEN');
$tokenConfigured = $token !== false && strlen($token) >= 32;
$httpsDetected = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
$localHttpAllowed = getenv('AD_SYNC_ALLOW_INSECURE_LOCAL') === '1'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$transportReady = $httpsDetected || $localHttpAllowed;
$latestEvent = null;

if ($schemaReady) {
    $latestEvent = $conn->query("
        SELECT created_at, received_count, created_count, updated_count, failed_count
        FROM ad_sync_events
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc() ?: null;
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
                <h2>Connection status</h2>
                <span>Secrets are checked locally and never displayed here.</span>
            </div>
        </div>
        <p class="sync-status <?= $schemaReady && $tokenConfigured ? 'ready' : '' ?>">
            <strong><?= $schemaReady && $tokenConfigured && $transportReady ? 'Server prerequisites look ready' : 'Setup is required' ?></strong>
            <span>Database migration: <?= $schemaReady ? 'applied' : 'not detected' ?> · Server token: <?= $tokenConfigured ? 'configured' : 'missing or too short' ?> · Transport: <?= $httpsDetected ? 'HTTPS' : ($localHttpAllowed ? 'development-only localhost HTTP' : 'HTTPS not detected') ?></span>
        </p>
        <p class="muted" style="margin:10px 0 0;font-size:12px">Apache/firewall source-address restrictions must also be configured; that network policy is not inferred by this page.</p>
        <p style="margin:16px 0 0">
            <strong>Endpoint path</strong><br>
            <code><?= e($endpointPath) ?></code>
        </p>
        <p style="margin:12px 0 0">
            <strong>Latest sync</strong><br>
            <?php if ($latestEvent): ?>
                <?= e($latestEvent['created_at']) ?> ·
                <?= (int) $latestEvent['received_count'] ?> received,
                <?= (int) $latestEvent['created_count'] ?> created,
                <?= (int) $latestEvent['updated_count'] ?> updated,
                <?= (int) $latestEvent['failed_count'] ?> failed
            <?php else: ?>
                No completed sync has been logged.
            <?php endif; ?>
        </p>
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
                <p>Apply <code>migrations/20261026_active_directory_inventory.sql</code> to the database configured for this application.</p>
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
                <p>Use <code>scripts/Sync-ActiveDirectoryInventory.ps1</code> for an administrator-managed OU preview and batch send. For a Mac with an AD computer object, <a href="scripts/Send-ThisMacInventory.ps1" download>download the macOS PowerShell sender</a>; provide the device's AD identity and HTTPS endpoint when running it. <code>scripts/Send-ThisLaptopInventory.ps1</code> is for Windows.</p>
            </li>
        </ol>
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

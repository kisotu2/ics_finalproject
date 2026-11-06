<?php
declare(strict_types=1);

function ad_sync_trusted_proxy_ips(): array
{
    $configured = getenv('AD_SYNC_TRUSTED_PROXY_IPS');
    if ($configured === false || trim($configured) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/[;,\\s]+/', $configured) ?: [])));
}

function ad_sync_is_secure_transport(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (getenv('AD_SYNC_ALLOW_INSECURE_LOCAL') === '1'
        && in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
        return true;
    }

    if (!in_array($remoteAddress, ad_sync_trusted_proxy_ips(), true)) {
        return false;
    }

    // Forwarded scheme is trusted only when the immediate peer is explicitly configured above.
    return strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function ad_sync_rate_limit(string $remoteAddress, int $limit = 30, int $windowSeconds = 60): ?bool
{
    $rateDirectory = getenv('AD_SYNC_RATE_LIMIT_DIR') ?: sys_get_temp_dir();
    if (!is_dir($rateDirectory) && !@mkdir($rateDirectory, 0700, true) && !is_dir($rateDirectory)) {
        return null;
    }
    $path = rtrim($rateDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'asset-ad-sync-rate-' . hash('sha256', $remoteAddress) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return null;
    }

    $locked = false;
    try {
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX)) {
            return null;
        }
        $locked = true;

        rewind($handle);
        $contents = stream_get_contents($handle);
        $timestamps = json_decode($contents === false ? '' : $contents, true);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        $now = time();
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - $windowSeconds
        ));
        if (count($timestamps) >= $limit) {
            return false;
        }

        $timestamps[] = $now;
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, json_encode($timestamps, JSON_THROW_ON_ERROR)) === false) {
            return null;
        }
        fflush($handle);
        return true;
    } finally {
        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

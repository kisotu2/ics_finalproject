<?php

$localConfig = __DIR__ . '/google_config.local.php';

if (is_file($localConfig)) {
    return require $localConfig;
}

return [
    'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    'redirect_uri' => 'http://localhost/ics_finalproject/google-login.php',
];

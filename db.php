<?php
/** Database connection */

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : [];

$host = getenv('ASSET_DB_HOST') ?: ($config['db_host'] ?? '127.0.0.1');
$user = getenv('ASSET_DB_USER') ?: ($config['db_user'] ?? 'root');
$pass = getenv('ASSET_DB_PASSWORD') ?: ($config['db_password'] ?? '');
$name = getenv('ASSET_DB_NAME') ?: ($config['db_name'] ?? 'ira_assets');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $name);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    http_response_code(500);

    // Development: show the actual database error
    exit('Database connection failed: ' . $exception->getMessage());
}
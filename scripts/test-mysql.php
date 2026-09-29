<?php
/**
 * Sets up a dedicated MySQL testing database and runs the test suite against it.
 *
 * Usage (PowerShell):
 *   php scripts\test-mysql.php
 *
 * Safety: this NEVER touches your production database. It connects to the MySQL
 * server as the configured user, creates a schema named DB_TEST_DATABASE
 * (default: masahati_testing), and runs migrations only against that schema.
 */
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$testDb = getenv('DB_TEST_DATABASE') ?: 'masahati_testing';

// Refuse to run if someone points this at the production database name.
$prodDb = getenv('DB_DATABASE') ?: '';
if ($testDb === $prodDb && $prodDb !== '') {
    fwrite(STDERR, "REFUSING: DB_TEST_DATABASE equals the production database name.\n");
    fwrite(STDERR, "Set DB_TEST_DATABASE to a different name and re-run.\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
echo "Connecting to MySQL at {$host}:{$port} as {$user}...\n";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Dropping and recreating test schema '{$testDb}'...\n";
$pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$pdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Schema '{$testDb}' ready.\n\n";

echo "Running migrations against '{$testDb}'...\n";
$env = [
    'APP_ENV'      => 'testing',
    'DB_CONNECTION'=> 'mysql',
    'DB_HOST'      => $host,
    'DB_PORT'      => $port,
    'DB_DATABASE'  => $testDb,
    'DB_USERNAME'  => $user,
    'DB_PASSWORD'  => $pass,
];

// The child process needs the same connection, otherwise artisan would fall
// back to the .env values and migrate the wrong database.
$prefix = '';
foreach ($env as $k => $v) {
    $prefix .= $k . '=' . escapeshellarg($v) . ' ';
}

passthru($prefix . 'php artisan migrate --force', $exitCode);
exit($exitCode);

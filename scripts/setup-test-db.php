<?php
/**
 * Creates the dedicated MySQL testing schema and migrates it.
 * Never touches the application's own database.
 */
$host = 'localhost';
$port = '3306';
$user = 'root';
$pass = '';
$testDb = 'masahati_testing';

$pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Dropping and recreating '{$testDb}'...\n";
$pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$pdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Schema '{$testDb}' created.\n";
echo "Databases now: " . implode(', ', $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN)) . "\n";

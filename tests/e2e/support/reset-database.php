<?php

/**
 * Drops and recreates the end-to-end test database, so every run starts from
 * an empty one (the app's own migrations and seeders then fill it).
 *
 * Reads the same E2E_* variables as app/Config/Database.php and refuses any
 * database whose name does not contain "e2e" or "test", before connecting.
 *
 * Usage (via serve.js): E2E_DATABASE=mentora_e2e php tests/e2e/support/reset-database.php
 */

declare(strict_types=1);

$name     = (string) getenv('E2E_DATABASE');
$host     = (string) (getenv('E2E_DB_HOST') ?: '127.0.0.1');
$user     = (string) (getenv('E2E_DB_USER') ?: 'root');
$password = (string) getenv('E2E_DB_PASSWORD');

if ($name === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $name) || ! preg_match('/e2e|test/i', $name)) {
    fwrite(STDERR, "Refusing to reset '{$name}': E2E_DATABASE must be a plain name containing 'e2e' or 'test'.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_OFF);

$db = @new mysqli($host, $user, $password);

if ($db->connect_errno) {
    fwrite(STDERR, "Could not connect to the database server at {$host}: {$db->connect_error}\n");
    exit(1);
}

foreach (["DROP DATABASE IF EXISTS `{$name}`", "CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"] as $sql) {
    if (! $db->query($sql)) {
        fwrite(STDERR, "Could not run [{$sql}]: {$db->error}\n");
        exit(1);
    }
}

echo "Database '{$name}' on {$host} is empty and ready.\n";

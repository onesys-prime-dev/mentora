<?php

/**
 * Runs one SQL statement against the end-to-end database. For cases that need
 * time to have passed (a subscription that ended weeks ago), which the app
 * itself, rightly, will not let anyone enter for a new customer.
 *
 * Same guard as reset-database.php: refuses any database whose name does not
 * contain "e2e" or "test".
 *
 * Usage (via support/database.js): E2E_DATABASE=mentora_e2e php sql.php "UPDATE ..."
 */

declare(strict_types=1);

$name     = (string) (getenv('E2E_DATABASE') ?: 'mentora_e2e');
$host     = (string) (getenv('E2E_DB_HOST') ?: '127.0.0.1');
$user     = (string) (getenv('E2E_DB_USER') ?: 'root');
$password = (string) getenv('E2E_DB_PASSWORD');
$sql      = (string) ($argv[1] ?? '');

if (! preg_match('/^[A-Za-z0-9_]+$/', $name) || ! preg_match('/e2e|test/i', $name)) {
    fwrite(STDERR, "Refusing to use '{$name}': E2E_DATABASE must be a plain name containing 'e2e' or 'test'.\n");
    exit(2);
}

if ($sql === '') {
    fwrite(STDERR, "No SQL given.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_OFF);

$db = @new mysqli($host, $user, $password, $name);

if ($db->connect_errno) {
    fwrite(STDERR, "Could not connect to {$name} on {$host}: {$db->connect_error}\n");
    exit(1);
}

if (! $db->query($sql)) {
    fwrite(STDERR, "Could not run [{$sql}]: {$db->error}\n");
    exit(1);
}

echo "ok ({$db->affected_rows} row(s))\n";

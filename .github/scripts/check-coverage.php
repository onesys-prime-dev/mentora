<?php

/**
 * Fails the build when overall line coverage drops below the agreed floor.
 *
 * Usage: php .github/scripts/check-coverage.php <phpunit-output.txt> <floor-file>
 *
 * Reads the "Lines: NN.NN%" summary PHPUnit prints with --coverage-text and
 * the floor from a one-number file (.github/coverage-floor). The floor only
 * ever goes up: raise it in the same pull request that adds the tests.
 */

declare(strict_types=1);

[, $outputFile, $floorFile] = $argv + [null, null, null];

if ($outputFile === null || $floorFile === null || ! is_file($outputFile) || ! is_file($floorFile)) {
    fwrite(STDERR, "Usage: php check-coverage.php <phpunit-output.txt> <floor-file>\n");
    exit(2);
}

$output = (string) file_get_contents($outputFile);

if (! preg_match('/^\s*Lines:\s+([0-9]+(?:\.[0-9]+)?)%/m', $output, $match)) {
    fwrite(STDERR, "Could not find the \"Lines: NN.NN%\" summary in {$outputFile}. Was PHPUnit run with --coverage-text?\n");
    exit(2);
}

$actual = (float) $match[1];
$floor = (float) trim((string) file_get_contents($floorFile));

printf("Line coverage: %.2f%% (floor %.2f%%)\n", $actual, $floor);

if ($actual + 0.00001 < $floor) {
    fwrite(STDERR, sprintf(
        "Coverage fell below the floor: %.2f%% is under %.2f%%. Add tests for the code you changed; do not lower the floor to merge.\n",
        $actual,
        $floor
    ));
    exit(1);
}

if ($actual - $floor >= 1.0) {
    printf("Coverage is %.2f points above the floor; consider raising .github/coverage-floor.\n", $actual - $floor);
}

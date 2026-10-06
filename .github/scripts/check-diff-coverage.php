<?php

/**
 * Fails the build when the lines a pull request adds or changes are not covered
 * by tests, so new code cannot hide behind the overall coverage number.
 *
 * Usage: php .github/scripts/check-diff-coverage.php <clover.xml> <diff-file> <threshold-file>
 *
 * - clover.xml: the report PHPUnit writes (build/logs/clover.xml).
 * - diff-file: `git diff -U0 <base> HEAD -- app` (zero context, so the hunk
 *   headers name exactly the changed lines).
 * - threshold-file: a one-number file (.github/diff-coverage-threshold), the
 *   minimum percentage of changed executable lines that must be covered.
 *
 * Only executable lines of PHP files under app/ that PHPUnit measures are
 * counted; comments, blank lines, declarations, views, migrations, seeders and
 * the route table are ignored. A change with no executable lines passes.
 */

declare(strict_types=1);

[, $cloverFile, $diffFile, $thresholdFile] = $argv + [null, null, null, null];

foreach ([$cloverFile, $diffFile, $thresholdFile] as $path) {
    if ($path === null || ! is_file($path)) {
        fwrite(STDERR, "Usage: php check-diff-coverage.php <clover.xml> <diff-file> <threshold-file>\n");
        exit(2);
    }
}

$threshold = (float) trim((string) file_get_contents($thresholdFile));

/**
 * Changed (added or modified) line numbers per file, from a zero-context diff.
 *
 * @return array<string, list<int>> path relative to the repository root => line numbers
 */
function changedLines(string $diff): array
{
    $changed = [];
    $file    = null;

    foreach (preg_split('/\r?\n/', $diff) ?: [] as $line) {
        if (str_starts_with($line, '+++ ')) {
            $file = preg_match('#^\+\+\+ b/(app/.+\.php)$#', $line, $m) ? $m[1] : null;

            continue;
        }

        if ($file !== null && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
            $start = (int) $m[1];
            $count = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;

            for ($i = 0; $i < $count; $i++) {
                $changed[$file][] = $start + $i;
            }
        }
    }

    return $changed;
}

/**
 * Executable lines and their hit counts per file, from a Clover report.
 *
 * @return array<string, array<int, int>> absolute path as PHPUnit wrote it => [line => hits]
 */
function cloverLines(string $xml): array
{
    $doc = simplexml_load_string($xml);

    if ($doc === false) {
        fwrite(STDERR, "The coverage file is not valid Clover XML.\n");
        exit(2);
    }

    $files = [];

    foreach ($doc->xpath('//file') ?: [] as $file) {
        $name = str_replace('\\', '/', (string) $file['name']);

        foreach ($file->line as $line) {
            if ((string) $line['type'] === 'stmt') {
                $files[$name][(int) $line['num']] = (int) $line['count'];
            }
        }
    }

    return $files;
}

/**
 * "12, 15-17" for [12, 15, 16, 17].
 *
 * @param list<int> $lines
 */
function ranges(array $lines): string
{
    sort($lines);
    $parts = [];
    $start = $end = null;

    foreach ($lines as $n) {
        if ($start === null) {
            $start = $end = $n;
        } elseif ($n === $end + 1) {
            $end = $n;
        } else {
            $parts[] = $start === $end ? (string) $start : "{$start}-{$end}";
            $start   = $end = $n;
        }
    }

    if ($start !== null) {
        $parts[] = $start === $end ? (string) $start : "{$start}-{$end}";
    }

    return implode(', ', $parts);
}

$changed = changedLines((string) file_get_contents($diffFile));
$clover  = cloverLines((string) file_get_contents($cloverFile));

$rows       = [];
$executable = 0;
$covered    = 0;

ksort($changed);

foreach ($changed as $path => $lines) {
    // PHPUnit writes absolute paths; match on the path from the repo root.
    $measured = null;

    foreach ($clover as $name => $hits) {
        if (str_ends_with($name, '/' . $path)) {
            $measured = $hits;
            break;
        }
    }

    if ($measured === null) {
        continue; // not measured (excluded, or not a class file)
    }

    $fileExecutable = 0;
    $fileMissed     = [];

    foreach (array_unique($lines) as $n) {
        if (! isset($measured[$n])) {
            continue; // comment, blank line, declaration: nothing to run
        }

        $fileExecutable++;

        if ($measured[$n] === 0) {
            $fileMissed[] = $n;
        }
    }

    if ($fileExecutable === 0) {
        continue;
    }

    $executable += $fileExecutable;
    $covered    += $fileExecutable - count($fileMissed);
    $rows[]      = [$path, $fileExecutable, $fileExecutable - count($fileMissed), $fileMissed];
}

$percent = $executable === 0 ? 100.0 : $covered / $executable * 100;
$passed  = $percent + 0.00001 >= $threshold;

$out = [];

if ($executable === 0) {
    $out[] = 'No executable lines were changed under app/, so there is nothing to cover.';
} else {
    $out[] = sprintf('Changed lines covered: %.2f%% (%d of %d executable lines; minimum %.2f%%)', $percent, $covered, $executable, $threshold);

    foreach ($rows as [$path, $total, $hit, $missed]) {
        $out[] = sprintf('  %s: %d of %d%s', $path, $hit, $total, $missed === [] ? '' : '  not covered: lines ' . ranges($missed));
    }
}

echo implode("\n", $out), "\n";

$summary = getenv('GITHUB_STEP_SUMMARY');

if (is_string($summary) && $summary !== '') {
    $md = ["### Changed-lines coverage\n"];

    if ($executable === 0) {
        $md[] = 'No executable lines were changed under `app/`.';
    } else {
        $md[] = sprintf('**%.2f%%** of the changed executable lines are covered (%d of %d; minimum %.2f%%).' . "\n", $percent, $covered, $executable, $threshold);
        $md[] = '| File | Covered | Not covered |';
        $md[] = '|---|---|---|';

        foreach ($rows as [$path, $total, $hit, $missed]) {
            $md[] = sprintf('| `%s` | %d / %d | %s |', $path, $hit, $total, $missed === [] ? '' : ranges($missed));
        }
    }

    file_put_contents($summary, implode("\n", $md) . "\n", FILE_APPEND);
}

if (! $passed) {
    fwrite(STDERR, sprintf(
        "The lines this change adds or edits are %.2f%% covered, under the %.2f%% minimum. Add tests for the lines listed above in this pull request; do not lower the threshold to merge.\n",
        $percent,
        $threshold
    ));
    exit(1);
}

<?php

/**
 * Post-deploy smoke test: a few requests a real visitor makes first, against a
 * running site. /health only proves the app can reach its database; this proves
 * the site a user would see works.
 *
 * Usage: php .github/scripts/smoke-test.php <base-url> [--insecure]
 *
 *   --insecure  do not verify the TLS certificate (staging uses a self-signed one)
 *
 * Checks (every one must pass; the exit code is 1 if any fails):
 *   1. /health answers 200 with {"status":"ok"}
 *   2. /login answers 200, shows the sign-in form, and leaks no error page or
 *      debug toolbar
 *   3. the built stylesheet(s) and script(s) the login page links to exist and
 *      load as CSS and JavaScript (not an HTML error page)
 *   4. /, /student/dashboard, /instructor/dashboard and /files/1 send an
 *      anonymous visitor to the login page (not a 200, not an error)
 */

declare(strict_types=1);

[, $baseArg] = $argv + [null, null];
$insecure    = in_array('--insecure', $argv, true);

if ($baseArg === null || ! preg_match('#^https?://#i', $baseArg)) {
    fwrite(STDERR, "Usage: php smoke-test.php <base-url> [--insecure]\n");
    exit(2);
}

$base = rtrim($baseArg, '/');

/**
 * One GET, redirects not followed.
 *
 * @return array{status: int, headers: array<string, string>, body: string, error: string|null}
 */
function get(string $url, bool $insecure): array
{
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => 20,
            'header'          => "User-Agent: mentora-smoke-test\r\n",
        ],
        'ssl' => [
            'verify_peer'      => ! $insecure,
            'verify_peer_name' => ! $insecure,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false && empty($http_response_header)) {
        $last = error_get_last();

        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $last['message'] ?? 'no response'];
    }

    $status  = 0;
    $headers = [];

    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];

            continue;
        }

        if (str_contains($line, ':')) {
            [$name, $value]                  = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body, 'error' => null];
}

/** Absolute URL for a link found on a page served from $pageUrl. */
function resolve(string $link, string $pageUrl): string
{
    if (preg_match('#^https?://#i', $link)) {
        return $link;
    }

    $parts  = parse_url($pageUrl);
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

    if (str_starts_with($link, '//')) {
        return $parts['scheme'] . ':' . $link;
    }

    if (str_starts_with($link, '/')) {
        return $origin . $link;
    }

    $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

    return $origin . $dir . '/' . $link;
}

$results = [];

function record(string $name, bool $ok, string $detail = ''): void
{
    global $results;

    $results[] = [$name, $ok, $detail];
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
}

// 1. Health.
$health = get($base . '/health', $insecure);
$json   = json_decode($health['body'], true);

if ($health['status'] === 0) {
    fwrite(STDERR, "Could not reach {$base}: {$health['error']}\n");
    exit(1);
}

record(
    'GET /health is ok',
    $health['status'] === 200 && is_array($json) && ($json['status'] ?? null) === 'ok',
    'HTTP ' . $health['status']
);

// 2. The login page.
$login = get($base . '/login', $insecure);

record('GET /login answers 200', $login['status'] === 200, $login['error'] ?? 'HTTP ' . $login['status']);
record(
    'The login page shows the sign-in form',
    str_contains($login['body'], 'name="email"') && str_contains($login['body'], 'name="password"'),
    'email and password fields'
);

$leaks = [];

foreach (['debugbar_loader' => 'debug toolbar', 'Whoops!' => 'error page', 'Stack trace' => 'stack trace', 'Fatal error' => 'PHP error'] as $needle => $label) {
    if (str_contains($login['body'], $needle)) {
        $leaks[] = $label;
    }
}

record('The login page leaks no debug toolbar or error output', $login['body'] !== '' && $leaks === [], $login['body'] === '' ? 'the page is empty' : implode(', ', $leaks));

// 3. The built assets the login page links to.
preg_match_all('#<link[^>]+rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']#i', $login['body'], $css);
preg_match_all('#<script[^>]+src=["\']([^"\']+)["\']#i', $login['body'], $js);

$built = static fn (array $urls): array => array_values(array_filter($urls, static fn (string $u): bool => str_contains($u, '/build/')));

$styles  = $built($css[1]);
$scripts = $built($js[1]);

record('The login page links to a built stylesheet and script', $styles !== [] && $scripts !== [], count($styles) . ' css, ' . count($scripts) . ' js under /build/');

foreach ([['css', $styles], ['javascript', $scripts]] as [$kind, $urls]) {
    foreach ($urls as $link) {
        $url   = resolve($link, $base . '/login');
        $asset = get($url, $insecure);
        $type  = $asset['headers']['content-type'] ?? '';

        record(
            'Asset loads: ' . basename(parse_url($url, PHP_URL_PATH) ?: $url),
            $asset['status'] === 200 && $asset['body'] !== '' && str_contains(strtolower($type), $kind),
            $asset['error'] ?? 'HTTP ' . $asset['status'] . ', ' . ($type !== '' ? $type : 'no content type') . ', ' . strlen($asset['body']) . ' bytes'
        );
    }
}

// 4. Anonymous visitors are sent to the login page.
foreach (['/', '/student/dashboard', '/instructor/dashboard', '/files/1'] as $path) {
    $response = get($base . $path, $insecure);
    $location = $response['headers']['location'] ?? '';
    $sent     = in_array($response['status'], [301, 302, 303, 307, 308], true) && preg_match('#/login(?:[/?\#]|$)#', $location) === 1;

    record('Anonymous GET ' . $path . ' goes to login', $sent, $response['error'] ?? 'HTTP ' . $response['status'] . ($location !== '' ? ' -> ' . $location : ''));
}

// Summary.
$failed = array_filter($results, static fn (array $r): bool => ! $r[1]);

printf("\n%d of %d checks passed.\n", count($results) - count($failed), count($results));

$summary = getenv('GITHUB_STEP_SUMMARY');

if (is_string($summary) && $summary !== '') {
    $md = ["### Smoke test: {$base}\n", '| Check | Result |', '|---|---|'];

    foreach ($results as [$name, $ok, $detail]) {
        $md[] = sprintf('| %s | %s |', $name, ($ok ? 'pass' : '**FAIL**') . ($detail !== '' ? ' (' . str_replace('|', '/', $detail) . ')' : ''));
    }

    file_put_contents($summary, implode("\n", $md) . "\n", FILE_APPEND);
}

if ($failed !== []) {
    fwrite(STDERR, "The site is up but the smoke test failed. See the FAIL lines above.\n");
    exit(1);
}

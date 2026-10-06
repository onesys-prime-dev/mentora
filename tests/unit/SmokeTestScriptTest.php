<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * .github/scripts/smoke-test.php: the post-deploy check that the site a visitor
 * sees works. The script is run as a separate process, exactly as the deploy
 * runs it, against a stub web server (php -S) whose responses each test sets.
 *
 * @internal
 */
final class SmokeTestScriptTest extends CIUnitTestCase
{
    private static string $dir;

    /** @var resource|null */
    private static $server = null;

    private static int $port;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$dir = sys_get_temp_dir() . '/smoke_' . bin2hex(random_bytes(4));
        mkdir(self::$dir);

        // The stub answers from routes.json: path => {status, headers, body}.
        file_put_contents(self::$dir . '/router.php', <<<'PHP'
            <?php
            $routes = json_decode((string) @file_get_contents(__DIR__ . '/routes.json'), true) ?: [];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (! isset($routes[$path])) { http_response_code(404); header('Content-Type: text/html'); echo 'not found'; return; }
            $r = $routes[$path];
            http_response_code($r['status']);
            // PHP turns a Location header into a 302 unless the status is passed with it.
            foreach ($r['headers'] ?? [] as $name => $value) { header("{$name}: {$value}", true, $r['status']); }
            echo $r['body'] ?? '';
            PHP);

        $probe      = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
        fclose($probe);

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$dir . '/server.log', 'a'], 2 => ['file', self::$dir . '/server.log', 'a']],
            $pipes,
            self::$dir
        );

        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if ($conn) {
                fclose($conn);

                return;
            }

            usleep(100_000);
        }

        self::fail('The stub web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(self::$dir);

        parent::tearDownAfterClass();
    }

    /**
     * A healthy site; each test overrides what it wants to break.
     *
     * @param array<string, array<string, mixed>> $override
     *
     * @return array<string, array<string, mixed>>
     */
    private function site(array $override = [], string $assetOrigin = ''): array
    {
        $login = '<html><head>'
            . '<link rel="stylesheet" href="' . $assetOrigin . '/build/assets/app-1.css">'
            . '<script type="module" src="' . $assetOrigin . '/build/assets/app-1.js"></script>'
            . '</head><body><form method="post"><input name="email"><input name="password"></form></body></html>';

        $toLogin = ['status' => 302, 'headers' => ['Location' => '/login'], 'body' => ''];

        return array_replace([
            '/health'                => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"status":"ok","database":"up"}'],
            '/login'                 => ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => $login],
            '/build/assets/app-1.css' => ['status' => 200, 'headers' => ['Content-Type' => 'text/css'], 'body' => 'body{}'],
            '/build/assets/app-1.js' => ['status' => 200, 'headers' => ['Content-Type' => 'text/javascript'], 'body' => 'export{}'],
            '/'                      => $toLogin,
            '/student/dashboard'     => $toLogin,
            '/instructor/dashboard'  => $toLogin,
            '/files/1'               => $toLogin,
        ], $override);
    }

    /**
     * @param array<string, array<string, mixed>> $routes
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function smoke(array $routes, ?string $base = null): array
    {
        file_put_contents(self::$dir . '/routes.json', json_encode($routes));

        $proc = proc_open(
            [PHP_BINARY, ROOTPATH . '.github/scripts/smoke-test.php', $base ?? 'http://127.0.0.1:' . self::$port],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testAHealthySitePassesEveryCheck(): void
    {
        [$code, $out] = $this->smoke($this->site());

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('11 of 11 checks passed', $out);
        $this->assertStringNotContainsString('FAIL', $out);
    }

    public function testAbsoluteAssetUrlsAreFollowedToo(): void
    {
        [$code, $out] = $this->smoke($this->site([], 'http://127.0.0.1:' . self::$port));

        $this->assertSame(0, $code, $out);
    }

    public function testADegradedHealthCheckFails(): void
    {
        [$code, $out] = $this->smoke($this->site(['/health' => ['status' => 503, 'body' => '{"status":"degraded"}']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  GET /health is ok', $out);
    }

    public function testAHealthStatusOtherThanOkFailsEvenWithHttp200(): void
    {
        [$code, $out] = $this->smoke($this->site(['/health' => ['status' => 200, 'body' => '{"status":"degraded"}']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  GET /health is ok', $out);
    }

    public function testAnOkBodyWithAnErrorStatusStillFailsTheHealthCheck(): void
    {
        // The status code, not just the body, must say healthy.
        [$code, $out] = $this->smoke($this->site(['/health' => ['status' => 503, 'body' => '{"status":"ok"}']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  GET /health is ok  (HTTP 503)', $out);
    }

    public function testAPageThatRendersInsteadOfRedirectingFailsEvenWithALoginLocationHeader(): void
    {
        [$code, $out] = $this->smoke($this->site(['/student/dashboard' => ['status' => 200, 'headers' => ['Location' => '/login'], 'body' => 'secret dashboard']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Anonymous GET /student/dashboard goes to login  (HTTP 200', $out);
    }

    public function testALoginPageThatErrorsFails(): void
    {
        [$code, $out] = $this->smoke($this->site(['/login' => ['status' => 500, 'body' => 'Whoops! We hit a snag. Stack trace']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  GET /login answers 200', $out);
        $this->assertStringContainsString('FAIL  The login page leaks no debug toolbar or error output  (error page, stack trace)', $out);
    }

    public function testALoginPageWithoutTheFormFails(): void
    {
        [$code, $out] = $this->smoke($this->site(['/login' => ['status' => 200, 'body' => '<html><body>hello</body></html>']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  The login page shows the sign-in form', $out);
    }

    public function testALoginPageMissingOneOfTheTwoFieldsFails(): void
    {
        foreach (['<input name="email">', '<input name="password">'] as $only) {
            [$code, $out] = $this->smoke($this->site(['/login' => ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<link rel="stylesheet" href="/build/assets/app-1.css"><script src="/build/assets/app-1.js"></script><form>' . $only . '</form>']]));

            $this->assertSame(1, $code, $only);
            $this->assertStringContainsString('FAIL  The login page shows the sign-in form', $out, $only);
        }
    }

    public function testADebugToolbarInTheLoginPageFails(): void
    {
        $page = $this->site()['/login'];
        $page['body'] .= '<script id="debugbar_loader" src="/?debugbar"></script>';

        [$code, $out] = $this->smoke($this->site(['/login' => $page]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  The login page leaks no debug toolbar or error output  (debug toolbar)', $out);
    }

    public function testADevelopmentPageWithNoBuiltAssetsFails(): void
    {
        $page         = $this->site()['/login'];
        $page['body'] = '<script type="module" src="http://localhost:5173/@vite/client"></script><form><input name="email"><input name="password"></form>';

        [$code, $out] = $this->smoke($this->site(['/login' => $page]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  The login page links to a built stylesheet and script  (0 css, 0 js', $out);
    }

    public function testAMissingAssetFails(): void
    {
        $routes = $this->site();
        unset($routes['/build/assets/app-1.js']);

        [$code, $out] = $this->smoke($routes);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Asset loads: app-1.js  (HTTP 404', $out);
        $this->assertStringContainsString('PASS  Asset loads: app-1.css', $out);
    }

    public function testAnAssetServedAsAnHtmlPageFails(): void
    {
        // A proxy or SPA fallback answering 200 with an HTML page instead of the file.
        [$code, $out] = $this->smoke($this->site([
            '/build/assets/app-1.css' => ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<html>fallback</html>'],
        ]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Asset loads: app-1.css', $out);
    }

    public function testAnEmptyAssetFails(): void
    {
        [$code, $out] = $this->smoke($this->site([
            '/build/assets/app-1.js' => ['status' => 200, 'headers' => ['Content-Type' => 'text/javascript'], 'body' => ''],
        ]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Asset loads: app-1.js', $out);
    }

    public function testAProtectedPageThatDoesNotRedirectAnonymousVisitorsFails(): void
    {
        [$code, $out] = $this->smoke($this->site(['/student/dashboard' => ['status' => 200, 'body' => 'secret dashboard']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Anonymous GET /student/dashboard goes to login  (HTTP 200', $out);
        $this->assertStringContainsString('PASS  Anonymous GET /instructor/dashboard goes to login', $out);
    }

    public function testARedirectToAnotherPageThanLoginFails(): void
    {
        [$code, $out] = $this->smoke($this->site(['/files/1' => ['status' => 302, 'headers' => ['Location' => '/oops'], 'body' => '']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Anonymous GET /files/1 goes to login', $out);
    }

    public function testAServerErrorOnAProtectedPageFails(): void
    {
        [$code, $out] = $this->smoke($this->site(['/instructor/dashboard' => ['status' => 500, 'body' => 'boom']]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Anonymous GET /instructor/dashboard goes to login  (HTTP 500', $out);
    }

    public function testAnUnreachableSiteFailsWithAClearMessage(): void
    {
        [$code, , $err] = $this->smoke($this->site(), 'http://127.0.0.1:9');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Could not reach http://127.0.0.1:9', $err);
        $this->assertStringNotContainsString('The site is up', $err);
    }

    public function testMissingOrInvalidArgumentsExitWithUsageError(): void
    {
        foreach ([[], ['not-a-url']] as $args) {
            $proc = proc_open([PHP_BINARY, ROOTPATH . '.github/scripts/smoke-test.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);

            $this->assertSame(2, proc_close($proc));
            $this->assertStringContainsString('Usage:', $err);
        }
    }
}

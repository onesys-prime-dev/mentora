<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Config\Services;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Base class for tests that call real routes through the whole filter and
 * controller stack as a given user (or as nobody).
 *
 * Runs over HTTPS (the app redirects plain http), logs everyone out after
 * each test, and starts every test from an empty, rolled-back database.
 *
 * @internal
 */
abstract class RouteTestCase extends DatabaseTestCase
{
    use AuthenticationTesting;
    use FeatureTestTrait {
        call as private featureCall;
    }

    protected bool $isolateTests = true;

    private bool $withoutCsrf = false;

    /** Set when this test had to create the stand-in Vite manifest (so it removes it again). */
    private bool $createdViteManifest = false;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['HTTPS'] = 'on';

        // The shared renderer keeps the data of every view it rendered; a request starts with none, so each test
        // does too (one page's reauthDialog or area must not show up on the next test's page).
        Services::resetSingle('renderer');

        $this->ensureViteManifest();
    }

    /**
     * One browser step. Each feature-test request starts with a blank session,
     * so what the last step left in it is carried into the next one, the way
     * a browser keeps its cookie.
     *
     * @param array<string, string> $data
     */
    protected function step(string $verb, string $uri, array $data = []): TestResponse
    {
        $result = $this->call($verb, $uri, $data);

        $this->withSession();

        return $result;
    }

    /**
     * Every request that changes something carries the session's CSRF token, the way a page
     * served by the app would. withoutCsrfToken() sends the next requests without one.
     *
     * @param array<array-key, mixed>|null $params
     */
    public function call(string $method, string $path, ?array $params = null)
    {
        unset($this->headers['X-CSRF-TOKEN']);

        if (! $this->withoutCsrf && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $this->session['csrf_test_name'] ?? bin2hex(random_bytes(16));

            $this->session['csrf_test_name'] = $token;
            $this->headers['X-CSRF-TOKEN']   = $token;
        }

        // A fresh security service, so it reads the token from the session this request is given.
        Services::resetSingle('security');

        return $this->featureCall($method, $path, $params);
    }

    /** The next requests are sent without a CSRF token, as from a page that was open too long. */
    protected function withoutCsrfToken(): static
    {
        $this->withoutCsrf = true;

        return $this;
    }

    protected function tearDown(): void
    {
        $this->withoutCsrf = false;

        auth()->logout();

        // A test that mounted its own routes (withRoutes) added them to the shared
        // route collection. Empty it, which also makes the next test load the real
        // route table again, so nothing is left behind for the tests that read it.
        if ($this->routes instanceof RouteCollection) {
            service('routes')->resetRoutes();
        }

        $this->removeStandInViteManifest();

        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }

    /**
     * Every full page asks vite() for the built assets, which reads
     * public/build/.vite/manifest.json. That file only exists after
     * `npm run build`, and tests must not depend on a build having run (the
     * CI tests job does not build the frontend). When there is no real
     * manifest, a minimal stand-in is written for the duration of the test.
     */
    private function ensureViteManifest(): void
    {
        $manifest = ROOTPATH . 'public/build/.vite/manifest.json';

        if (is_file($manifest)) {
            return;
        }

        if (! is_dir(dirname($manifest))) {
            mkdir(dirname($manifest), 0775, true);
        }

        file_put_contents($manifest, json_encode([
            'resources/js/app.js' => ['file' => 'assets/test-app.js', 'css' => []],
        ]));

        $this->createdViteManifest = true;
    }

    private function removeStandInViteManifest(): void
    {
        if (! $this->createdViteManifest) {
            return;
        }

        @unlink(ROOTPATH . 'public/build/.vite/manifest.json');
        @rmdir(ROOTPATH . 'public/build/.vite');
        @rmdir(ROOTPATH . 'public/build');

        $this->createdViteManifest = false;
    }

    /** Logs in as the user for the rest of the test. */
    protected function loggedInAs(User $user): self
    {
        return $this->actingAs($user);
    }
}

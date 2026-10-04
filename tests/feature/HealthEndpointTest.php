<?php

declare(strict_types=1);

use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;

/**
 * The deploy pipeline treats this endpoint as "the site is up". It must be
 * public (no login) and must say "ok" only when the database answers.
 *
 * @internal
 */
final class HealthEndpointTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // The app redirects plain http to https, as it does behind the proxy.
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }

    public function testReportsHealthyWithoutLoggingIn(): void
    {
        $result = $this->get('health');

        $result->assertStatus(200);
        $result->assertJSONFragment(['status' => 'ok', 'database' => 'up']);
    }

    public function testRevealsNothingBeyondStatusAndDatabase(): void
    {
        $body = json_decode($this->get('health')->getJSON(), true);

        $this->assertEqualsCanonicalizing(['status', 'database'], array_keys($body));
    }

    public function testIsNeverCached(): void
    {
        $result = $this->get('health');

        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }
}

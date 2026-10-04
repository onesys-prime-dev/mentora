<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;

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
    use FeatureTestTrait;

    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        auth()->logout();

        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }

    /** Logs in as the user for the rest of the test. */
    protected function loggedInAs(User $user): self
    {
        return $this->actingAs($user);
    }
}

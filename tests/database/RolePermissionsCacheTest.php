<?php

declare(strict_types=1);

use App\Services\Auth\RolePermissions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The permission caches (who belongs where, each institution's matrix) are static. Every database test starts
 * with them empty, so a user id that an earlier test used with another institution cannot answer from the cache.
 *
 * @internal
 */
final class RolePermissionsCacheTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    /** @return array<string, array<mixed>> */
    private function caches(): array
    {
        return [
            'matrices'     => (new ReflectionProperty(RolePermissions::class, 'matrices'))->getValue(),
            'institutions' => (new ReflectionProperty(RolePermissions::class, 'institutions'))->getValue(),
        ];
    }

    public function testTheCacheIsEmptyAtTheStartOfEveryTest(): void
    {
        $instructor = PeopleFactory::person('instructor', institutionId: PeopleFactory::institution('CACHE'));
        (new RolePermissions())->allows($instructor, 'grading.manage');
        $this->assertNotSame([], $this->caches()['institutions'], 'the call filled it');

        // What the next test gets: a fresh start.
        $this->tearDown();
        $this->setUp();

        $this->assertSame(['matrices' => [], 'institutions' => []], $this->caches());
    }
}

<?php

declare(strict_types=1);

use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Admin\PermissionMatrix;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The Messages tile that replaced the Inbox tile: shown while the `messages` flag
 * is on, to every role that holds `messages.use`, and never to the system administrator.
 *
 * @internal
 */
final class MessagesNavigationTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        helper(['navigation', 'icon', 'feature']);
        PermissionMatrix::flush();

        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'messages', 'institution_id' => null, 'enabled' => 1]);
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }

        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** @return array<string, mixed>|null */
    private function tileFor(string $role): ?array
    {
        auth()->login(CourseFactory::user($role));

        $tile = array_column(global_apps($role), null, 'key')['messages'] ?? null;

        auth()->logout();

        return $tile;
    }

    public function testTheMessagesTileAppearsForEveryRoleButTheSystemAdmin(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean', 'parent', 'institutional_admin'] as $role) {
            $tile = $this->tileFor($role);

            $this->assertNotNull($tile, $role);
            $this->assertSame('Messages', $tile['label'], $role);
            $this->assertSame(site_url('messages'), $tile['url'], $role);
            $this->assertSame(0, $tile['badge'], $role);
        }

        $this->assertNull($this->tileFor('admin'));
    }

    public function testTheTileIsHiddenWhileTheFlagIsOffEvenWithMessagesUse(): void
    {
        $this->assertNotNull($this->tileFor('student'));

        (new FeatureFlagOverrideModel())->where('flag_key', 'messages')->set(['enabled' => 0])->update();

        $this->assertNull($this->tileFor('student'));
    }

    public function testTheTileIsHiddenWhenTheRoleLosesMessagesUse(): void
    {
        $this->assertNotNull($this->tileFor('student'));

        (new PermissionMatrix())->set('student', 'messages.use', false, CourseFactory::user('admin'));
        PermissionMatrix::flush();

        $this->assertNull($this->tileFor('student'));
        $this->assertNotNull($this->tileFor('instructor'));
    }
}

<?php

declare(strict_types=1);

use App\Models\Users\UserProfileModel;
use App\Services\Auth\ActiveRoleService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Which role a multi-role user is currently acting as: resumed across
 * logins via user_profiles.active_role, re-validated against the user's
 * real Shield groups on every read so a revoked role can never linger.
 *
 * @internal
 */
final class ActiveRoleServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ActiveRoleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ActiveRoleService();
        session()->destroy();
    }

    /** CourseFactory::user() skips user_profiles, which real registration always creates. */
    private function withProfile(User $user): User
    {
        (new UserProfileModel())->insert([
            'user_id'    => $user->id,
            'first_name' => 'Test',
            'last_name'  => 'User',
        ]);

        return $user;
    }

    private function dualRoleUser(): User
    {
        $user = $this->withProfile(CourseFactory::user('chairperson'));
        $user->addGroup('instructor');

        return $user;
    }

    private function savedRole(User $user): ?string
    {
        return db_connect()->table('user_profiles')->where('user_id', $user->id)->get()->getRowArray()['active_role'] ?? null;
    }

    public function testASingleRoleUserResolvesToTheirOnlyGroup(): void
    {
        $user = $this->withProfile(CourseFactory::user('instructor'));

        $this->assertSame('instructor', $this->service->resolve($user));
        $this->assertSame('instructor', $this->savedRole($user), 'the default is persisted so it survives a fresh login');
    }

    public function testADualRoleUserDefaultsToChairpersonOnFirstResolve(): void
    {
        $user = $this->dualRoleUser();

        $this->assertSame('chairperson', $this->service->resolve($user));
    }

    public function testSwitchingToAHeldRoleSucceedsAndIsPickedUpOnTheNextResolve(): void
    {
        $user = $this->dualRoleUser();
        $this->service->resolve($user);

        $this->assertTrue($this->service->switchTo($user, 'instructor'));
        $this->assertSame('instructor', $this->service->resolve($user));
        $this->assertSame('instructor', $this->savedRole($user));
    }

    public function testSwitchingToARoleTheUserDoesNotHoldFails(): void
    {
        $user = $this->dualRoleUser();

        $this->assertFalse($this->service->switchTo($user, 'admin'));
        $this->assertNull($this->savedRole($user));
    }

    public function testAStaleSessionRoleNoLongerHeldIsIgnored(): void
    {
        $user = $this->dualRoleUser();
        $this->service->switchTo($user, 'instructor');

        // The user is removed from a group mid-session (e.g. revoked) — the
        // session still says 'instructor', but that's no longer true.
        db_connect()->table('auth_groups_users')
            ->where('user_id', $user->id)
            ->where('group', 'instructor')
            ->delete();

        $user = model(CodeIgniter\Shield\Models\UserModel::class)->findById($user->id);

        $this->assertSame('chairperson', $this->service->resolve($user), 'falls back to a group the user actually still holds');
    }

    public function testAUserInNoGroupResolvesToAnEmptyRole(): void
    {
        $user = CourseFactory::user('student');

        db_connect()->table('auth_groups_users')->where('user_id', $user->id)->delete();
        $user = model(CodeIgniter\Shield\Models\UserModel::class)->findById($user->id);

        $this->assertSame('', $this->service->resolve($user));
    }

    public function testASavedRoleFromAPreviousLoginIsResumedOnAFreshSession(): void
    {
        $user = $this->dualRoleUser();
        $this->service->switchTo($user, 'instructor');

        // A new login, a brand new session — nothing in it yet.
        session()->destroy();

        $this->assertSame('instructor', $this->service->resolve($user), 'resumes the last-used role from the saved profile, not the chairperson default');
    }
}

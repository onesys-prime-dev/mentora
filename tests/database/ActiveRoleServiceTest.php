<?php

declare(strict_types=1);

use App\Models\Users\UserProfileModel;
use App\Services\Auth\ActiveRoleService;
use App\Services\Institutions\DeanAssignmentService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

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

    /** A person who is dean and instructor (a dean cannot also be a chairperson), with or without an active deanship. */
    private function deanWithOtherRoles(bool $assigned): User
    {
        $institution = PeopleFactory::institution('ARS');
        db_connect()->table('departments')->insert(['institution_id' => $institution, 'code' => 'D1', 'name' => 'Department One']);
        $department = (int) db_connect()->insertID();

        $user = PeopleFactory::person('instructor', 'Dana', 'Dean', null, $institution);

        if ($assigned) {
            (new DeanAssignmentService())->assign($institution, $department, (int) $user->id);
        } else {
            $user->addGroup('dean');
        }

        return model(CodeIgniter\Shield\Models\UserModel::class)->findById($user->id);
    }

    public function testADeanWhoIsAlsoAnInstructorDefaultsToDean(): void
    {
        $user = $this->deanWithOtherRoles(true);

        $this->assertSame('dean', $this->service->resolve($user));
    }

    public function testADeanCanSwitchToTheirOtherRolesAndBack(): void
    {
        $user = $this->deanWithOtherRoles(true);

        $this->assertTrue($this->service->switchTo($user, 'instructor'));
        $this->assertSame('instructor', $this->service->resolve($user));
        $this->assertTrue($this->service->switchTo($user, 'dean'));
        $this->assertSame('dean', $this->service->resolve($user));
    }

    public function testADeanWithoutAnAssignmentCannotActAsDeanWhileHoldingAnotherRole(): void
    {
        $user = $this->deanWithOtherRoles(false);

        $this->assertSame('instructor', $this->service->resolve($user));
        $this->assertFalse($this->service->switchTo($user, 'dean'));
    }

    public function testADeanWithNothingElseKeepsTheRoleSoTheyReachTheExplanationPage(): void
    {
        $user = $this->withProfile(CourseFactory::user('dean'));

        $this->assertSame('dean', $this->service->resolve($user));
    }

    public function testRoleDeanIsDroppedMidSessionWhenTheAssignmentEnds(): void
    {
        $user = $this->deanWithOtherRoles(true);
        $this->assertSame('dean', $this->service->resolve($user));

        db_connect()->table('dean_assignments')->where('dean_user_id', $user->id)->update(['status' => 'ended', 'active_marker' => null]);

        $this->assertSame('instructor', $this->service->resolve($user), 'the session still says dean, but the deanship has ended');
    }

    public function testSwitchingToARoleTheUserDoesNotHoldIsRefusedForDeanToo(): void
    {
        $user = $this->withProfile(CourseFactory::user('instructor'));

        $this->assertFalse($this->service->switchTo($user, 'dean'));
        $this->assertNull($this->savedRole($user));
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

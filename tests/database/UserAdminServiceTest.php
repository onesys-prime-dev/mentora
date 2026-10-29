<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationService;
use App\Services\Users\UserAdminService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * What an administrator can do to people, and what is refused: inviting,
 * editing, switching accounts on and off, roles and institutions, bulk
 * changes, with the rules about administrators and seats.
 *
 * @internal
 */
final class UserAdminServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private UserAdminService $service;

    private FakeEmail $mailer;

    private User $admin;

    private int $acme;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->service = new UserAdminService();
        $this->mailer  = FakeEmail::install();
        $this->admin   = PeopleFactory::person('admin', 'Ada', 'Admin', 'ada.admin@example.com');
        $this->acme    = PeopleFactory::institution('ACME', 'active', 'Acme University');
        $this->now     = new DateTimeImmutable('2026-10-09 10:00:00');

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function person(array $override = []): array
    {
        return array_merge(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'], $override);
    }

    /** Fills the institution's two seats with students. */
    private function fillSeats(): void
    {
        PeopleFactory::subscribe($this->acme, PeopleFactory::plan('Small', 2));
        PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);
        PeopleFactory::person('student', 'Ben', 'Two', null, $this->acme);
    }

    private function audit(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getResultArray();
    }

    private function active(User $user): int
    {
        return (int) db_connect()->table('users')->where('id', $user->id)->get()->getRowArray()['active'];
    }

    private function roles(User $user): array
    {
        $roles = array_column(db_connect()->table('auth_groups_users')->where('user_id', $user->id)->get()->getResultArray(), 'group');
        sort($roles);

        return $roles;
    }

    private function refused(callable $call, string $mentions): void
    {
        try {
            $call();
            $this->fail('This should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Inviting (how an account is created)
    |--------------------------------------------------------------------------
    */

    public function testInvitingSendsALinkForTheChosenRole(): void
    {
        $result = $this->service->invite($this->acme, 'instructor', $this->person(), $this->admin, $this->now);

        $this->assertNull($result['email_error']);

        $invitation = db_connect()->table('institution_invitations')->get()->getRowArray();

        $this->assertSame('instructor', $invitation['role']);
        $this->assertSame($this->acme, (int) $invitation['institution_id']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString('join Acme University as an instructor', $this->mailer->sent[0]['message']);
        $this->assertStringNotContainsString('administer', $this->mailer->sent[0]['message']);
    }

    public function testTheInvitedPersonBecomesThatRoleInThatInstitutionWhenTheyUseTheLink(): void
    {
        $this->service->invite($this->acme, 'chairperson', $this->person(), $this->admin, $this->now);
        preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $this->mailer->sent[0]['message'], $match);

        $user = (new InvitationService())->accept($match[1], self::STRONG, $this->now);

        $this->assertTrue($user->inGroup('chairperson'));
        $this->assertFalse($user->inGroup('institutional_admin'));
        $this->assertSame(1, db_connect()->table('institution_users')->where(['institution_id' => $this->acme, 'user_id' => $user->id])->countAllResults());
    }

    public function testASystemAdministratorCannotBeInvitedHere(): void
    {
        $this->refused(fn () => $this->service->invite($this->acme, 'admin', $this->person(), $this->admin, $this->now), 'Choose a role');
        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $this->refused(fn () => $this->service->invite($this->acme, 'superuser', $this->person(), $this->admin, $this->now), 'Choose a role');
        $this->refused(fn () => $this->service->invite($this->acme, '', $this->person(), $this->admin, $this->now), 'Choose a role');
    }

    public function testAnInstitutionIsRequiredAndMustHaveFinishedOnboarding(): void
    {
        $pending = PeopleFactory::institution('NEWCO', 'pending');

        $this->refused(fn () => $this->service->invite(999999, 'student', $this->person(), $this->admin, $this->now), 'Choose the institution');
        $this->refused(fn () => $this->service->invite($pending, 'student', $this->person(), $this->admin, $this->now), 'Finish onboarding');
    }

    public function testBadDetailsAreRefusedAndNothingIsSent(): void
    {
        $this->refused(fn () => $this->service->invite($this->acme, 'student', $this->person(['email' => 'nope']), $this->admin, $this->now), 'valid email');
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAStudentCannotBeInvitedWhenEverySeatIsInUse(): void
    {
        $this->fillSeats();

        $this->refused(fn () => $this->service->invite($this->acme, 'student', $this->person(), $this->admin, $this->now), 'seats');
        $this->assertSame([], $this->mailer->sent);

        $this->service->invite($this->acme, 'instructor', $this->person(), $this->admin, $this->now);
        $this->assertCount(1, $this->mailer->sent, 'seats are for students only');
    }

    public function testASeatThatFilledUpBeforeTheLinkWasUsedStopsTheStudent(): void
    {
        PeopleFactory::subscribe($this->acme, PeopleFactory::plan('Small', 2));
        PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);

        $this->service->invite($this->acme, 'student', $this->person(), $this->admin, $this->now);
        preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $this->mailer->sent[0]['message'], $match);

        PeopleFactory::person('student', 'Ben', 'Two', null, $this->acme);

        $this->refused(fn () => (new InvitationService())->accept($match[1], self::STRONG, $this->now), 'seats');
        $this->assertNull(db_connect()->table('institution_invitations')->get()->getRowArray()['accepted_at'], 'the link is still there for when there is room');
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    public function testEditingChangesTheNameAndTheSignInEmailAndIsRecorded(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->service->update((int) $user->id, ['first_name' => 'Maria Clara', 'last_name' => 'Santos-Reyes', 'email' => 'Clara@Example.com'], $this->admin);

        $row = (new App\Services\Users\UserDirectory())->find((int) $user->id);

        $this->assertSame('Maria Clara', $row['first_name']);
        $this->assertSame('Santos-Reyes', $row['last_name']);
        $this->assertSame('clara@example.com', $row['email']);

        $entry = $this->audit('user.updated')[0];

        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertSame((int) $user->id, (int) $entry['target_id']);
        $this->assertSame($this->acme, (int) $entry['institution_id']);
        $this->assertStringContainsString('maria@example.com', (string) $entry['old_values']);
        $this->assertStringContainsString('clara@example.com', (string) $entry['new_values']);
    }

    public function testAnAccountWithoutAProfileGetsOne(): void
    {
        $admin = PeopleFactory::person('admin', 'x', 'y', 'second.admin@example.com');
        db_connect()->table('user_profiles')->where('user_id', $admin->id)->delete();

        $this->service->update((int) $admin->id, $this->person(['email' => 'second.admin@example.com']), $this->admin);

        $this->assertSame('Maria', db_connect()->table('user_profiles')->where('user_id', $admin->id)->get()->getRowArray()['first_name']);
    }

    public function testAnEmailThatBelongsToSomeoneElseIsRefused(): void
    {
        PeopleFactory::person('student', 'Taken', 'Person', 'taken@example.com', $this->acme);
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->refused(fn () => $this->service->update((int) $user->id, $this->person(['email' => 'TAKEN@example.com']), $this->admin), 'already exists');
        $this->assertSame([], $this->audit('user.updated'));
    }

    public function testKeepingYourOwnEmailIsFine(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->service->update((int) $user->id, ['first_name' => 'Marie', 'last_name' => 'Santos', 'email' => 'maria@example.com'], $this->admin);

        $this->assertSame('Marie', (new App\Services\Users\UserDirectory())->find((int) $user->id)['first_name']);
    }

    public function testSavingWithNothingChangedWritesNoEntry(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->service->update((int) $user->id, ['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com'], $this->admin);

        $this->assertSame([], $this->audit('user.updated'));
    }

    public function testBadNamesAndEmailsAreRefused(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->refused(fn () => $this->service->update((int) $user->id, $this->person(['first_name' => '']), $this->admin), 'first name');
        $this->refused(fn () => $this->service->update((int) $user->id, $this->person(['email' => 'nope']), $this->admin), 'valid email');
        $this->refused(fn () => $this->service->update(999999, $this->person(), $this->admin), 'does not exist');
    }

    /*
    |--------------------------------------------------------------------------
    | Switching accounts on and off
    |--------------------------------------------------------------------------
    */

    public function testDeactivatingSwitchesTheAccountOffAndIsRecorded(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', null, $this->acme);

        $this->service->deactivate((int) $user->id, $this->admin, $this->now);

        $this->assertSame(0, $this->active($user));
        $this->assertNotNull(db_connect()->table('user_account_controls')->where('user_id', $user->id)->get()->getRowArray()['deactivated_at']);

        $entry = $this->audit('user.deactivated')[0];

        $this->assertSame((int) $user->id, (int) $entry['target_id']);
        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
    }

    public function testYouCannotDeactivateYourself(): void
    {
        PeopleFactory::person('admin', 'Other', 'Admin');

        $this->refused(fn () => $this->service->deactivate((int) $this->admin->id, $this->admin, $this->now), 'your own account');
        $this->assertSame(1, $this->active($this->admin));
    }

    public function testTheLastActiveAdministratorCannotBeDeactivated(): void
    {
        $only = PeopleFactory::person('admin', 'Only', 'Admin');
        db_connect()->table('users')->where('id', $this->admin->id)->update(['active' => 0]);

        $this->refused(fn () => $this->service->deactivate((int) $only->id, $this->admin, $this->now), 'last active system administrator');
        $this->assertSame(1, $this->active($only));
    }

    public function testAnAdministratorCanBeDeactivatedWhenAnotherIsActive(): void
    {
        $other = PeopleFactory::person('admin', 'Other', 'Admin');

        $this->service->deactivate((int) $other->id, $this->admin, $this->now);

        $this->assertSame(0, $this->active($other));
    }

    public function testDeactivatingTwiceIsRefused(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', null, $this->acme);
        $this->service->deactivate((int) $user->id, $this->admin, $this->now);

        $this->refused(fn () => $this->service->deactivate((int) $user->id, $this->admin, $this->now), 'already deactivated');
    }

    public function testAnUnknownUserCannotBeDeactivated(): void
    {
        $this->refused(fn () => $this->service->deactivate(999999, $this->admin, $this->now), 'does not exist');
    }

    public function testReactivatingSwitchesItBackOn(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);
        $this->service->deactivate((int) $user->id, $this->admin, $this->now);

        $this->service->reactivate((int) $user->id, $this->admin, $this->now->modify('+1 hour'));

        $this->assertSame(1, $this->active($user));
        $this->assertNull(db_connect()->table('user_account_controls')->where('user_id', $user->id)->get()->getRowArray()['deactivated_at']);
        $this->assertCount(1, $this->audit('user.reactivated'));
    }

    public function testReactivatingSomeoneWhoIsActiveIsRefused(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', null, $this->acme);

        $this->refused(fn () => $this->service->reactivate((int) $user->id, $this->admin, $this->now), 'already active');
    }

    public function testAStudentIsNotReactivatedIntoASeatThatIsGone(): void
    {
        PeopleFactory::subscribe($this->acme, PeopleFactory::plan('Small', 1));
        $away = PeopleFactory::person('student', 'Away', 'Student', null, $this->acme);
        $this->service->deactivate((int) $away->id, $this->admin, $this->now);
        PeopleFactory::person('student', 'Took', 'Seat', null, $this->acme);

        $this->refused(fn () => $this->service->reactivate((int) $away->id, $this->admin, $this->now), 'seats');
        $this->assertSame(0, $this->active($away));
    }

    public function testASignOutEverywhereIsRecordedAndLeavesTheAccountOn(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', null, $this->acme);

        $this->service->signOut((int) $user->id, $this->admin, $this->now);

        $this->assertSame('2026-10-09 10:00:00', db_connect()->table('user_account_controls')->where('user_id', $user->id)->get()->getRowArray()['sessions_valid_from']);
        $this->assertSame(1, $this->active($user));
        $this->assertCount(1, $this->audit('user.signed_out'));
    }

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    public function testRolesAreReplacedAndTheChangeIsRecorded(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->service->setRoles((int) $user->id, ['chairperson', 'instructor'], $this->admin);

        $this->assertSame(['chairperson', 'instructor'], $this->roles($user));

        $entry = $this->audit('user.roles_changed')[0];

        $this->assertStringContainsString('instructor', (string) $entry['old_values']);
        $this->assertStringContainsString('chairperson', (string) $entry['new_values']);
    }

    public function testNobodyIsMadeASystemAdministratorFromTheScreen(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->refused(fn () => $this->service->setRoles((int) $user->id, ['admin'], $this->admin), 'php spark admin:create');
        $this->refused(fn () => $this->service->setRoles((int) $user->id, ['instructor', 'admin'], $this->admin), 'php spark admin:create');
        $this->assertSame(['instructor'], $this->roles($user));
    }

    public function testASystemAdministratorsRolesAreNotChangedFromTheScreen(): void
    {
        $other = PeopleFactory::person('admin', 'Other', 'Admin');

        $this->refused(fn () => $this->service->setRoles((int) $other->id, ['student'], $this->admin), 'php spark');
        $this->assertSame(['admin'], $this->roles($other));
    }

    public function testAPersonAlwaysKeepsAtLeastOneRole(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->refused(fn () => $this->service->setRoles((int) $user->id, [], $this->admin), 'at least one role');
        $this->assertSame(['instructor'], $this->roles($user));
    }

    public function testAnUnknownRoleIsRefusedWhenSettingRoles(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->refused(fn () => $this->service->setRoles((int) $user->id, ['wizard'], $this->admin), 'not a role');
    }

    public function testBecomingAStudentNeedsASeat(): void
    {
        $this->fillSeats();
        $teacher = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->refused(fn () => $this->service->setRoles((int) $teacher->id, ['instructor', 'student'], $this->admin), 'seats');
        $this->assertSame(['instructor'], $this->roles($teacher));
    }

    public function testChangingNothingWritesNoEntry(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->service->setRoles((int) $user->id, ['instructor'], $this->admin);

        $this->assertSame([], $this->audit('user.roles_changed'));
    }

    /*
    |--------------------------------------------------------------------------
    | Institutions
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | In bulk
    |--------------------------------------------------------------------------
    */

    public function testBulkDeactivatingDoesEveryoneItCan(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);
        $b = PeopleFactory::person('student', 'Ben', 'Two', null, $this->acme);

        $result = $this->service->bulk('deactivate', [(int) $a->id, (int) $b->id], $this->admin, $this->now);

        $this->assertSame([(int) $a->id, (int) $b->id], $result['done']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(0, $this->active($a));
        $this->assertSame(0, $this->active($b));
        $this->assertCount(2, $this->audit('user.deactivated'));
    }

    public function testOneRefusalDoesNotStopTheRest(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);

        $result = $this->service->bulk('deactivate', [(int) $this->admin->id, (int) $a->id, 999999], $this->admin, $this->now);

        $this->assertSame([(int) $a->id], $result['done']);
        $this->assertArrayHasKey((int) $this->admin->id, $result['skipped']);
        $this->assertStringContainsString('your own account', $result['skipped'][(int) $this->admin->id]);
        $this->assertStringContainsString('does not exist', $result['skipped'][999999]);
        $this->assertSame(1, $this->active($this->admin));
    }

    public function testBulkNeverDeactivatesTheLastAdministrator(): void
    {
        $one = PeopleFactory::person('admin', 'One', 'Admin');
        $two = PeopleFactory::person('admin', 'Two', 'Admin');
        db_connect()->table('users')->where('id', $this->admin->id)->update(['active' => 0]);

        $result = $this->service->bulk('deactivate', [(int) $one->id, (int) $two->id], $this->admin, $this->now);

        $this->assertSame([(int) $one->id], $result['done'], 'the first goes, because another is still active');
        $this->assertStringContainsString('last active system administrator', $result['skipped'][(int) $two->id], 'the second is the last');
        $this->assertSame(1, $this->active($two));
    }

    public function testBulkReactivating(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);
        $b = PeopleFactory::person('student', 'Ben', 'Two', null, $this->acme);
        $this->service->bulk('deactivate', [(int) $a->id, (int) $b->id], $this->admin, $this->now);

        $result = $this->service->bulk('reactivate', [(int) $a->id, (int) $b->id, (int) $this->admin->id], $this->admin, $this->now);

        $this->assertSame([(int) $a->id, (int) $b->id], $result['done']);
        $this->assertStringContainsString('already active', $result['skipped'][(int) $this->admin->id]);
    }

    public function testBulkNeedsSomeoneAndAKnownAction(): void
    {
        $this->refused(fn () => $this->service->bulk('deactivate', [], $this->admin), 'at least one person');
        $this->refused(fn () => $this->service->bulk('deactivate', [0, -3], $this->admin), 'at least one person');
        $this->refused(fn () => $this->service->bulk('delete', [1], $this->admin), 'several people at once');
        $this->refused(fn () => $this->service->bulk('roles', [1], $this->admin), 'several people at once');
    }

    public function testTheSameIdTwiceIsDoneOnce(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);

        $result = $this->service->bulk('deactivate', [(int) $a->id, (int) $a->id], $this->admin, $this->now);

        $this->assertSame([(int) $a->id], $result['done']);
        $this->assertSame([], $result['skipped']);
    }
}

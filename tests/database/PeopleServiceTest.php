<?php

declare(strict_types=1);

use App\Services\Institutions\PeopleService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * What an institutional administrator may do to their people: the scoped
 * list, invitations, edits, switching accounts on and off, roles, removing
 * someone who also belongs elsewhere, and the rules that protect the last
 * administrator and other institutions' accounts.
 *
 * @internal
 */
final class PeopleServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private User $actor;

    private PeopleService $service;

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->mailer = FakeEmail::install();

        $this->institution = SisFixture::institution('PPLA');
        $this->other       = SisFixture::institution('PPLB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@people.test', $this->institution);
        $this->service     = new PeopleService();

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function person(string $role, string $first, ?int $institution = null, bool $active = true): User
    {
        return PeopleFactory::person($role, $first, 'Person', strtolower($first) . '@people.test', $institution ?? $this->institution, $active);
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the change to be refused.');
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    private function names(array $result): array
    {
        return array_column($result['rows'], 'first_name');
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testTheListHoldsOnlyThisInstitutionsPeopleAndHidesOtherMemberships(): void
    {
        $this->person('student', 'Ann');
        $this->person('student', 'Zed', $this->other);

        $shared = $this->person('instructor', 'Ivy');
        db_connect()->table('institution_users')->insert(['institution_id' => $this->other, 'user_id' => $shared->id, 'created_at' => date('Y-m-d H:i:s')]);

        $result = $this->service->list($this->institution, [], (int) $this->actor->id);

        $this->assertSame(['Ada', 'Ann', 'Ivy'], $this->names($result));
        $this->assertSame(3, $result['total']);

        $ivy = array_values(array_filter($result['rows'], static fn (array $r): bool => $r['first_name'] === 'Ivy'))[0];
        $this->assertTrue($ivy['elsewhere']);
        $this->assertArrayNotHasKey('institutions', $ivy, 'no other institution is named');
    }

    public function testTheListIsFilteredSearchedSortedAndPaged(): void
    {
        $this->person('student', 'Ann');
        $this->person('student', 'Ben', null, false);
        $this->person('instructor', 'Cy');

        $this->assertSame(['Ann', 'Ben'], $this->names($this->service->list($this->institution, ['role' => 'student'], 0)));
        $this->assertSame(['Ben'], $this->names($this->service->list($this->institution, ['status' => 'deactivated'], 0)));
        $this->assertSame(['Ada'], $this->names($this->service->list($this->institution, ['q' => 'ada@people'], 0)));
        $this->assertSame(['Cy', 'Ben', 'Ann', 'Ada'], $this->names($this->service->list($this->institution, ['sort' => 'name', 'dir' => 'desc'], 0)));

        $this->assertSame(4, $this->service->list($this->institution, ['role' => 'admin'], 0)['total'], 'the system administrator role is not a filter here');

        $page = $this->service->list($this->institution, ['page' => '99'], 0);
        $this->assertSame(1, $page['filters']->page);
    }

    public function testFlagsSaySelfLastAdministratorAndSisManaged(): void
    {
        $ann = $this->person('student', 'Ann');

        (new \App\Services\Flags\FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->actor);
        (new \App\Services\Sis\SisConnectionService())->save($this->institution, true, [], false, $this->actor);
        db_connect()->table('sis_links')->insert(['institution_id' => $this->institution, 'entity' => 'person', 'external_id' => 'S-1', 'local_id' => $ann->id, 'created_at' => date('Y-m-d H:i:s')]);

        $rows = [];

        foreach ($this->service->list($this->institution, [], (int) $this->actor->id)['rows'] as $row) {
            $rows[$row['first_name']] = $row;
        }

        $this->assertTrue($rows['Ada']['self']);
        $this->assertTrue($rows['Ada']['last_admin']);
        $this->assertFalse($rows['Ann']['last_admin']);
        $this->assertTrue($rows['Ann']['sis']);
        $this->assertFalse($rows['Ada']['sis']);
    }

    /*
    |--------------------------------------------------------------------------
    | Inviting
    |--------------------------------------------------------------------------
    */

    public function testSomeoneIsInvitedWithTheirRoleAndEmailed(): void
    {
        $result = $this->service->invite($this->institution, 'instructor', ['first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => 'ivy@school.test'], $this->actor);

        $this->assertSame('ivy@school.test', $result['email']);
        $this->assertNull($result['email_error']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('instructor', db_connect()->table('institution_invitations')->where('email', 'ivy@school.test')->get()->getRowArray()['role']);
        $this->assertSame(1, $this->service->openInvitations($this->institution));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadInvitations(): iterable
    {
        yield 'no first name' => [['first_name' => '', 'last_name' => 'Tan', 'email' => 'a@b.test', 'role' => 'student'], 'first_name'];
        yield 'no last name' => [['first_name' => 'Ivy', 'last_name' => '', 'email' => 'a@b.test', 'role' => 'student'], 'last_name'];
        yield 'a bad email' => [['first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => 'not-an-email', 'role' => 'student'], 'email'];
        yield 'no role' => [['first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => 'a@b.test', 'role' => ''], 'role'];
        yield 'a system administrator' => [['first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => 'a@b.test', 'role' => 'admin'], 'role'];
    }

    /**
     * @param array<string, string> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadInvitations')]
    public function testABadInvitationIsRefusedBesideTheField(array $input, string $field): void
    {
        $e = $this->refused(fn () => $this->service->invite($this->institution, $input['role'], $input, $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAStudentInvitationIsRefusedWhenAllSeatsAreInUse(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('Tiny', 1));
        $this->person('student', 'Ann');

        $e = $this->refused(fn () => $this->service->invite($this->institution, 'student', ['first_name' => 'Ben', 'last_name' => 'Ng', 'email' => 'ben@school.test'], $this->actor));

        $this->assertStringContainsString('seats', $e->getMessage());
    }

    public function testAnInvitationCanBeSentAgainAndCancelledButNotByAnotherInstitution(): void
    {
        $this->service->invite($this->institution, 'student', ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@school.test'], $this->actor);
        $id = (int) db_connect()->table('institution_invitations')->where('email', 'ann@school.test')->get()->getRowArray()['id'];

        $again = $this->service->resend($this->institution, $id, $this->actor);
        $this->assertSame('ann@school.test', $again['email']);

        $newId = (int) db_connect()->table('institution_invitations')->where('email', 'ann@school.test')->orderBy('id', 'desc')->get()->getRowArray()['id'];
        $this->service->revoke($this->institution, $newId, $this->actor);
        $this->assertSame(0, $this->service->openInvitations($this->institution));

        $this->expectException(PageNotFoundException::class);
        $this->service->revoke($this->other, $newId, $this->actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    public function testNameAndEmailAreChangedAndAudited(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->service->update($this->institution, (int) $ann->id, ['first_name' => 'Anna', 'last_name' => 'Lee', 'email' => 'anna@school.test'], $this->actor);

        $row = $this->service->list($this->institution, ['q' => 'anna@school'], 0)['rows'][0];
        $this->assertSame('Anna Lee', $row['name']);
        $this->assertSame(1, $this->audit('user.updated'));
    }

    public function testAnEmailAlreadyInUseIsRefusedBesideTheField(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->person('student', 'Ben');

        $e = $this->refused(fn () => $this->service->update($this->institution, (int) $ann->id, ['first_name' => 'Ann', 'last_name' => 'Person', 'email' => 'ben@people.test'], $this->actor));

        $this->assertArrayHasKey('email', $e->errors());
    }

    public function testAPersonTheSisManagesKeepsTheirNameAndEmail(): void
    {
        $ann = $this->person('student', 'Ann');

        (new \App\Services\Flags\FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->actor);
        (new \App\Services\Sis\SisConnectionService())->save($this->institution, true, [], false, $this->actor);
        db_connect()->table('sis_links')->insert(['institution_id' => $this->institution, 'entity' => 'person', 'external_id' => 'S-1', 'local_id' => $ann->id, 'created_at' => date('Y-m-d H:i:s')]);

        $e = $this->refused(fn () => $this->service->update($this->institution, (int) $ann->id, ['first_name' => 'Anna', 'last_name' => 'Person', 'email' => 'ann@people.test'], $this->actor));

        $this->assertStringContainsString('student information system', $e->getMessage());
    }

    /*
    |--------------------------------------------------------------------------
    | Switching accounts on and off
    |--------------------------------------------------------------------------
    */

    public function testAnAccountIsDeactivatedAndReactivatedAndAudited(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->service->deactivate($this->institution, (int) $ann->id, $this->actor);
        $this->assertSame(0, (int) db_connect()->table('users')->where('id', $ann->id)->get()->getRowArray()['active']);

        $this->service->reactivate($this->institution, (int) $ann->id, $this->actor);
        $this->assertSame(1, (int) db_connect()->table('users')->where('id', $ann->id)->get()->getRowArray()['active']);

        $this->assertSame(1, $this->audit('user.deactivated'));
        $this->assertSame(1, $this->audit('user.reactivated'));
    }

    public function testNobodyDeactivatesThemselvesOrTheLastAdministrator(): void
    {
        $e = $this->refused(fn () => $this->service->deactivate($this->institution, (int) $this->actor->id, $this->actor));
        $this->assertNotSame('', $e->getMessage());

        // Someone else acting (a service call, say) still cannot remove the only administrator.
        $someone = $this->person('instructor', 'Ivy');
        $e       = $this->refused(fn () => $this->service->deactivate($this->institution, (int) $this->actor->id, $someone));
        $this->assertStringContainsString('last active administrator', $e->getMessage());
    }

    public function testASecondAdministratorCanBeDeactivatedWhileAnotherRemains(): void
    {
        $second = $this->person('institutional_admin', 'Sam');

        $this->service->deactivate($this->institution, (int) $second->id, $this->actor);

        $this->assertSame(0, (int) db_connect()->table('users')->where('id', $second->id)->get()->getRowArray()['active']);
    }

    public function testAReactivatedStudentNeedsASeat(): void
    {
        PeopleFactory::subscribe($this->institution, PeopleFactory::plan('Tiny', 1));
        $this->person('student', 'Ann');
        $ben = $this->person('student', 'Ben', null, false);

        db_connect()->table('user_account_controls')->insert(['user_id' => $ben->id, 'deactivated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);

        $e = $this->refused(fn () => $this->service->reactivate($this->institution, (int) $ben->id, $this->actor));

        $this->assertStringContainsString('seats', $e->getMessage());
    }

    public function testSigningOutAndAPasswordLink(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->service->signOut($this->institution, (int) $ann->id, $this->actor);
        $this->assertSame(1, $this->audit('user.signed_out'));

        $result = $this->service->resetPassword($this->institution, (int) $ann->id, $this->actor);
        $this->assertSame('ann@people.test', $result['email']);
        $this->assertCount(1, $this->mailer->sent);
    }

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    public function testRolesAreReplacedAndAudited(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->service->setRoles($this->institution, (int) $ann->id, ['instructor', 'parent'], $this->actor);

        $groups = array_column(db_connect()->table('auth_groups_users')->where('user_id', $ann->id)->get()->getResultArray(), 'group');
        sort($groups);
        $this->assertSame(['instructor', 'parent'], $groups);
        $this->assertSame(1, $this->audit('user.roles_changed'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function provideBadRoles(): iterable
    {
        yield 'none' => [[]];
        yield 'a system administrator' => [['admin']];
        yield 'something that is not a role' => [['superuser']];
    }

    /**
     * @param list<string> $roles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadRoles')]
    public function testBadRolesAreRefused(array $roles): void
    {
        $ann = $this->person('student', 'Ann');

        $e = $this->refused(fn () => $this->service->setRoles($this->institution, (int) $ann->id, $roles, $this->actor));

        $this->assertArrayHasKey('roles', $e->errors());
    }

    public function testTheLastAdministratorKeepsTheRoleAndNobodyTakesItFromThemselves(): void
    {
        $second = $this->person('institutional_admin', 'Sam');

        $e = $this->refused(fn () => $this->service->setRoles($this->institution, (int) $this->actor->id, ['instructor'], $this->actor));
        $this->assertStringContainsString('yourself', $e->errors()['roles']);

        // Sam is also an administrator, so Ada may be demoted by Sam; but not when Sam is the only one left.
        $this->service->setRoles($this->institution, (int) $this->actor->id, ['instructor'], $second);

        $e = $this->refused(fn () => $this->service->setRoles($this->institution, (int) $second->id, ['instructor'], $this->actor));
        $this->assertNotSame('', $e->getMessage());
    }

    public function testEndingTheChairpersonRoleOrAccountEndsTheAssignments(): void
    {
        $chair = $this->person('chairperson', 'Cal');
        $sub   = (int) db_connect()->query('SELECT sd.id FROM sub_departments sd JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ? LIMIT 1', [$this->institution])->getRow()->id;

        db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $sub, 'chairperson_user_id' => $chair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);

        $this->service->setRoles($this->institution, (int) $chair->id, ['instructor'], $this->actor);

        $this->assertSame('revoked', db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $chair->id)->get()->getRowArray()['status']);
        $this->assertSame(1, $this->audit('structure.chairperson_revoked'));

        $other = $this->person('chairperson', 'Dee');
        db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $sub, 'chairperson_user_id' => $other->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);

        $this->service->deactivate($this->institution, (int) $other->id, $this->actor);

        $this->assertSame('revoked', db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $other->id)->get()->getRowArray()['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared accounts, foreign people, bulk
    |--------------------------------------------------------------------------
    */

    public function testAnAccountSharedWithAnotherInstitutionCanOnlyBeRemoved(): void
    {
        $ivy = $this->person('instructor', 'Ivy');
        db_connect()->table('institution_users')->insert(['institution_id' => $this->other, 'user_id' => $ivy->id, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $ivy->id;

        foreach ([
            fn () => $this->service->update($this->institution, $id, ['first_name' => 'Ivy', 'last_name' => 'Person', 'email' => 'new@people.test'], $this->actor),
            fn () => $this->service->deactivate($this->institution, $id, $this->actor),
            fn () => $this->service->setRoles($this->institution, $id, ['student'], $this->actor),
            fn () => $this->service->signOut($this->institution, $id, $this->actor),
            fn () => $this->service->resetPassword($this->institution, $id, $this->actor),
        ] as $do) {
            $e = $this->refused($do);
            $this->assertStringContainsString('another institution', $e->getMessage());
        }

        $this->service->removeFromInstitution($this->institution, $id, $this->actor);

        $this->assertSame(1, db_connect()->table('users')->where(['id' => $id, 'active' => 1])->countAllResults(), 'still active');
        $this->assertSame(0, db_connect()->table('institution_users')->where(['user_id' => $id, 'institution_id' => $this->institution])->countAllResults());
        $this->assertSame(1, db_connect()->table('institution_users')->where(['user_id' => $id, 'institution_id' => $this->other])->countAllResults());
    }

    public function testSomeoneWhoBelongsOnlyHereCannotBeRemoved(): void
    {
        $ann = $this->person('student', 'Ann');

        $e = $this->refused(fn () => $this->service->removeFromInstitution($this->institution, (int) $ann->id, $this->actor));

        $this->assertStringContainsString('Deactivate', $e->getMessage());
    }

    public function testAnotherInstitutionsPeopleAndSystemAdministratorsAreNotFound(): void
    {
        $zed   = $this->person('student', 'Zed', $this->other);
        $admin = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@people.test');

        foreach ([(int) $zed->id, (int) $admin->id, 999999] as $id) {
            foreach ([
                fn () => $this->service->update($this->institution, $id, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.test'], $this->actor),
                fn () => $this->service->deactivate($this->institution, $id, $this->actor),
                fn () => $this->service->reactivate($this->institution, $id, $this->actor),
                fn () => $this->service->setRoles($this->institution, $id, ['student'], $this->actor),
                fn () => $this->service->removeFromInstitution($this->institution, $id, $this->actor),
            ] as $do) {
                try {
                    $do();
                    $this->fail('A foreign id must be a 404.');
                } catch (PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function testBulkDoesWhatItCanAndSaysWhyNot(): void
    {
        $ann = $this->person('student', 'Ann');
        $ben = $this->person('student', 'Ben');
        $zed = $this->person('student', 'Zed', $this->other);

        $result = $this->service->bulk($this->institution, 'deactivate', [(int) $ann->id, (int) $ben->id, (int) $zed->id, (int) $this->actor->id], $this->actor);

        $this->assertSame(2, $result['done']);
        $this->assertCount(2, $result['skipped']);
        $this->assertSame(1, (int) db_connect()->table('users')->where('id', $zed->id)->get()->getRowArray()['active']);

        $again = $this->service->bulk($this->institution, 'reactivate', [(int) $ann->id], $this->actor);
        $this->assertSame(1, $again['done']);

        $this->refused(fn () => $this->service->bulk($this->institution, 'deactivate', [], $this->actor));
        $this->refused(fn () => $this->service->bulk($this->institution, 'delete', [1], $this->actor));
    }

    public function testTheExportNamesOnlyThisInstitution(): void
    {
        $ivy = $this->person('instructor', 'Ivy');
        db_connect()->table('institution_users')->insert(['institution_id' => $this->other, 'user_id' => $ivy->id, 'created_at' => date('Y-m-d H:i:s')]);

        $rows = iterator_to_array($this->service->each($this->institution, [], 100), false);

        foreach ($rows as $row) {
            $this->assertSame([$this->institution], array_column($row['institutions'], 'id'));
        }

        $this->assertCount(2, $this->service->byIds($this->institution, [(int) $ivy->id, (int) $this->actor->id, 999999]));
    }
}

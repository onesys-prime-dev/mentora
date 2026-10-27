<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionSnapshot;
use App\Services\Institutions\PeopleService;
use App\Services\Security\SecuritySettings;
use App\Services\Users\UserAdminService;
use App\Services\Users\UserFilters;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The dean is a role like the others wherever roles are listed: the user filters
 * and labels, the roles an administrator can give (the system administrator on
 * the Users screen, the institution's administrator on People), the invitation,
 * the two-factor choices and the institution's people counts.
 *
 * @internal
 */
final class DeanRoleListsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;
    private User $institutionAdmin;
    private PeopleService $people;
    private UserAdminService $users;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->institution      = SisFixture::institution('DRL');
        $this->institutionAdmin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@drl.test', $this->institution);
        $this->people           = new PeopleService();
        $this->users            = new UserAdminService();
    }

    /** @return list<string> */
    private function roles(User $user): array
    {
        $groups = array_values(model(CodeIgniter\Shield\Models\UserModel::class)->findById($user->id)->getGroups());
        sort($groups);

        return $groups;
    }

    public function testTheFiltersAndLabelsKnowTheDean(): void
    {
        $this->assertContains('dean', UserFilters::ROLES);
        $this->assertSame('Dean', UserFilters::ROLE_LABELS['dean']);
        $this->assertSame(array_keys(UserFilters::ROLE_LABELS), UserFilters::ROLES, 'every role has a label, in the same order');
    }

    public function testTheSystemAdministratorMayGiveAndTakeTheDeanRole(): void
    {
        $this->assertContains('dean', UserAdminService::ASSIGNABLE_ROLES);

        $person = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@drl.test', $this->institution);

        $this->users->setRoles((int) $person->id, ['instructor', 'dean']);
        $this->assertSame(['dean', 'instructor'], $this->roles($person));

        $this->users->setRoles((int) $person->id, ['instructor']);
        $this->assertSame(['instructor'], $this->roles($person));
    }

    public function testTheInstitutionAdministratorMayGiveTheDeanRoleOnPeople(): void
    {
        $person = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@drl.test', $this->institution);

        $this->people->setRoles($this->institution, (int) $person->id, ['instructor', 'dean'], $this->institutionAdmin);

        $this->assertSame(['dean', 'instructor'], $this->roles($person));
    }

    public function testAnInvitationCanBeForADean(): void
    {
        $result = $this->people->invite($this->institution, 'dean', ['first_name' => 'Dana', 'last_name' => 'Cruz', 'email' => 'dana@drl.test'], $this->institutionAdmin);

        $this->assertNotNull($result['invitation_id']);
        $this->assertSame('dana@drl.test', $result['email']);

        $row = db_connect()->table('institution_invitations')->where('id', $result['invitation_id'])->get()->getRowArray();
        $this->assertSame('dean', $row['role'], 'the invitation remembers the role it is for');
    }

    public function testAnUnknownRoleIsStillRefused(): void
    {
        $person = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@drl.test', $this->institution);

        $this->expectException(InvalidArgumentException::class);

        $this->users->setRoles((int) $person->id, ['instructor', 'principal']);
    }

    public function testDeansCanBeAskedForAnEmailedCodeAtSignIn(): void
    {
        $this->assertContains('dean', SecuritySettings::TWO_FACTOR_CHOICES);
    }

    public function testTheInstitutionHomeCountsItsDeans(): void
    {
        PeopleFactory::person('dean', 'Dana', 'Cruz', 'dana@drl.test', $this->institution);
        PeopleFactory::person('dean', 'Dean', 'Two', 'dean2@drl.test', $this->institution);
        PeopleFactory::person('student', 'Stu', 'Dent', 'stu@drl.test', $this->institution);

        $snapshot = (new InstitutionSnapshot())->for($this->institution);

        $this->assertSame(2, $snapshot['people']['dean']);
        $this->assertSame(1, $snapshot['people']['student']);
        $this->assertContains('dean', InstitutionSnapshot::ROLES);
    }
}

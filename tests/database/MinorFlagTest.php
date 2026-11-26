<?php

declare(strict_types=1);

use App\Services\Institutions\MinorFlag;
use App\Services\Messaging\MessagingContextResolver;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The minor flag (FR8): who may set it, on whom, what is audited, and that Messages reads it.
 *
 * @internal
 */
final class MinorFlagTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('MNRA');
        $this->other       = SisFixture::institution('MNRB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@minor.test', $this->institution);
        $this->student     = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@minor.test', $this->institution);

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_column(db_connect()->table('audit_logs')->select('action')->like('action', 'messages.minor_flag_', 'after')->orderBy('id')->get()->getResultArray(), 'action');
    }

    public function testAdminMarksStudentMinorAndAudits(): void
    {
        $flag = new MinorFlag();

        $this->assertFalse($flag->isMinor($this->institution, (int) $this->student->id));
        $this->assertTrue($flag->set($this->admin, $this->institution, (int) $this->student->id, true));
        $this->assertTrue($flag->isMinor($this->institution, (int) $this->student->id));
        $this->assertSame(['messages.minor_flag_set'], $this->actions());

        // Setting it again changes nothing and is not audited twice.
        $this->assertTrue($flag->set($this->admin, $this->institution, (int) $this->student->id, true));
        $this->assertSame(['messages.minor_flag_set'], $this->actions());

        $this->assertTrue($flag->set($this->admin, $this->institution, (int) $this->student->id, false));
        $this->assertFalse($flag->isMinor($this->institution, (int) $this->student->id));
        $this->assertSame(['messages.minor_flag_set', 'messages.minor_flag_cleared'], $this->actions());
    }

    public function testNonStudentCannotBeMarked(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@minor.test', $this->institution);

        $this->assertFalse((new MinorFlag())->set($this->admin, $this->institution, (int) $teacher->id, true));
        $this->assertFalse((new MinorFlag())->isMinor($this->institution, (int) $teacher->id));
        $this->assertSame([], $this->actions());
    }

    public function testFlagCanBeClearedAfterTheStudentRoleIsGone(): void
    {
        $flag = new MinorFlag();
        $flag->set($this->admin, $this->institution, (int) $this->student->id, true);
        db_connect()->table('auth_groups_users')->where('user_id', (int) $this->student->id)->where('group', 'student')->delete();

        $this->assertFalse($flag->set($this->admin, $this->institution, (int) $this->student->id, true));
        $this->assertTrue($flag->set($this->admin, $this->institution, (int) $this->student->id, false));
        $this->assertFalse($flag->isMinor($this->institution, (int) $this->student->id));
    }

    public function testRemovingTheStudentRoleClearsTheFlagWithAnAudit(): void
    {
        (new MinorFlag())->set($this->admin, $this->institution, (int) $this->student->id, true);

        (new \App\Services\Institutions\PeopleService())->setRoles($this->institution, (int) $this->student->id, ['instructor'], $this->admin);

        $this->assertFalse((new MinorFlag())->isMinor($this->institution, (int) $this->student->id));
        $this->assertSame(['messages.minor_flag_set', 'messages.minor_flag_cleared'], $this->actions());
    }

    public function testOtherInstitutionAdminRefused(): void
    {
        $foreign = PeopleFactory::person('institutional_admin', 'Fay', 'Foreign', 'fay@minor.test', $this->other);

        $this->assertFalse((new MinorFlag())->set($foreign, $this->institution, (int) $this->student->id, true));
        $this->assertFalse((new MinorFlag())->set($foreign, $this->other, (int) $this->student->id, true), 'the student is not a member there');
        $this->assertFalse((new MinorFlag())->isMinor($this->institution, (int) $this->student->id));
        $this->assertSame([], $this->actions());
    }

    public function testPartyCarriesMinor(): void
    {
        $this->assertFalse((new MessagingContextResolver())->party($this->student)->minor);

        (new MinorFlag())->set($this->admin, $this->institution, (int) $this->student->id, true);

        $this->assertTrue((new MessagingContextResolver())->party($this->student)->minor);
        $this->assertFalse((new MessagingContextResolver())->party($this->admin)->minor);
    }
}

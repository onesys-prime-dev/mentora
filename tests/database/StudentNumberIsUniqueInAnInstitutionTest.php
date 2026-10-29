<?php

declare(strict_types=1);

use App\Database\Migrations\StudentNumberIsUniqueInAnInstitution;
use App\Services\Sis\SisImporter;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

require_once APPPATH . 'Database/Migrations/2026-11-20-000004_StudentNumberIsUniqueInAnInstitution.php';

/**
 * A student number is unique within the institution that issued it (QA MISS-DB-01, TC-SA-55); two
 * institutions may issue the same one. The import says which row it refused and why, and the
 * database refuses a duplicate whatever writes it.
 *
 * @internal
 */
final class StudentNumberIsUniqueInAnInstitutionTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private string $folder;

    private int $institution;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->folder      = SisFixture::useFolder();
        $this->institution = SisFixture::institution();
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@example.com');

        SisFixture::connect($this->institution, $this->actor);
        SisFixture::priorSuccess($this->institution);
    }

    protected function tearDown(): void
    {
        SisFixture::cleanup($this->folder);

        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function import(string $students): \App\Services\Sis\SisImportResult
    {
        SisFixture::drop($this->institution, 'students', "external_id,email,first_name,last_name,student_number\n" . $students);

        return (new SisImporter())->run($this->institution, $this->actor, false, \App\Services\Sis\SisRunLog::MANUAL, new DateTimeImmutable('2026-10-10 09:00:00'));
    }

    /** @return array<string, mixed> */
    private function profileOf(string $email): array
    {
        $id = (int) db_connect()->table('auth_identities')->where('secret', $email)->get()->getRowArray()['user_id'];

        return db_connect()->table('user_profiles')->where('user_id', $id)->get()->getRowArray();
    }

    public function testTheImportRefusesASecondStudentWithTheSameNumber(): void
    {
        $result = $this->import("S1,ann@example.com,Ann,Lee,2026-001\nS2,ben@example.com,Ben,Ng,2026-001\n");

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $result->datasets['students']);
        $this->assertStringContainsString('2026-001', $result->issues[0]['reason']);
        $this->assertStringContainsString('already', $result->issues[0]['reason']);
        $this->assertSame('2026-001', $this->profileOf('ann@example.com')['student_number']);
        $this->assertSame($this->institution, (int) $this->profileOf('ann@example.com')['student_number_institution_id']);
    }

    public function testTheImportRefusesChangingANumberToOneAlreadyUsed(): void
    {
        $this->import("S1,ann@example.com,Ann,Lee,2026-001\nS2,ben@example.com,Ben,Ng,2026-002\n");

        $result = $this->import("S1,ann@example.com,Ann,Lee,2026-001\nS2,ben@example.com,Ben,Ng,2026-001\n");

        $this->assertSame(1, $result->datasets['students']['failed']);
        $this->assertSame('2026-002', $this->profileOf('ben@example.com')['student_number']);
    }

    public function testAStudentKeepsTheirOwnNumber(): void
    {
        $this->import("S1,ann@example.com,Ann,Lee,2026-001\n");

        $result = $this->import("S1,ann@example.com,Annie,Lee,2026-001\n");

        $this->assertSame(0, $result->datasets['students']['failed']);
        $this->assertSame('Annie', $this->profileOf('ann@example.com')['first_name']);
    }

    public function testTheDatabaseRefusesADuplicateInOneInstitution(): void
    {
        $this->import("S1,ann@example.com,Ann,Lee,2026-001\n");
        $ben = PeopleFactory::person('student', 'Ben', 'Ng', 'ben@example.com', $this->institution);

        try {
            $changed = db_connect()->table('user_profiles')->where('user_id', $ben->id)->update(['student_number' => '2026-001', 'student_number_institution_id' => $this->institution]);
        } catch (DatabaseException) {
            $changed = false;
        }

        $this->assertFalse($changed);
    }

    public function testTwoInstitutionsMayIssueTheSameNumber(): void
    {
        $this->import("S1,ann@example.com,Ann,Lee,2026-001\n");
        $other = PeopleFactory::institution('OTHER');
        $ben   = PeopleFactory::person('student', 'Ben', 'Ng', 'ben@example.com', $other);

        $this->assertTrue(db_connect()->table('user_profiles')->where('user_id', $ben->id)->update(['student_number' => '2026-001', 'student_number_institution_id' => $other]));
    }

    public function testTheMigrationRecordsTheIssuerAndLeavesExistingDuplicatesUnclaimed(): void
    {
        $other = PeopleFactory::institution('OTHER');
        $ann   = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', $this->institution);
        $ben   = PeopleFactory::person('student', 'Ben', 'Ng', 'ben@example.com', $this->institution);
        $cy    = PeopleFactory::person('student', 'Cy', 'Ho', 'cy@example.com', $other);

        $migration = new StudentNumberIsUniqueInAnInstitution();
        $migration->down();

        try {
            foreach ([$ann, $ben, $cy] as $student) {
                db_connect()->table('user_profiles')->where('user_id', $student->id)->update(['student_number' => '2026-001']);
            }
        } finally {
            $migration->up();
        }

        $issuer = static fn (User $u) => db_connect()->table('user_profiles')->where('user_id', $u->id)->get()->getRowArray()['student_number_institution_id'];

        $this->assertSame($this->institution, (int) $issuer($ann));
        $this->assertNull($issuer($ben), 'the later duplicate is left for the institution to correct');
        $this->assertSame('2026-001', db_connect()->table('user_profiles')->where('user_id', $ben->id)->get()->getRowArray()['student_number']);
        $this->assertSame($other, (int) $issuer($cy));
    }
}

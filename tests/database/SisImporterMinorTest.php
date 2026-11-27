<?php

declare(strict_types=1);

use App\Services\Institutions\MinorFlag;
use App\Services\Sis\SisImporter;
use App\Services\Sis\UploadConnections;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The optional `minor` column of an uploaded students file.
 *
 * @internal
 */
final class SisImporterMinorTest extends DatabaseTestCase
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

        $this->folder      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-minor-' . bin2hex(random_bytes(4));
        $this->institution = SisFixture::institution('MNRI');
        $this->actor       = PeopleFactory::person('admin', 'Root', 'Admin', 'root@minor-import.test');
        mkdir($this->folder, 0770, true);
        SisFixture::priorSuccess($this->institution);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: []);
        @rmdir($this->folder);
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function import(string $csv): \App\Services\Sis\SisImportResult
    {
        file_put_contents($this->folder . DIRECTORY_SEPARATOR . 'students.csv', $csv);

        return (new SisImporter(new UploadConnections($this->folder), null, false))->run($this->institution, $this->actor, false, 'manual', new DateTimeImmutable('2026-10-10 09:00:00'));
    }

    private function isMinor(string $email): bool
    {
        $id = (int) db_connect()->table('auth_identities')->select('user_id')->where('secret', $email)->get()->getRow('user_id');

        return (new MinorFlag())->isMinor($this->institution, $id);
    }

    public function testCsvMinorColumnSetsFlag(): void
    {
        $result = $this->import("email,first_name,last_name,minor\nann@minor-import.test,Ann,Lee,yes\nben@minor-import.test,Ben,Ng,no\ncy@minor-import.test,Cy,Ho,\nde@minor-import.test,De,Po,1\n");

        $this->assertTrue($result->ok, $result->message);
        $this->assertSame(4, $result->datasets['students']['created']);
        $this->assertTrue($this->isMinor('ann@minor-import.test'));
        $this->assertFalse($this->isMinor('ben@minor-import.test'));
        $this->assertFalse($this->isMinor('cy@minor-import.test'));
        $this->assertTrue($this->isMinor('de@minor-import.test'));
    }

    public function testExistingStudentIsUpdatedAndBlankLeavesItAlone(): void
    {
        $this->import("email,first_name,last_name,minor\nann@minor-import.test,Ann,Lee,yes\n");

        $result = $this->import("email,first_name,last_name,minor\nann@minor-import.test,Ann,Lee,\n");
        $this->assertSame(1, $result->datasets['students']['skipped']);
        $this->assertTrue($this->isMinor('ann@minor-import.test'));

        $result = $this->import("email,first_name,last_name,minor\nann@minor-import.test,Ann,Lee,no\n");
        $this->assertSame(1, $result->datasets['students']['updated']);
        $this->assertFalse($this->isMinor('ann@minor-import.test'));
    }

    public function testUnknownMinorValueRejectsTheRow(): void
    {
        $result = $this->import("email,first_name,last_name,minor\nann@minor-import.test,Ann,Lee,maybe\nben@minor-import.test,Ben,Ng,yes\n");

        $this->assertSame(1, $result->datasets['students']['created']);
        $this->assertSame(1, $result->datasets['students']['failed']);
    }

    public function testAFailedRowLeavesTheFlagAndTheAuditAlone(): void
    {
        $this->import("student_number,email,first_name,last_name
S-1,ann@minor-import.test,Ann,Lee
S-2,ben@minor-import.test,Ben,Ng
");

        // S-1 is Ann; the new address already belongs to Ben, so the row fails.
        $result = $this->import("student_number,email,first_name,last_name,minor
S-1,ben@minor-import.test,Ann,Lee,yes
");

        $this->assertSame(1, $result->datasets['students']['failed']);
        $this->assertFalse($this->isMinor('ann@minor-import.test'));
        $this->assertSame(0, db_connect()->table('audit_logs')->like('action', 'messages.minor_flag', 'after')->countAllResults());
    }

    public function testAnInstitutionalAdminUploadSetsTheFlag(): void
    {
        $this->actor = PeopleFactory::person('institutional_admin', 'Ia', 'Admin', 'ia@minor-import.test', $this->institution);

        $this->import("email,first_name,last_name,minor
ann@minor-import.test,Ann,Lee,yes
");

        $this->assertTrue($this->isMinor('ann@minor-import.test'));
    }

    public function testFileWithoutTheColumnStillImports(): void
    {
        $result = $this->import("email,first_name,last_name\nann@minor-import.test,Ann,Lee\n");

        $this->assertSame(1, $result->datasets['students']['created']);
        $this->assertFalse($this->isMinor('ann@minor-import.test'));
    }
}

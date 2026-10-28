<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\TestEnv;

/**
 * Purging an institution removes its accounts, their files on disk and its export zips, and keeps the audit
 * log (audit decisions 10.1–10.5).
 *
 * @internal
 */
final class InstitutionPurgeFilesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private InstitutionService $service;

    private User $admin;

    private string $exports;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->exports = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-purge-exports-' . bin2hex(random_bytes(4));
        mkdir($this->exports);
        TestEnv::set('export.path', $this->exports);

        $this->service = new InstitutionService();
        $this->admin   = CourseFactory::user('admin');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->exports . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }

        @rmdir($this->exports);
        TestEnv::restore();
        CourseFactory::removeCreatedFiles();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** A deleted institution; returns its id. */
    private function deletedInstitution(): int
    {
        $offering = CourseFactory::offering();
        $id       = (int) db_connect()->query('SELECT d.institution_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE o.id = ?', [$offering])->getRow()->institution_id;
        db_connect()->table('institutions')->where('id', $id)->update(['status' => 'past', 'deleted_at' => '2026-01-01 08:00:00']);

        return $id;
    }

    private function member(int $institution, string $group = 'student'): User
    {
        $user = CourseFactory::user($group);
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id]);

        return $user;
    }

    /** @return array{id: int, path: string} */
    private function fileOf(User $owner): array
    {
        return CourseFactory::file($owner);
    }

    private function zip(int $institution): string
    {
        $name = 'inst-' . bin2hex(random_bytes(3)) . '.zip';
        file_put_contents($this->exports . DIRECTORY_SEPARATOR . $name, 'zip');
        db_connect()->table('institution_exports')->insert(['institution_id' => $institution, 'status' => 'ready', 'file_name' => $name, 'created_at' => '2026-01-01 08:00:00', 'expires_at' => '2030-01-01 08:00:00']);

        return $this->exports . DIRECTORY_SEPARATOR . $name;
    }

    private function rows(string $table, string $column, int $id): int
    {
        return db_connect()->table($table)->where($column, $id)->countAllResults();
    }

    public function testAccountsTheirFilesOnDiskAndExportZipsAreRemoved(): void
    {
        $institution = $this->deletedInstitution();
        $ann         = $this->member($institution);
        $tess        = $this->member($institution, 'instructor');
        $annFile     = $this->fileOf($ann);
        $tessFile    = $this->fileOf($tess);
        $zip         = $this->zip($institution);

        $this->service->purge($institution, null, $this->admin);

        $this->assertSame(0, $this->rows('users', 'id', (int) $ann->id) + $this->rows('users', 'id', (int) $tess->id), 'the accounts are gone');
        $this->assertSame(0, $this->rows('files', 'id', $annFile['id']) + $this->rows('files', 'id', $tessFile['id']));
        $this->assertFileDoesNotExist($annFile['path']);
        $this->assertFileDoesNotExist($tessFile['path']);
        $this->assertFileDoesNotExist($zip);
    }

    public function testAnotherInstitutionsFilesAndAccountsStay(): void
    {
        $doomed = $this->deletedInstitution();
        $this->member($doomed);
        $other     = $this->deletedInstitution();
        $neighbour = $this->member($other);
        $kept      = $this->fileOf($neighbour);
        $otherZip  = $this->zip($other);

        $this->service->purge($doomed, null, $this->admin);

        $this->assertSame(1, $this->rows('users', 'id', (int) $neighbour->id));
        $this->assertFileExists($kept['path']);
        $this->assertFileExists($otherZip);
    }

    public function testTheLogoGoesWithTheInstitution(): void
    {
        $institution = $this->deletedInstitution();
        $logo        = $this->fileOf($this->admin); // uploaded by a system administrator, who stays
        db_connect()->table('institutions')->where('id', $institution)->update(['logo_file_id' => $logo['id']]);

        $this->service->purge($institution, null, $this->admin);

        $this->assertSame(0, $this->rows('files', 'id', $logo['id']));
        $this->assertFileDoesNotExist($logo['path']);
        $this->assertSame(1, $this->rows('users', 'id', (int) $this->admin->id));
    }

    public function testALogoTheInstitutionsOwnAdministratorUploadedGoesOnce(): void
    {
        $institution = $this->deletedInstitution();
        $ada         = $this->member($institution, 'institutional_admin');
        $logo        = $this->fileOf($ada); // both the institution's logo and one of its account's files
        db_connect()->table('institutions')->where('id', $institution)->update(['logo_file_id' => $logo['id']]);

        $this->service->purge($institution, null, $this->admin);

        $this->assertSame(0, $this->rows('files', 'id', $logo['id']));
        $this->assertFileDoesNotExist($logo['path']);
        $this->assertSame(0, $this->rows('users', 'id', (int) $ada->id));
        $this->assertSame('[]', (string) (service('settings')->get('Purge.pendingFiles') ?? '[]'), 'the path is deleted once, so nothing is left to retry');
    }

    public function testAuditEntriesStay(): void
    {
        $institution = $this->deletedInstitution();
        $ann         = $this->member($institution);
        db_connect()->table('audit_logs')->insert(['action' => 'enrolment.added', 'actor_user_id' => $ann->id, 'actor_label' => 'ann', 'institution_id' => $institution, 'created_at' => '2026-01-01 08:00:00']);

        $this->service->purge($institution, null, $this->admin);

        $this->assertSame(1, $this->rows('audit_logs', 'actor_user_id', (int) $ann->id));
        $purged = db_connect()->table('audit_logs')->where('action', 'institution.purged')->get()->getRowArray();
        $this->assertSame(1, json_decode((string) $purged['old_values'], true)['accounts'] ?? null);
    }

    public function testASystemAdministratorIsNeverRemoved(): void
    {
        $institution = $this->deletedInstitution();
        $root        = $this->member($institution, 'admin');

        $this->service->purge($institution, null, $this->admin);

        $this->assertSame(1, $this->rows('users', 'id', (int) $root->id));
    }

    public function testAFileThatCouldNotBeDeletedIsRetriedByTheNextRun(): void
    {
        $institution = $this->deletedInstitution();
        $ann         = $this->member($institution);
        $file        = $this->fileOf($ann);

        // Something holds the path (a folder where the file was), so this run cannot delete it.
        unlink($file['path']);
        mkdir($file['path']);

        $this->service->purge($institution, null, $this->admin);

        $pending = json_decode((string) setting('Purge.pendingFiles'), true);
        $this->assertContains($file['path'], $pending);

        rmdir($file['path']);
        file_put_contents($file['path'], 'left behind');
        $this->service->purgeExpired(new DateTimeImmutable('2026-10-12 12:00:00'));

        $this->assertFileDoesNotExist($file['path']);
        $this->assertSame([], json_decode((string) setting('Purge.pendingFiles'), true));
    }
}

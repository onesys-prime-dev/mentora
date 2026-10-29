<?php

declare(strict_types=1);

use App\Services\Institutions\CsvUploadService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The CSV upload pages: access, the upload step and its templates, the
 * kept preview and applying it (behind the password), starting over, foreign
 * tokens answering 404, the SIS taking precedence, and a read-only
 * institution. (The browser's file upload itself is in the e2e case; PHP's
 * own upload check cannot be satisfied from a feature test.)
 *
 * @internal
 */
final class InstitutionCsvUploadTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->base               = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mentora-csv-feature-' . bin2hex(random_bytes(4));
        \Tests\Support\TestEnv::set('upload.csvPath', $this->base);

        $this->institution = SisFixture::institution('UPFA');
        $this->other       = SisFixture::institution('UPFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@upf.example', $this->institution);

        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        \Tests\Support\TestEnv::restore();

        foreach (glob($this->base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            @rmdir($dir);
        }

        foreach (glob($this->base . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->base);

        parent::tearDown();
    }

    private function signIn(User $user, bool $confirmed = true): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $user->id, 'at' => time()];
        } else {
            unset($_SESSION['reauth']);
        }

        $this->withSession();
    }

    private function keep(?int $institution = null): string
    {
        $institution ??= $this->institution;

        return (new CsvUploadService($this->base))->preview($institution, ['students' => SisFixture::STUDENTS, 'courses' => SisFixture::COURSES], $this->admin)['token'];
    }

    private function students(): int
    {
        return db_connect()->table('sis_links')->where(['institution_id' => $this->institution, 'entity' => 'person'])->countAllResults();
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $token  = $this->keep();
        $before = $this->students();
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@upf.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/people/upload')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', "institution/people/upload/{$token}")->response()->getStatusCode());

        $this->step('POST', "institution/people/upload/{$token}/apply");

        $this->assertSame($before, $this->students());
    }

    public function testTheUploadStepShowsTheStepperTheFilesAndTemplates(): void
    {
        $body = $this->step('GET', 'institution/people/upload')->response()->getBody();

        $this->assertStringContainsString('Upload from CSV', $body);
        $this->assertStringContainsString('data-stepper', $body);
        $this->assertStringContainsString('Preview the changes', $body);

        foreach (CsvUploadService::datasets() as $dataset) {
            $this->assertStringContainsString("name=\"{$dataset}\"", $body);
            $this->assertStringContainsString("people/upload/template/{$dataset}", html_entity_decode($body));
        }
    }

    public function testATemplateIsAHeaderRowDownload(): void
    {
        $response = $this->step('GET', 'institution/people/upload/template/students')->response();

        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('students.csv', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame("external_id,email,first_name,last_name,student_number\r\n", $response->getBody());
    }

    public function testAnUnknownTemplateIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('GET', 'institution/people/upload/template/grades');
    }

    public function testTheKeptPreviewShowsWhatWillHappenAndThatNothingIsSavedYet(): void
    {
        $token = $this->keep();

        $body = $this->step('GET', "institution/people/upload/{$token}")->response()->getBody();

        $this->assertStringContainsString('data-upload-totals', $body);
        $this->assertStringContainsString('Nothing has been saved yet.', $body);
        $this->assertStringContainsString('data-upload-file="students"', $body);
        $this->assertStringContainsString('data-upload-issues', $body, 'the bad row is listed');
        $this->assertMatchesRegularExpression('/Apply \d+ changes?/', $body);
        $this->assertStringContainsString('Start over', $body);
    }

    /**
     * A kept preview of a staff file that lists nobody, in an institution where a chairperson was assigned by an
     * earlier upload of one: applying it would end that chairperson and nothing else. (A test transaction cannot be
     * rolled back by a preview, so this only reads what the preview said; that a preview really writes nothing is
     * proven in SisImporterRollbackTest.)
     */
    private function keepAFileThatEndsAChairperson(): string
    {
        $service = new CsvUploadService($this->base);
        $db      = db_connect();

        $people = "external_id,email,first_name,last_name\nI2,ian@example.com,Ian,Ong\n";
        $header = "role,person_external_id,department_code,sub_department_code,course_external_id,section\n";
        $sub    = (string) $db->table('sub_departments sd')->select('sd.code')->join('departments d', 'd.id = sd.department_id')->where('d.institution_id', $this->institution)->get()->getRow('code');

        // First upload: the person, then the chairperson, applied for real.
        $service->apply($this->institution, $service->preview($this->institution, ['instructors' => $people], $this->admin)['token'], $this->admin);
        $ian = (int) $db->table('auth_identities')->where('secret', 'ian@example.com')->get()->getRow('user_id');
        $db->table('auth_groups_users')->insert(['user_id' => $ian, 'group' => 'chairperson', 'created_at' => date('Y-m-d H:i:s')]);
        $service->apply($this->institution, $service->preview($this->institution, ['staff_assignments' => $header . "chairperson,I2,,{$sub},,\n"], $this->admin)['token'], $this->admin);

        $this->assertSame(1, $db->table('chairperson_assignments')->where('status', 'active')->countAllResults(), 'assigned by the first upload');

        // Second upload: the same kind of file, now listing nobody.
        return $service->preview($this->institution, ['staff_assignments' => $header], $this->admin)['token'];
    }

    public function testThePreviewSaysOnItsOwnWhatWouldBeEndedAndOffersToApplyIt(): void
    {
        $token = $this->keepAFileThatEndsAChairperson();

        $body = html_entity_decode($this->step('GET', "institution/people/upload/{$token}")->response()->getBody());

        $this->assertStringContainsString('Will be ended', $body, 'a card of its own: somebody losing a job is not an update');
        $this->assertMatchesRegularExpression('/data-upload-file="staff_assignments".*?1 to end/s', $body);
        $this->assertStringContainsString('0 to update', $body);
        $this->assertStringContainsString('grid-cols-5', $body, 'five cards when there is something to end');
        $this->assertMatchesRegularExpression('/Apply 1 changes?/', $body, 'ending is the only change, and it can still be applied');
    }

    public function testThePreviewSaysWhatClassTimesWouldBeRemovedAndOffersToApplyIt(): void
    {
        $service = new CsvUploadService($this->base);
        $header  = "course_external_id,section,day_of_week,start_time,end_time,room,delivery_mode\n";
        SisFixture::course($this->institution, 'C1', 'IT101');
        SisFixture::course($this->institution, 'C2', 'IT102');

        // First upload: times for both sections. Second: only C2, unchanged, so C1's times would go.
        $service->apply($this->institution, $service->preview($this->institution, ['class_schedules' => $header . "C1,A,mon,09:00,10:00,,\nC2,A,tue,09:00,10:00,,\n"], $this->admin)['token'], $this->admin);
        $token = $service->preview($this->institution, ['class_schedules' => $header . "C2,A,tue,09:00,10:00,,\n"], $this->admin)['token'];

        $body = html_entity_decode($this->step('GET', "institution/people/upload/{$token}")->response()->getBody());

        $this->assertStringContainsString('Class times removed', $body, 'a card of its own');
        $this->assertMatchesRegularExpression('/data-upload-file="class_schedules".*?1 to remove/s', $body);
        $this->assertMatchesRegularExpression('/Apply 1 changes?/', $body, 'the removal is the only change, and it can be applied');
    }

    public function testAPreviewThatEndsNothingShowsNoEndedCard(): void
    {
        $body = $this->step('GET', 'institution/people/upload/' . $this->keep())->response()->getBody();

        $this->assertStringNotContainsString('Will be ended', $body);
        $this->assertStringNotContainsString('to end', $body);
        $this->assertStringContainsString('grid-cols-4', $body);
    }

    public function testApplyingNeedsThePasswordThenImportsAndSaysWhatHappened(): void
    {
        $token = $this->keep();
        $this->signIn($this->admin, false);

        $refused = $this->step('POST', "institution/people/upload/{$token}/apply");

        $this->assertStringEndsWith('/reauth', $refused->response()->getHeaderLine('Location'));
        $this->assertNotNull((new CsvUploadService($this->base))->kept($this->institution, $token), 'still waiting');

        $this->signIn($this->admin, true);
        $done = $this->step('POST', "institution/people/upload/{$token}/apply");

        $this->assertStringEndsWith('institution/people', $done->response()->getHeaderLine('Location'));

        $page = $this->step('GET', 'institution/people')->response()->getBody();
        $this->assertStringContainsString('The upload was applied', $page);

        // The files were removed with it.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('GET', "institution/people/upload/{$token}");
    }

    public function testStartingOverDiscardsThePreview(): void
    {
        $token = $this->keep();

        $this->step('POST', "institution/people/upload/{$token}/discard");

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('GET', "institution/people/upload/{$token}");
    }

    public function testAnotherInstitutionsPreviewIsNeverShownOrApplied(): void
    {
        $token = $this->keep($this->other);

        foreach ([['GET', "institution/people/upload/{$token}"], ['POST', "institution/people/upload/{$token}/apply"], ['POST', "institution/people/upload/{$token}/discard"]] as [$method, $uri]) {
            try {
                $this->call($method, $uri);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull((new CsvUploadService($this->base))->kept($this->other, $token), 'still kept, untouched');
    }

    public function testWhileTheSisIsConnectedTheStepIsLockedAndApplyingIsRefused(): void
    {
        $token  = $this->keep();
        $before = $this->students();
        SisFixture::connect($this->institution, $this->admin);

        $page = $this->step('GET', 'institution/people/upload')->response()->getBody();
        $this->assertStringContainsString('data-lock="sis"', $page);
        $this->assertStringContainsString('disabled', $page);

        $this->step('POST', "institution/people/upload/{$token}/apply");

        $this->assertSame($before, $this->students());
    }

    public function testAReadOnlyInstitutionCannotApply(): void
    {
        $token  = $this->keep();
        $before = $this->students();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $page = $this->step('GET', 'institution/people/upload')->response()->getBody();
        $this->assertStringContainsString('data-lock="read_only"', $page);

        $this->step('POST', "institution/people/upload/{$token}/apply");

        $this->assertSame($before, $this->students());
        $this->assertNotNull((new CsvUploadService($this->base))->kept($this->institution, $token), 'nothing was applied or removed');
    }

    public function testThePeoplePageLinksToIt(): void
    {
        $this->assertStringContainsString('institution/people/upload', html_entity_decode($this->step('GET', 'institution/people')->response()->getBody()));
    }
}

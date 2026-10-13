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
        $_SERVER['upload.csvPath'] = $this->base;

        $this->institution = SisFixture::institution('UPFA');
        $this->other       = SisFixture::institution('UPFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@upf.example', $this->institution);

        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['upload.csvPath']);

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

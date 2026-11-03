<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\Grades\GradeReportService;
use App\Services\Courses\Grades\GradingEngineService;
use App\Services\Grades\InstructorGradesOverview;
use App\Services\Grades\ReadySectionFinalizer;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The two cross-section actions of the instructor Grades page: Finalize ready sections and Download all.
 *
 * They sit outside api/courses, so none of the course filters run; each section is checked here instead
 * (taught by this person, still active, still ready right before finalizing, grading.manage), and every
 * refusal is named by section.
 *
 * @internal
 */
final class GradesBulkTest extends RouteTestCase
{
    private int $institution;

    private User $instructor;

    private ?int $term = null;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->institution = PeopleFactory::institution('BLK');
        $this->instructor  = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** A section, in the one term every section of the test shares. */
    private function section(string $code = 'A', ?User $teacher = null): int
    {
        $offering = CourseFactory::offering($code);
        $db       = db_connect();

        if ($this->term === null) {
            $this->term = (int) $db->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
        } else {
            $db->table('course_offerings')->where('id', $offering)->update(['term_id' => $this->term]);
        }

        CourseFactory::assignInstructor($offering, $teacher ?? $this->instructor);

        return $offering;
    }

    /** A published scheme whose checklist is clear, with one student scored. */
    private function ready(int $offering): int
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        $item = GradeFactory::item($scheme, GradeFactory::category($scheme, 'Exams', 100), 'Midterm', 100);

        $student = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $this->institution);
        CourseFactory::enroll($offering, $student);
        GradeFactory::score($offering, $student, $item, 88);

        (new GradingEngineService())->recalculateOffering($offering);

        return $scheme;
    }

    /** One piece of handed-in work waiting to be graded. */
    private function handIn(int $offering): void
    {
        $assessment = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering));
        db_connect()->table('assessments')->where('id', $assessment)->update(['status' => 'Published', 'due_date' => date('Y-m-d H:i:s', strtotime('+30 days'))]);

        $student = PeopleFactory::person('student', 'Ben', 'Waiting', null, $this->institution);
        CourseFactory::enroll($offering, $student);
        AssessmentFactory::attempt($assessment, $student, 'Submitted');
    }

    private function finalizedCount(?int $offering = null): int
    {
        $query = db_connect()->table('grade_finalizations')->where('reopened_at IS NULL', null, false);

        if ($offering !== null) {
            $query->where('course_offering_id', $offering);
        }

        return $query->countAllResults();
    }

    private function label(int $offering): string
    {
        $row = db_connect()->table('course_offerings co')->select('c.course_code, co.section_code')
            ->join('courses c', 'c.id = co.course_id')->where('co.id', $offering)->get()->getRow();

        return $row->course_code . '-' . $row->section_code;
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $_SESSION['reauth']                         = ['user' => $user->id, 'at' => time()];
        $this->withSession();
    }

    private function finalizeReady(): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->step('POST', 'instructor/grades/finalize-ready', ['term' => (string) $this->term]);
    }

    /** @return array<string, mixed> */
    private function json(TestResponse $result): array
    {
        return json_decode((string) $result->response()->getBody(), true) ?? [];
    }

    private function clear(string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'instructor', 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();

        $this->instructor = model(UserModel::class)->findById($this->instructor->id);
    }

    public function testOnlyReadySectionsTheyTeachAreFinalized(): void
    {
        $ready   = $this->section('A');
        $waiting = $this->section('B');
        $theirs  = $this->section('C', PeopleFactory::person('instructor', 'Olga', 'Other', null, $this->institution));

        $this->ready($ready);
        $this->ready($waiting);
        $this->handIn($waiting);
        $this->ready($theirs);

        $this->signIn($this->instructor);
        $result = $this->finalizeReady();

        $result->assertStatus(200);
        $this->assertSame([
            'success' => true,
            'done'    => [['offering_id' => $ready, 'label' => $this->label($ready)]],
            'skipped' => [],
        ], $this->json($result));

        $this->assertSame(1, $this->finalizedCount($ready));
        $this->assertSame(0, $this->finalizedCount($waiting));
        $this->assertSame(0, $this->finalizedCount($theirs), 'another instructor\'s ready section is left alone');
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'grades.finalized')->countAllResults());
    }

    public function testASectionThatStoppedBeingReadyIsSkippedWithItsReason(): void
    {
        $stale = $this->section('A');
        $fine  = $this->section('B');
        $this->ready($stale);
        $this->ready($fine);

        // The card list the person confirmed, then new work handed in before the finalizing reaches the section.
        $cards = (new InstructorGradesOverview())->sections($this->instructor, $this->term);
        $this->assertSame(['ready', 'ready'], array_column($cards, 'state'));
        $this->handIn($stale);

        $result = (new ReadySectionFinalizer())->finalize($this->instructor, $cards);

        $this->assertSame([['offering_id' => $fine, 'label' => $this->label($fine)]], $result['done']);
        $this->assertSame([['label' => $this->label($stale), 'reason' => '1 piece of handed-in work is still waiting to be graded.']], $result['skipped']);
        $this->assertSame(0, $this->finalizedCount($stale), 'nothing else changes');
        $this->assertSame(1, $this->finalizedCount($fine));
    }

    public function testASectionWhoseWeightsDoNotAddUpIsNotOfferedOrFinalized(): void
    {
        $offering = $this->section('A');
        $scheme   = $this->ready($offering);

        // Published, nothing waiting, no requests, but the weights no longer add up to 100: the card is
        // blocked with the reason, not ready, so Finalize ready sections leaves it alone.
        db_connect()->table('grade_categories')->where('grading_scheme_id', $scheme)->update(['weight_percentage' => 90]);

        $cards = (new InstructorGradesOverview())->sections($this->instructor, (int) $this->term);
        $this->assertSame('blocked', $cards[0]['state']);
        $this->assertStringContainsString('must total 100%', $cards[0]['note']);

        $this->signIn($this->instructor);
        $body = $this->json($this->finalizeReady());

        $this->assertSame([], $body['done']);
        $this->assertSame([], $body['skipped']);
        $this->assertSame(0, $this->finalizedCount());
    }

    public function testAClosedSectionIsSkipped(): void
    {
        $offering = $this->section('A');
        $this->ready($offering);
        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => 'completed']);

        $this->signIn($this->instructor);
        $result = $this->finalizeReady();

        $result->assertStatus(200);
        $this->assertSame([], $this->json($result)['done']);
        $this->assertSame([['label' => $this->label($offering), 'reason' => 'This section is closed.']], $this->json($result)['skipped']);
        $this->assertSame(0, $this->finalizedCount());
    }

    public function testWithoutGradingManageNothingIsFinalized(): void
    {
        $offering = $this->section('A');
        $this->ready($offering);
        $this->clear('grading.manage');

        $this->signIn($this->instructor);
        $result = $this->finalizeReady();

        $result->assertStatus(403);
        $this->assertFalse($this->json($result)['success']);
        $this->assertSame(0, $this->finalizedCount());
    }

    public function testWhileViewingAsNothingIsFinalized(): void
    {
        $offering = $this->section('A');
        $this->ready($offering);

        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->signIn($admin);
        $this->step('POST', "institution/view-as/{$this->instructor->id}", ['reason' => 'Checking what they see']);
        $this->assertSame((int) $this->instructor->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view started');

        $result = $this->finalizeReady();

        $result->assertStatus(403);
        $this->assertSame('impersonation_view_only', $this->json($result)['error']);
        $this->assertSame(0, $this->finalizedCount());
    }

    public function testDownloadAllHasASheetPerSectionAndAnAuditEach(): void
    {
        $first  = $this->section('A');
        $second = $this->section('B');
        $bare   = $this->section('C');
        $this->ready($first);
        $this->ready($second);

        $this->signIn($this->instructor);
        $response = $this->step('GET', 'instructor/grades/download?term=' . $this->term)->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $response->buildHeaders();

        $term = (string) db_connect()->table('academic_terms')->where('id', $this->term)->get()->getRow()->name;
        $this->assertStringContainsString('gradebooks-' . url_title($term, '-', true) . '-' . date('Ymd') . '.xlsx', $response->getHeaderLine('Content-Disposition'));

        ob_start();
        $response->sendBody();
        $path = tempnam(sys_get_temp_dir(), 'gb');
        file_put_contents($path, (string) ob_get_clean());

        $book = IOFactory::load($path);
        @unlink($path);

        $this->assertSame([$this->label($first), $this->label($second)], $book->getSheetNames(), 'a section with no grading has no sheet');

        $reports = new GradeReportService();
        $single  = $reports->classSheet($reports->classReport($first), 'xlsx');
        $header  = IOFactory::load($single['file_path'])->getSheetByName('Gradebook')->toArray()[0];
        @unlink($single['file_path']);

        $this->assertSame($header, $book->getSheet(0)->toArray()[0], 'the same header as the class sheet');

        $audits = db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->orderBy('id')->get()->getResultArray();
        $this->assertSame([$first, $second], array_map(static fn (array $row): int => (int) $row['target_id'], $audits), 'one entry per section with a sheet, none for ' . $bare);
        $this->assertSame(['kind' => 'class_gradebook', 'format' => 'xlsx', 'rows' => 1], json_decode((string) $audits[0]['new_values'], true));
    }

    public function testDownloadAllLeavesOutArchivedSectionsAndOtherInstructorsSections(): void
    {
        $mine     = $this->section('A');
        $archived = $this->section('B');
        $theirs   = $this->section('C', PeopleFactory::person('instructor', 'Other', 'Teacher', null, $this->institution));
        $this->ready($mine);
        $this->ready($archived);
        $this->ready($theirs);
        db_connect()->table('course_offerings')->where('id', $archived)->update(['status' => 'archived']);

        $this->signIn($this->instructor);
        $response = $this->step('GET', 'instructor/grades/download?term=' . $this->term)->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);

        ob_start();
        $response->sendBody();
        $path = tempnam(sys_get_temp_dir(), 'gb');
        file_put_contents($path, (string) ob_get_clean());

        $book = IOFactory::load($path);
        @unlink($path);

        $this->assertSame([$this->label($mine)], $book->getSheetNames());

        $audits = db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->get()->getResultArray();
        $this->assertSame([$mine], array_map(static fn (array $row): int => (int) $row['target_id'], $audits));
    }

    public function testDownloadAllWithNoSectionsRefusesPolitely(): void
    {
        $bare = $this->section('A');

        $this->signIn($this->instructor);
        $result = $this->step('GET', 'instructor/grades/download?term=' . $this->term);

        $result->assertRedirect();
        $this->assertSame('None of your sections this term has grading yet, so there is nothing to download.', session('error'));

        // A term that is not theirs, or not a number, refuses the same way.
        foreach (['999999', 'abc', ''] as $term) {
            $this->step('GET', 'instructor/grades/download?term=' . $term)->assertRedirect();
        }

        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->countAllResults());
        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $bare)->countAllResults(), 'no scheme created by looking');
    }

    public function testDownloadAllWithoutGradingManageIsRefused(): void
    {
        $this->ready($this->section('A'));
        $this->clear('grading.manage');

        $this->signIn($this->instructor);
        $this->withHeaders(['Accept' => 'application/json'])->step('GET', 'instructor/grades/download?term=' . $this->term)->assertStatus(403);

        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->countAllResults());
    }

    public function testTheButtonsAreWired(): void
    {
        $offering = $this->section('A');
        $this->ready($offering);

        $this->signIn($this->instructor);
        $result = $this->step('GET', 'instructor/grades?term=' . $this->term);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $result->getBody());
        libxml_clear_errors();
        $x = new DOMXPath($dom);

        $this->assertStringEndsWith('instructor/grades/download?term=' . $this->term, $x->query('//a[@data-download-all]')->item(0)->getAttribute('href'));
        $this->assertSame('finalize-ready-dialog', $x->query('//button[@data-finalize-ready]')->item(0)->getAttribute('data-dialog-open'));

        $form = $x->query('//*[@id="finalize-ready-dialog"]//form')->item(0);
        $this->assertStringEndsWith('instructor/grades/finalize-ready', $form->getAttribute('action'));
        $this->assertSame((string) $this->term, $x->query('.//input[@name="term"]', $form)->item(0)->getAttribute('value'));
        $this->assertStringContainsString($this->label($offering), $form->textContent, 'the dialog names the sections');
    }
}

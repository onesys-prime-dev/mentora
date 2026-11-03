<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The instructor's Grades page end to end: the section cards and the summary,
 * the To grade queue and its way back, the At risk list, the empty states, the
 * permissions behind the page and its buttons, and that looking writes nothing.
 *
 * @internal
 */
final class InstructorGradesPageTest extends RouteTestCase
{
    private int $institution;

    private User $instructor;

    private ?int $term = null;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->institution = PeopleFactory::institution('GRD');
        $this->instructor  = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** A section the instructor teaches, in the one term every section of the test shares. */
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

    private function publish(int $offering): int
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        return $scheme;
    }

    private function student(int $offering, string $last = 'Lovelace'): User
    {
        $student = PeopleFactory::person('student', 'Ada', $last, null, $this->institution);
        CourseFactory::enroll($offering, $student);

        return $student;
    }

    /**
     * An assessment with $count ungraded submissions, the oldest handed in $daysAgo days ago.
     *
     * @return int assessment id
     */
    private function waiting(int $offering, int $count, string $type = 'Quiz', int $daysAgo = 0, bool $late = false): int
    {
        $assessment = CourseFactory::assessment($offering, $this->instructor, CourseFactory::module($offering), null, $type);
        db_connect()->table('assessments')->where('id', $assessment)->update([
            'status'   => 'Published',
            'due_date' => $late ? date('Y-m-d H:i:s', strtotime('-30 days')) : date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        for ($i = 0; $i < $count; $i++) {
            $submission = AssessmentFactory::attempt($assessment, $this->student($offering), 'Submitted');
            db_connect()->table('assessment_submissions')->where('id', $submission)->update([
                'submitted_at' => date('Y-m-d H:i:s', time() - ($i === 0 ? $daysAgo : 0) * 86400 - 60),
            ]);
        }

        return $assessment;
    }

    private function open(string $query = '', ?User $as = null): TestResponse
    {
        $this->loggedInAs($as ?? $this->instructor);

        $result = $this->get('instructor/grades' . ($query !== '' ? '?' . $query : ''));
        $result->assertStatus(200);

        return $result;
    }

    private function xpath(TestResponse $result): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $result->getBody());
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** The value shown on a summary card ("3", "1 / 3"). */
    private function summary(DOMXPath $x, string $key): string
    {
        $value = $x->query("//*[@data-summary='{$key}']//span[contains(@class,'font-black')]")->item(0);

        return trim(str_replace(['Warning:', 'Needs attention:', 'On track:'], '', $this->text($value)));
    }

    /** @return list<string> */
    private function attributes(DOMXPath $x, string $query, string $attribute): array
    {
        $out = [];

        foreach ($x->query($query) as $node) {
            $out[] = $node->getAttribute($attribute);
        }

        return $out;
    }

    private function clear(string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'instructor', 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();

        auth()->logout();
        $this->withSession([]);
        $this->instructor = model(UserModel::class)->findById($this->instructor->id);
    }

    public function testTheGradebookTabShowsEveryCardAndOpensOnTheBusiestSection(): void
    {
        $ready   = $this->section('A');
        $busiest = $this->section('B');
        $other   = $this->section('C');

        $scheme = $this->publish($ready);
        $this->publish($busiest);
        $this->publish($other);

        $this->waiting($busiest, 2);
        $this->waiting($other, 1);

        // One student failing so far, in the ready section.
        $failing = $this->student($ready, 'Failing');
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $ready, 'student_id' => $failing->id, 'grading_scheme_id' => $scheme,
            'calculated_percentage' => 58, 'remarks' => 'Failed',
        ]);

        $result = $this->open();
        $x      = $this->xpath($result);

        $result->assertSee('Every section you teach.');
        $this->assertSame('Grades', $this->text($x->query('//h1')->item(0)));

        $this->assertEqualsCanonicalizing(
            [(string) $ready, (string) $busiest, (string) $other],
            $this->attributes($x, '//*[@data-section-card]', 'data-section-card'),
        );
        $this->assertSame([(string) $busiest], $this->attributes($x, "//*[@data-section-card][@aria-current='true']", 'data-section-card'));

        // Each card links to its own choice and says where it stands in words, not only a colour.
        $card = $x->query("//*[@data-section-card='{$ready}']")->item(0);
        $this->assertStringContainsString('section=' . $ready, $card->getAttribute('href'));
        $this->assertStringContainsString('term=' . $this->term, $card->getAttribute('href'));
        $this->assertSame('ready', $card->getAttribute('data-state'));
        $this->assertStringContainsString('Ready to finalize', $this->text($card));
        $this->assertStringContainsString('2 waiting', $this->text($x->query("//*[@data-section-card='{$busiest}']")->item(0)));

        $this->assertSame('3', $this->summary($x, 'waiting'));
        $this->assertSame('1 / 3', $this->summary($x, 'ready'));
        $this->assertSame('1', $this->summary($x, 'at-risk'));
        $this->assertSame('0 / 3', $this->summary($x, 'finalized'));
        foreach (['Waiting to grade', 'Ready to finalize', 'At risk', 'Finalized'] as $label) {
            $result->assertSee($label);
        }

        // The grid is left for the page script: an empty container that names the section.
        $grid = $x->query('//*[@data-grades-grid]')->item(0);
        $this->assertSame((string) $busiest, $grid->getAttribute('data-section-id'));
        $this->assertSame(site_url('instructor/api/courses/' . $busiest), $grid->getAttribute('data-api-base'));

        // The action bar is the selected section's.
        $bar = $this->text($x->query('//*[@data-section-actions]')->item(0));
        $this->assertStringContainsString('2 waiting to be graded', $bar);
        $this->assertCount(1, $x->query('//*[@data-open-finalize]'));
        $slug = $x->query("//*[@data-section-card='{$busiest}']")->item(0)->getAttribute('data-slug');
        $this->assertSame([site_url("instructor/courses/{$busiest}/{$slug}/grades")], $this->attributes($x, '//*[@data-open-in-course]', 'href'));
        $this->assertSame([
            site_url("instructor/api/courses/{$busiest}/grades/report/class") . '?format=xlsx',
            site_url("instructor/api/courses/{$busiest}/grades/report/summary") . '?format=pdf',
        ], $this->attributes($x, '//*[@data-download-link]', 'href'));

        // A chosen section opens instead of the busiest.
        $chosen = $this->xpath($this->open('section=' . $ready));
        $this->assertSame([(string) $ready], $this->attributes($chosen, "//*[@data-section-card][@aria-current='true']", 'data-section-card'));
    }

    public function testASelectedSectionWithoutGradingSetUpShowsThatInsteadOfAGrid(): void
    {
        $bare    = $this->section('A');
        $graded  = $this->section('B');
        $this->publish($graded);
        $this->waiting($bare, 2); // the busiest, so it is the one selected

        $result = $this->open();
        $x      = $this->xpath($result);

        $this->assertSame([(string) $bare], $this->attributes($x, "//*[@data-section-card][@aria-current='true']", 'data-section-card'));
        $this->assertSame('0', $x->query("//*[@data-section-card='{$bare}']")->item(0)->getAttribute('data-has-scheme'));
        $this->assertSame('1', $x->query("//*[@data-section-card='{$graded}']")->item(0)->getAttribute('data-has-scheme'));

        // The grid area says so and offers the course, where setting it up is a deliberate step; no grid is fetched.
        $grid = $x->query('//*[@data-grades-grid]')->item(0);
        $this->assertSame('0', $grid->getAttribute('data-has-scheme'));
        $this->assertStringContainsString("Grading isn't set up for this section yet.", $this->text($grid));
        $slug = $x->query("//*[@data-section-card='{$bare}']")->item(0)->getAttribute('data-slug');
        $this->assertSame([site_url("instructor/courses/{$bare}/{$slug}/grades")], $this->attributes($x, '//*[@data-grades-grid]//a[@data-no-scheme-open]', 'href'));
        $this->assertStringContainsString('Open in course →', $this->text($x->query('//*[@data-grades-grid]//a[@data-no-scheme-open]')->item(0)));
        $this->assertSame(0, $x->query('//*[@data-grades-grid]//*[@data-skeleton]')->length);
        $this->assertTrue($x->query('//*[@data-open-finalize]')->item(0)->hasAttribute('hidden'), 'its checklist would create a scheme');

        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $bare)->countAllResults(), 'no scheme is created by looking');

        // A section with a scheme gets the loading shape for the page script to fill.
        $grid = $this->xpath($this->open('section=' . $graded))->query('//*[@data-grades-grid]')->item(0);
        $this->assertSame('1', $grid->getAttribute('data-has-scheme'));
        $this->assertStringNotContainsString("Grading isn't set up", $this->text($grid));
    }

    public function testASectionTheyDoNotTeachIs404(): void
    {
        $this->section('A');
        $someoneElses = $this->section('B', PeopleFactory::person('instructor', institutionId: $this->institution));

        $this->loggedInAs($this->instructor);

        $this->expectException(PageNotFoundException::class);
        $this->get('instructor/grades?section=' . $someoneElses);
    }

    public function testANonNumericSectionIs404(): void
    {
        $this->section('A');

        $this->loggedInAs($this->instructor);

        $this->expectException(PageNotFoundException::class);
        $this->get('instructor/grades?section=abc');
    }

    public function testToGradeListsOldestFirstWithAReturnAddress(): void
    {
        $a = $this->section('A');
        $b = $this->section('B');

        $newer = $this->waiting($a, 3, 'Quiz', 2);
        $older = $this->waiting($b, 1, 'Assignment', 6);
        $today = $this->waiting($a, 1, 'Quiz', 0);

        $x    = $this->xpath($this->open('tab=to-grade'));
        $rows = $x->query('//*[@data-to-grade-row]');

        $this->assertSame([(string) $older, (string) $newer, (string) $today], $this->attributes($x, '//*[@data-to-grade-row]', 'data-assessment-id'));

        $code  = (string) db_connect()->query('SELECT c.course_code FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE co.id = ?', [$b])->getRow()->course_code;
        $title = (string) db_connect()->table('assessments')->where('id', $older)->get()->getRow()->title;

        $first = $this->text($rows->item(0));
        $this->assertStringContainsString("{$code}-B · {$title}", $first);
        $this->assertStringContainsString('1 waiting · oldest 6 days · Assignment', $first);
        $this->assertStringContainsString('3 waiting · oldest 2 days · Quiz', $this->text($rows->item(1)));
        $this->assertStringContainsString('1 waiting · oldest today · Quiz', $this->text($rows->item(2)));
        $this->assertStringNotContainsString('late', $first);

        // Grade → opens the Grade work page, with the way back to this queue.
        $link = $x->query('.//*[@data-grade-link]', $rows->item(0))->item(0);
        $this->assertStringContainsString('Grade →', $this->text($link));
        $href = $link->getAttribute('href');
        $slug = url_title((string) db_connect()->query('SELECT c.course_title FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE co.id = ?', [$b])->getRow()->course_title, '-', true);
        // It opens on the student who has waited longest.
        $firstStudent = (int) db_connect()->table('assessment_submissions')->where('assessment_id', $older)->get()->getRow()->student_id;
        $this->assertStringStartsWith(site_url("instructor/courses/{$b}/{$slug}/assessments") . '?assessment=' . $older . '&grade=1&student=' . $firstStudent . '&return=', $href);

        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        // This page's own path (under a sub-folder install, the sub-folder too): /instructor/grades?….
        $this->assertStringStartsWith(parse_url(site_url('instructor/grades'), PHP_URL_PATH) . '?', $query['return']);
        $this->assertStringEndsWith('/instructor/grades', (string) parse_url($query['return'], PHP_URL_PATH));
        $this->assertStringContainsString('tab=to-grade', $query['return']);
        $this->assertStringContainsString('term=' . $this->term, $query['return']);

        // The tab shows how much is waiting.
        $this->assertSame('5', $this->text($x->query("//*[@data-tab-count='to-grade']")->item(0)));
    }

    public function testToGradeFiltersBySectionTypeAndLate(): void
    {
        $a = $this->section('A');
        $b = $this->section('B');

        $quiz       = $this->waiting($a, 1, 'Quiz', 1);
        $assignment = $this->waiting($b, 2, 'Assignment', 3, true);

        $ids = fn (string $query): array => $this->attributes($this->xpath($this->open($query)), '//*[@data-to-grade-row]', 'data-assessment-id');

        $this->assertSame([(string) $assignment, (string) $quiz], $ids('tab=to-grade'));
        $this->assertSame([(string) $quiz], $ids('tab=to-grade&qsection=' . $a));
        $this->assertSame([(string) $assignment], $ids('tab=to-grade&type=assignment'));
        $this->assertSame([(string) $assignment], $ids('tab=to-grade&late=1'));
        $this->assertSame([], $ids('tab=to-grade&qsection=' . $a . '&late=1'));

        // A filter that names nothing of theirs is ignored, not an error.
        $this->assertSame([(string) $assignment, (string) $quiz], $ids('tab=to-grade&qsection=999999&type=nonsense'));

        // The late count shows, and the way back keeps the filters.
        $x = $this->xpath($this->open('tab=to-grade&late=1&qsection=' . $b));
        $row = $this->text($x->query('//*[@data-to-grade-row]')->item(0));
        $this->assertStringContainsString('2 waiting · oldest 3 days · Assignment · 2 late', $row);

        parse_str((string) parse_url($x->query('//*[@data-grade-link]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
        parse_str((string) parse_url($query['return'], PHP_URL_QUERY), $back);
        $this->assertSame(['tab' => 'to-grade', 'term' => (string) $this->term, 'qsection' => (string) $b, 'late' => '1'], $back);

        // Nothing matches: the empty state.
        $this->open('tab=to-grade&qsection=' . $a . '&late=1')->assertSee('Nothing waiting to grade');
    }

    public function testAtRiskShowsReasonsAndFilters(): void
    {
        $offering = $this->section('A');
        $scheme   = $this->publish($offering);
        $items    = CourseFactory::items(CourseFactory::unit(CourseFactory::module($offering)), array_fill(0, 10, []));
        $db       = db_connect();

        $failing = $this->student($offering, 'Adams');
        $inc     = $this->student($offering, 'Brown');
        $this->student($offering, 'Silent'); // never active

        foreach ([$failing, $inc] as $active) {
            for ($i = 0; $i < 8; $i++) {
                CourseFactory::progress($items[$i], $active);
            }
        }

        $db->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $failing->id, 'grading_scheme_id' => $scheme, 'calculated_percentage' => 58, 'remarks' => 'Failed']);
        $db->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $inc->id, 'grading_scheme_id' => $scheme, 'remarks' => 'Incomplete', 'inc_set_at' => '2026-11-01 08:00:00', 'inc_until' => null]);

        $x    = $this->xpath($this->open('tab=at-risk'));
        $rows = $x->query('//*[@data-risk-row]');

        $this->assertSame(3, $rows->length);
        $this->assertStringContainsString('Ada Adams', $this->text($rows->item(0)));
        $this->assertStringContainsString('Failing · 58.0%', $this->text($rows->item(0)));
        $this->assertStringContainsString('-A', $this->text($rows->item(0)));
        $this->assertStringContainsString('INC', $this->text($rows->item(1)));
        $this->assertStringContainsString('Never active', $this->text($rows->item(2)));
        $this->assertSame(['grade', 'inc', 'activity'], $this->attributes($x, '//*[@data-reason]', 'data-reason'));
        $this->assertSame('3', $this->text($x->query("//*[@data-tab-count='at-risk']")->item(0)));

        $names = fn (string $query): array => array_map(
            fn (DOMNode $row): string => $this->text($row->getElementsByTagName('th')->item(0)),
            iterator_to_array($this->xpath($this->open($query))->query('//*[@data-risk-row]')),
        );

        $this->assertSame(['Ada Adams'], $names('tab=at-risk&reason=grade'));
        $this->assertSame(['Ada Brown'], $names('tab=at-risk&reason=inc'));
        $this->assertSame(['Ada Silent'], $names('tab=at-risk&reason=activity'));
        $this->assertCount(3, $names('tab=at-risk&reason=nonsense'), 'an unknown reason is ignored');
    }

    public function testNoSectionsShowsTheEmptyStateAndNoBulkButtons(): void
    {
        $this->open()->assertSee('No sections this term');
        $this->open('tab=to-grade')->assertSee('Nothing waiting to grade');
        $this->open('tab=at-risk')->assertSee('No one is at risk');

        $x = $this->xpath($this->open());
        $this->assertSame(0, $x->query('//*[@data-finalize-ready] | //*[@data-download-all] | //*[@data-open-finalize] | //*[@data-grades-grid]')->length);
    }

    public function testNoTermsAtAll(): void
    {
        $result = $this->open('term=abc');
        $x      = $this->xpath($result);

        $result->assertSee('No sections this term');
        $this->assertSame(0, $x->query('//select[@data-term-select]')->length, 'no term to pick');
        $this->assertSame(0, $x->query('//*[@data-load-error]')->length);
        $this->assertSame(0, $x->query('//*[@data-section-card]')->length);
    }

    public function testWithoutCoursesViewThereIsNoPage(): void
    {
        helper('navigation');
        $this->section('A');

        $this->open();
        $this->assertContains('grades', array_column(global_apps('instructor'), 'key'));

        $this->clear('courses.view');
        $this->loggedInAs($this->instructor);

        $this->step('get', 'instructor/grades');
        $this->assertSame(lang('Auth.notEnoughPrivilege'), session('error'));
        $this->assertNotContains('grades', array_column(global_apps('instructor'), 'key'), 'no page, no tile');
    }

    public function testFinalizeAndDownloadAllAreHiddenWithoutGradingManage(): void
    {
        $ready   = $this->section('A');
        $waiting = $this->section('B');
        $this->publish($ready);
        $this->publish($waiting);
        $this->waiting($waiting, 1);

        $x = $this->xpath($this->open());
        $this->assertSame(1, $x->query('//button[@data-finalize-ready]')->length);
        $this->assertStringContainsString('Finalize ready sections', $this->text($x->query('//*[@data-finalize-ready]')->item(0)));
        $this->assertSame(1, $x->query('//a[@data-download-all]')->length);
        $this->assertSame(1, $x->query('//*[@data-open-finalize]')->length);

        // Nothing ready: no Finalize ready sections, but Download all stays.
        db_connect()->table('grading_schemes')->where('course_offering_id', $ready)->update(['status' => 'Draft']);
        $x = $this->xpath($this->open());
        $this->assertSame(0, $x->query('//*[@data-finalize-ready]')->length);
        $this->assertSame(1, $x->query('//*[@data-download-all]')->length);

        $this->clear('grading.manage');

        $x = $this->xpath($this->open());
        $this->assertSame(0, $x->query('//*[@data-finalize-ready] | //*[@data-download-all] | //*[@data-open-finalize] | //*[@data-download-link]')->length);
        $this->assertSame(1, $x->query('//*[@data-open-in-course]')->length, 'the course stays one click away');
    }

    public function testFinalizeIsHiddenForASectionThatIsNotActive(): void
    {
        $offering = $this->section('A');
        $this->publish($offering);

        $x = $this->xpath($this->open());
        $this->assertSame('active', $x->query('//*[@data-section-card]')->item(0)->getAttribute('data-offering-status'));
        $this->assertFalse($x->query('//*[@data-open-finalize]')->item(0)->hasAttribute('hidden'));

        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => 'completed']);

        $x = $this->xpath($this->open());
        $this->assertSame('completed', $x->query('//*[@data-section-card]')->item(0)->getAttribute('data-offering-status'));
        $this->assertTrue($x->query('//*[@data-open-finalize]')->item(0)->hasAttribute('hidden'));
    }

    public function testOpeningThePageWritesNothing(): void
    {
        $unschemed = $this->section('A');
        $this->waiting($unschemed, 2);
        $this->publish($this->section('B'));

        $db     = db_connect();
        $counts = static function () use ($db): array {
            $out = [];

            foreach ($db->listTables() as $table) {
                if ($table !== 'audit_logs') {
                    $out[$table] = $db->table($table)->countAllResults();
                }
            }

            return $out;
        };

        $before = $counts();

        foreach (['', 'tab=to-grade', 'tab=at-risk', 'section=' . $unschemed] as $query) {
            $this->open($query);
        }

        $this->assertSame($before, $counts());
        $this->assertSame(0, $db->table('grading_schemes')->where('course_offering_id', $unschemed)->countAllResults(), 'no scheme is created by looking');
    }
}

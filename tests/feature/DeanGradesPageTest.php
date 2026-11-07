<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Grades page end to end (spec §9, "Phase 5 layout"): one College view with a program picker, the
 * strip whose counts link to Sign-off and Approvals when the dean may open them, the chairperson's section table
 * with a Signed off column, the selected section's grid always read-only, the college's at-risk list, the
 * 404 for a section outside the college, and that looking writes nothing.
 *
 * @internal
 */
final class DeanGradesPageTest extends RouteTestCase
{
    /** @var array{institution: int, college: int} */
    private array $unit;

    private int $programA;

    private int $programB;

    private int $term;

    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->unit     = DeanFactory::college('DGP');
        $this->programA = DeanFactory::program(DeanFactory::department($this->unit['college'], 'DGA'), 'DGA');
        $this->programB = DeanFactory::program(DeanFactory::department($this->unit['college'], 'DGB'), 'DGB');
        $this->term     = DeanFactory::term($this->unit['institution']); // 2026-08-01 to 2026-12-20
        $this->dean     = DeanFactory::dean($this->unit['institution'], $this->unit['college']);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function instructor(string $first = 'Ian', string $last = 'Structor'): User
    {
        return PeopleFactory::person('instructor', $first, $last, null, $this->unit['institution']);
    }

    /** A section of the college in the test's term (program A unless named), taught by $teachers. */
    private function section(string $code, ?int $program = null, User ...$teachers): int
    {
        $offering = DeanFactory::offering($program ?? $this->programA, $this->term, $code);

        foreach ($teachers as $teacher) {
            CourseFactory::assignInstructor($offering, $teacher);
        }

        return $offering;
    }

    /** A section of another college of the institution: not the dean's. */
    private function outsideSection(string $code = 'XX101'): int
    {
        $other   = DeanFactory::college('DGO', $this->unit['institution']);
        $program = DeanFactory::program(DeanFactory::department($other['college'], 'DGO'), 'DGO');

        return DeanFactory::offering($program, $this->term, $code);
    }

    private function publish(int $offering): int
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        return $scheme;
    }

    private function student(int $offering, ?float $percentage, string $remarks = 'Passed', string $first = 'Ada', string $last = 'Lovelace'): User
    {
        $student = PeopleFactory::person('student', $first, $last, null, $this->unit['institution']);
        CourseFactory::enroll($offering, $student);

        $scheme = db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRow();

        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $scheme?->id,
            'calculated_percentage' => $percentage, 'remarks' => $remarks,
        ]);

        return $student;
    }

    /** $count ungraded quiz submissions, the oldest handed in $daysAgo days ago. */
    private function waiting(int $offering, int $count, User $author, int $daysAgo = 0): void
    {
        $quiz = CourseFactory::assessment($offering, $author, CourseFactory::module($offering), null, 'Quiz');
        db_connect()->table('assessments')->where('id', $quiz)->update(['status' => 'Published', 'due_date' => date('Y-m-d H:i:s', strtotime('+30 days'))]);

        for ($i = 0; $i < $count; $i++) {
            $student = PeopleFactory::person('student', 'Wai', 'Ting' . $i, null, $this->unit['institution']);
            CourseFactory::enroll($offering, $student);
            $submission = AssessmentFactory::attempt($quiz, $student, 'Submitted');
            db_connect()->table('assessment_submissions')->where('id', $submission)->update([
                'submitted_at' => date('Y-m-d H:i:s', time() - ($i === 0 ? $daysAgo : 0) * 86400 - 60),
            ]);
        }
    }

    private function finalize(int $offering): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $offering, 'finalized_by' => $this->dean->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function signOff(int $offering): void
    {
        db_connect()->table('grade_signoffs')->insert(['offering_id' => $offering, 'signed_by' => $this->dean->id, 'signed_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function overrideRequest(int $offering): void
    {
        db_connect()->table('grade_override_requests')->insert([
            'offering_id' => $offering, 'student_id' => CourseFactory::user('student')->id, 'requested_percentage' => 80,
            'reason' => 'Appeal', 'requested_by' => $this->dean->id, 'requester_role' => 'instructor',
            'status' => 'awaiting_dean', 'open_marker' => 1,
        ]);
    }

    /** Takes a permission away from every dean of the institution, and signs the dean in afresh. */
    private function clear(string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->unit['institution'], 'role' => 'dean', 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();

        auth()->logout();
        $this->withSession([]);
        $this->dean = model(UserModel::class)->findById($this->dean->id);
    }

    private function open(string $query = ''): TestResponse
    {
        $this->loggedInAs($this->dean);

        $result = $this->get('dean/grades' . ($query !== '' ? '?' . $query : ''));
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

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $out = [];

        foreach ($x->query($query) as $node) {
            $out[] = $this->text($node);
        }

        return $out;
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

    private function queryOf(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $query);

        return $query;
    }

    private function row(DOMXPath $x, int $offering): DOMElement
    {
        $node = $x->query("//a[@data-program-row][@data-section-id='{$offering}']")->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "a row for section {$offering}");

        return $node;
    }

    private function cell(DOMXPath $x, int $offering, string $column): string
    {
        return $this->text($x->query("//a[@data-program-row][@data-section-id='{$offering}']/ancestor::tr//*[@data-cell='{$column}']")->item(0));
    }

    private function strip(DOMXPath $x): string
    {
        return $this->text($x->query('//*[@data-strip-line]')->item(0));
    }

    private function assert404(string $query): void
    {
        $this->loggedInAs($this->dean);

        try {
            $this->get('dean/grades?' . $query);
            $this->fail("dean/grades?{$query} should be a 404");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheTileOpensTheGradesPageForDeans(): void
    {
        helper('navigation');

        $this->open()->assertSee('Every section in your college.');
        $this->assertSame(site_url('dean/grades'), array_column(global_apps('dean'), 'url', 'key')['grades'] ?? null);

        $this->clear('college.monitor');
        $this->loggedInAs($this->dean);

        $this->assertArrayNotHasKey('grades', array_column(global_apps('dean'), 'url', 'key'), 'no page, no tile');

        $result = $this->get('dean/grades');
        $result->assertRedirect();
        $this->assertSame(lang('Auth.notEnoughPrivilege'), session('error'));
    }

    public function testTheCollegeViewShowsStripTableAndReadOnlyGrid(): void
    {
        $santos = $this->instructor('Mara', 'Santos');
        $garcia = $this->instructor('Jose', 'Garcia');

        $signed = $this->section('IT101', null, $santos);
        $this->publish($signed);
        $this->finalize($signed);
        $this->signOff($signed);

        $finalized = $this->section('IT102', null, $garcia);
        $this->publish($finalized);
        $this->finalize($finalized);

        $waiting = $this->section('CS101', $this->programB, $santos, $garcia);
        $this->publish($waiting);
        $this->waiting($waiting, 3, $santos, 4);
        $this->student($waiting, 40.0, 'Failed');
        $this->overrideRequest($waiting);

        $this->publish($this->outsideSection());

        $result = $this->open();
        $x      = $this->xpath($result);

        $result->assertSee('Every section in your college.');
        $this->assertSame(0, $x->query('//*[@data-grades-tab]')->length, 'one view, so no tab bar');
        $this->assertSame('College', $this->text($x->query('//*[@data-grades-tab-label]')->item(0)));
        $this->assertSame(['Sections', 'At risk'], $this->texts($x, '//*[@data-grades-sub]/*[@data-department-label]'));

        // The program picker: "All programs" first and chosen.
        $this->assertSame(['All programs', 'Program DGA', 'Program DGB'], $this->texts($x, '//select[@data-program-select]/option'));
        $this->assertSame('All programs', $this->text($x->query('//select[@data-program-select]/option[@selected]')->item(0)));

        // The strip, over the whole college; not the chairperson's tracker.
        $this->assertSame('Finalized 2 of 3 · Signed off 1 · Override requests waiting: 1', $this->strip($x));
        $this->assertSame(0, $x->query('//*[@data-tracker-line]')->length);
        $this->assertSame(1, $x->query('//a[@data-not-finalized]')->length);

        $this->assertSame(['Section', 'Instructor', 'Average', 'At risk', 'Waiting', 'Status', 'Signed off'], $this->texts($x, '//table[@data-program-table]//thead//*[@data-column-label]'));
        $this->assertSame([(string) $waiting, (string) $signed, (string) $finalized], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertSame('Yes', $this->cell($x, $signed, 'signed_off'));
        $this->assertSame('—', $this->cell($x, $finalized, 'signed_off'));
        $this->assertSame('—', $this->cell($x, $waiting, 'signed_off'));
        $this->assertSame('Jose Garcia, Mara Santos', $this->cell($x, $waiting, 'instructor'));

        // Nudge goes to the section's instructors (the dean may ask them), through the dean's Follow-ups.
        $row = $this->row($x, $waiting);
        $this->assertSame([['id' => (int) $garcia->id, 'name' => 'Jose Garcia'], ['id' => (int) $santos->id, 'name' => 'Mara Santos']], json_decode($row->getAttribute('data-instructors'), true));
        $this->assertSame('4', $row->getAttribute('data-oldest-days'));
        $this->assertSame(['term' => (string) $this->term, 'section' => (string) $waiting], $this->queryOf($row->getAttribute('href')));
        $this->assertSame('Nudge', $this->text($x->query('//button[@data-nudge]')->item(0)));
        $this->assertSame(site_url('dean/follow-ups'), $x->query('//form[@data-nudge-form]')->item(0)->getAttribute('action'));

        $download = $x->query('//a[@data-download-college]')->item(0);
        $this->assertSame('Download college', $this->text($download));
        $this->assertSame(site_url('dean/grades/download-college') . '?term=' . $this->term, $download->getAttribute('href'));
        $this->assertSame(0, $x->query('//*[@data-download-program]')->length);

        // The first row's grid, below the table, through the dean's read-only course API.
        $grid = $x->query('//*[@data-grades-grid]')->item(0);
        $this->assertSame(site_url("dean/api/courses/{$waiting}"), $grid->getAttribute('data-api-base'));
        $this->assertSame('1', $grid->getAttribute('data-readonly'));
        $this->assertSame('Read-only', $this->text($x->query('//*[@data-readonly-label]')->item(0)));
        $this->assertSame(site_url("dean/courses/{$waiting}/course-cs101/grades"), $x->query('//*[@data-program-actions]//*[@data-open-in-course]')->item(0)->getAttribute('href'));
        $this->assertSame(0, $x->query('//*[@data-edit-as-chair] | //*[@data-edit-done]')->length);

        // The grid route the page script calls is there for the dean, and answers.
        $this->loggedInAs($this->dean);
        $roster = $this->get("dean/api/courses/{$waiting}/grades/roster?embedded=1&readonly=1");
        $roster->assertStatus(200);
        $this->assertStringContainsString('"success": true', (string) $roster->getBody());
        $this->assertStringContainsString('data-gradebook-roster', (string) $roster->getBody());

        // The intro of a read-only grid says so, and does not invite an override the dean cannot make.
        $body = html_entity_decode((string) json_decode((string) $roster->getJSON(), true)['html']);
        $this->assertStringContainsString('Read-only. Quiz and assignment scores come from Grade Work', $body);
        $this->assertStringNotContainsString('Click a final grade', $body);
        $this->assertStringNotContainsString('are editable here', $body);
    }

    public function testStripLinksOnlyWithTheirPermissions(): void
    {
        $offering = $this->section('IT101', null, $this->instructor());
        $this->publish($offering);
        $this->finalize($offering);
        $this->signOff($offering);
        $this->overrideRequest($offering);

        $x = $this->xpath($this->open());
        $this->assertSame('Finalized 1 of 1 · Signed off 1 · Override requests waiting: 1', $this->strip($x));
        $this->assertSame(site_url('dean/signoff') . '?term=' . $this->term, $x->query('//*[@data-strip-line]//a[@data-strip-signed-off]')->item(0)->getAttribute('href'));
        $this->assertSame('Signed off 1', $this->text($x->query('//a[@data-strip-signed-off]')->item(0)));
        $this->assertSame(site_url('dean/approvals'), $x->query('//*[@data-strip-line]//a[@data-strip-requests]')->item(0)->getAttribute('href'));
        $this->assertSame('Override requests waiting: 1', $this->text($x->query('//a[@data-strip-requests]')->item(0)));
        $link = $x->query('//a[@data-strip-requests]')->item(0);
        $this->assertStringContainsString('this term', $link->getAttribute('title'), 'the count is for this term; Approvals is not');
        $this->assertStringContainsString('this term', $link->getAttribute('aria-label'));

        // With a program chosen, Approvals opens on it.
        $x = $this->xpath($this->open('program=' . $this->programA));
        $this->assertSame(site_url('dean/approvals') . '?program=' . $this->programA, $x->query('//a[@data-strip-requests]')->item(0)->getAttribute('href'));

        $this->clear('grades.signoff');

        $x = $this->xpath($this->open());
        $this->assertSame('Finalized 1 of 1 · Signed off 1 · Override requests waiting: 1', $this->strip($x), 'the counts stay');
        $this->assertSame(0, $x->query('//a[@data-strip-signed-off]')->length, 'no Sign-off page, no link');
        $this->assertSame(1, $x->query('//a[@data-strip-requests]')->length);

        $this->clear('grades.approve');

        $x = $this->xpath($this->open());
        $this->assertSame('Finalized 1 of 1 · Signed off 1 · Override requests waiting: 1', $this->strip($x));
        $this->assertSame(0, $x->query('//*[@data-strip-line]//a')->length, 'plain text without either permission');

        // Nudge needs followups.manage.
        $this->assertSame(1, $x->query('//*[@data-nudge]')->length);
        $this->clear('followups.manage');
        $x = $this->xpath($this->open());
        $this->assertSame(0, $x->query('//*[@data-nudge] | //*[@data-nudge-dialog]')->length);
        $this->assertSame(1, $x->query('//*[@data-download-college]')->length, 'college.monitor is what Download college needs');
    }

    public function testProgramPickerAndAllPrograms(): void
    {
        $a1 = $this->section('IT101', null, $this->instructor());
        $a2 = $this->section('IT102', null, $this->instructor());
        $b1 = $this->section('CS101', $this->programB, $this->instructor());

        foreach ([$a1, $a2, $b1] as $offering) {
            $this->publish($offering);
        }

        $this->finalize($a1);
        $this->signOff($a1);
        $this->finalize($b1);
        $this->overrideRequest($a2);
        $this->overrideRequest($b1);

        // All programs: every section, the strip over all of them, and the counts agree with the table.
        $x = $this->xpath($this->open());
        $this->assertSame([(string) $b1, (string) $a1, (string) $a2], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertSame('Finalized 2 of 3 · Signed off 1 · Override requests waiting: 2', $this->strip($x));
        $this->assertSame('3', $this->text($x->query("//*[@data-department-count='sections']")->item(0)));

        // Each picker option opens the page on that program, keeping the term.
        $options = $this->attributes($x, '//select[@data-program-select]/option', 'value');
        $this->assertSame(['term' => (string) $this->term], $this->queryOf($options[0]));
        $this->assertSame(['term' => (string) $this->term, 'program' => (string) $this->programB], $this->queryOf($options[2]));

        // One program: its sections only, and the strip and the table say the same.
        $x = $this->xpath($this->open('program=' . $this->programA));
        $this->assertSame([(string) $a1, (string) $a2], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertSame('Finalized 1 of 2 · Signed off 1 · Override requests waiting: 1', $this->strip($x));
        $this->assertSame('2', $this->text($x->query("//*[@data-department-count='sections']")->item(0)));
        $this->assertSame('Program DGA', $this->text($x->query('//select[@data-program-select]/option[@selected]')->item(0)));
        $this->assertSame(['term' => (string) $this->term, 'program' => (string) $this->programA, 'section' => (string) $a2], $this->queryOf($this->row($x, $a2)->getAttribute('href')));
        $this->assertSame(['term' => (string) $this->term, 'program' => (string) $this->programA, 'sort' => 'signed_off', 'dir' => 'asc'], $this->queryOf($x->query("//th[@data-column='signed_off']//a")->item(0)->getAttribute('href')));

        // The search keeps the program.
        $hidden = [];

        foreach ($x->query('//form[@data-program-search]//input[@type="hidden"]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $this->assertSame(['term' => (string) $this->term, 'program' => (string) $this->programA], $hidden);

        $x = $this->xpath($this->open('program=' . $this->programB));
        $this->assertSame([(string) $b1], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertSame('Finalized 1 of 1 · Signed off 0 · Override requests waiting: 1', $this->strip($x));

        // Sorting by Signed off: the signed-off section last ascending, first descending.
        $x = $this->xpath($this->open('sort=signed_off&dir=desc'));
        $this->assertSame((string) $a1, $this->attributes($x, '//a[@data-program-row]', 'data-section-id')[0]);
        $this->assertSame('descending', $x->query("//th[@data-column='signed_off']")->item(0)->getAttribute('aria-sort'));

        // A program of another college, or not a number, is no page.
        $other = DeanFactory::college('DGX', $this->unit['institution']);
        $this->assert404('program=' . DeanFactory::program(DeanFactory::department($other['college'], 'DGX'), 'DGX'));
        $this->assert404('program=abc');
    }

    public function testAnEmptyCollegeSaysSo(): void
    {
        $this->publish($this->outsideSection());

        $result = $this->open();
        $x      = $this->xpath($result);

        $result->assertSee('No sections in your college this term');
        $this->assertSame(0, $x->query('//*[@data-grades-grid] | //*[@data-nudge] | //*[@data-download-college] | //*[@data-load-error]')->length);
        $this->assertSame(['All programs', 'Program DGA', 'Program DGB'], $this->texts($x, '//select[@data-program-select]/option'));

        $this->section('IT101');
        $this->open('program=' . $this->programB)->assertSee('No sections in this program this term');
    }

    public function testASectionOutsideTheCollegeIs404(): void
    {
        $a = $this->section('IT101', null, $this->instructor());
        $b = $this->section('CS101', $this->programB, $this->instructor());
        $this->publish($a);

        $this->assert404('section=' . $this->outsideSection());
        $this->assert404('program=' . $this->programA . '&section=' . $b); // the college's, but not the program chosen
        $this->assert404('section=abc');

        $x = $this->xpath($this->open('section=' . $b));
        $this->assertSame((string) $b, $x->query('//*[@data-grades-grid]')->item(0)->getAttribute('data-section-id'));
        $this->assertSame('true', $this->row($x, $b)->getAttribute('aria-current'));
        $this->assertSame('CS101-A', $this->text($x->query('//*[@data-program-actions]//*[@data-action-label]')->item(0)));
    }

    public function testNoEditControlEver(): void
    {
        // Even in a section the dean teaches, open and published: no way to edit here.
        $open = $this->section('IT101', null, $this->dean);
        $this->publish($open);
        $this->student($open, 85.0);
        $closed = $this->section('IT102', null, $this->instructor());
        $this->publish($closed);
        db_connect()->table('course_offerings')->where('id', $closed)->update(['status' => 'completed']);

        foreach (['', 'section=' . $open, 'section=' . $closed, 'program=' . $this->programA] as $query) {
            $x = $this->xpath($this->open($query));

            $this->assertSame(0, $x->query('//*[@data-edit-as-chair] | //*[@data-edit-done] | //*[@data-open-finalize] | //*[@data-finalize-ready]')->length, $query);
            $this->assertSame(['1'], $this->attributes($x, '//*[@data-grades-grid]', 'data-readonly'), $query);
            $this->assertSame([], array_values(array_filter($this->texts($x, '//button | //a'), static fn (string $t): bool => str_starts_with($t, 'Edit') || str_starts_with($t, 'Finalize') || str_starts_with($t, 'Sign off'))), $query);
        }

        // The dean is never their own recipient.
        $x = $this->xpath($this->open('section=' . $open));
        $this->assertSame('[]', $this->row($x, $open)->getAttribute('data-instructors'));
    }

    public function testWithoutCoursesViewTheGridIsNotOffered(): void
    {
        $offering = $this->section('IT101', null, $this->instructor());
        $this->publish($offering);
        $this->clear('courses.view');

        $x = $this->xpath($this->open());

        // The table and the strip are the dean's (college.monitor); the gradebook is a course page (courses.view).
        $this->assertSame([(string) $offering], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertSame(0, $x->query('//*[@data-grades-grid] | //*[@data-open-in-course]')->length);
        $this->assertSame(1, $x->query('//*[@data-program-actions]')->length, 'the action bar stays, for Nudge');
        $this->assertSame(1, $x->query('//*[@data-grid-unavailable]')->length);
    }

    public function testWithoutCoursesViewTheAtRiskSectionsAreNotLinks(): void
    {
        $offering = $this->section('IT101', null, $this->instructor());
        $this->publish($offering);
        $this->student($offering, 40.0, 'Failed', 'Faye', 'Failing');
        $this->clear('courses.view');

        $x = $this->xpath($this->open('sub=at-risk'));

        $this->assertContains('Faye Failing', $this->texts($x, '//*[@data-risk-row]/th'));
        $this->assertSame(0, $x->query('//*[@data-risk-row]//a')->length, 'the course page would refuse them');
    }

    public function testCollegeAtRisk(): void
    {
        $a = $this->section('IT101', null, $this->instructor());
        $this->publish($a);
        $this->student($a, 40.0, 'Failed', 'Faye', 'Failing');
        $this->student($a, 90.0, 'Passed', 'Pat', 'Passing');

        $b = $this->section('CS101', $this->programB, $this->instructor());
        $this->publish($b);
        $this->student($b, 30.0, 'Failed', 'Bea', 'Behind');

        $outside = $this->outsideSection();
        $this->publish($outside);
        $this->student($outside, 30.0, 'Failed', 'Otto', 'Outside');

        $x = $this->xpath($this->open('sub=at-risk'));

        $this->assertSame('at-risk', $x->query("//*[@data-grades-sub][@aria-current='page']")->item(0)->getAttribute('data-grades-sub'));
        $this->assertStringContainsString('your college', $this->text($x->query('//*[@data-program-at-risk]/p')->item(0)));
        $names = $this->texts($x, '//*[@data-risk-row]/th');
        $this->assertContains('Faye Failing', $names);
        $this->assertContains('Bea Behind', $names);
        $this->assertNotContains('Pat Passing', $names);
        $this->assertNotContains('Otto Outside', $names);
        $this->assertSame(site_url("dean/courses/{$a}/course-it101/grades"), $x->query("//*[@data-risk-row][th[normalize-space()='Faye Failing']]//a")->item(0)->getAttribute('href'));
        $this->assertSame(0, $x->query('//a[@data-program-row] | //*[@data-grades-grid]')->length, 'the list, not the table');
        $this->assertSame(1, $x->query('//select[@data-risk-reason]')->length);

        // The program picker narrows the list too.
        $x = $this->xpath($this->open('sub=at-risk&program=' . $this->programB));
        $this->assertSame(['Bea Behind'], $this->texts($x, '//*[@data-risk-row]/th'));
        $this->assertSame(['sub' => 'at-risk', 'term' => (string) $this->term, 'program' => (string) $this->programB], $this->queryOf($x->query("//*[@data-grades-sub='at-risk']")->item(0)->getAttribute('href')));
    }

    /** @return array<string, int> row counts of every table but the audit log */
    private function counts(): array
    {
        $db  = db_connect();
        $out = [];

        foreach ($db->listTables() as $table) {
            if ($table !== 'audit_logs') {
                $out[$table] = $db->table($table)->countAllResults();
            }
        }

        return $out;
    }

    /** @return list<string> the views the page has */
    private function views(int $bare): array
    {
        return ['', 'sub=at-risk', 'program=' . $this->programA, 'section=' . $bare, 'q=IT&sort=signed_off&dir=desc&nf=1'];
    }

    public function testOpeningThePageWritesNothing(): void
    {
        $unschemed = $this->section('IT101', null, $this->instructor());
        $this->waiting($unschemed, 2, $this->instructor());
        $this->publish($this->section('IT102', null, $this->instructor()));
        $bare = $this->section('CS101', $this->programB, $this->instructor());

        $before = $this->counts();

        foreach ($this->views($bare) as $query) {
            $this->open($query);
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame(0, db_connect()->table('grading_schemes')->whereIn('course_offering_id', [$unschemed, $bare])->countAllResults(), 'no scheme is created by looking');
    }

    public function testViewingAsADeanWritesNothing(): void
    {
        $offering = $this->section('IT101', null, $this->instructor());
        $this->publish($offering);
        $this->waiting($offering, 1, $this->instructor());
        $bare = $this->section('CS101', $this->programB, $this->instructor());

        $admin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@dgp.test', $this->unit['institution']);
        $this->loggedInAs($admin);
        $this->withSession();
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $_SESSION['reauth']                         = ['user' => $admin->id, 'at' => time()];
        $this->withSession();

        $this->step('POST', "institution/view-as/{$this->dean->id}", ['reason' => 'Checking what they see']);
        $this->assertSame((int) $this->dean->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view started');

        $before = $this->counts();

        foreach ($this->views($bare) as $query) {
            Services::resetSingle('auth');
            $this->assertSame(200, $this->step('GET', 'dean/grades' . ($query === '' ? '' : '?' . $query))->response()->getStatusCode(), $query);
        }

        $this->assertSame($before, $this->counts());
    }
}

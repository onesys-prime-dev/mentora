<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The student's Grades page end to end: the term line and picker, the course rows with their grade, chip and
 * standing, the summary with the GWA and the courses left out of it, the empty state, the "Changed" badge, and
 * that viewing as a student writes nothing.
 *
 * @internal
 */
final class StudentGradesPageTest extends RouteTestCase
{
    private int $institution;

    private User $student;

    private ?int $term = null;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->institution = PeopleFactory::institution('SGP');
        $this->student     = PeopleFactory::person('student', 'Ada', 'Lovelace', null, $this->institution);
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** A course the student is in, in the one term the test's courses share unless another is given. */
    private function course(string $section = 'A', string $enrolment = 'enrolled', ?int $term = null): int
    {
        $offering = CourseFactory::offering($section);
        $db       = db_connect();

        CourseFactory::enroll($offering, $this->student, $enrolment);

        if ($term !== null) {
            $db->table('course_offerings')->where('id', $offering)->update(['term_id' => $term]);
        } elseif ($this->term === null) {
            $this->term = (int) $db->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
            $db->table('academic_terms')->where('id', $this->term)->update(['status' => 'active']);
        } else {
            $db->table('course_offerings')->where('id', $offering)->update(['term_id' => $this->term]);
        }

        return $offering;
    }

    /** A grading scheme with the given status and scale; returns the scheme id. */
    private function scheme(int $offering, string $status = 'Published', ?int $scale = null): int
    {
        $scheme = GradeFactory::scheme($offering, 75.0, false, $scale ?? GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $status]);

        return $scheme;
    }

    /** A band with a numeric value on the scale. */
    private function band(int $scale, string $name, ?float $value): int
    {
        db_connect()->table('grade_scale_items')->insert([
            'grade_scale_id' => $scale, 'name' => $name, 'numeric_value' => $value,
            'minimum_percentage' => 0, 'maximum_percentage' => 100, 'sort_order' => 99,
        ]);

        return (int) db_connect()->insertID();
    }

    /** @param array<string, mixed> $fields */
    private function record(int $offering, int $scheme, array $fields): void
    {
        db_connect()->table('gradebook_records')->insert($fields + [
            'course_offering_id' => $offering,
            'student_id'         => $this->student->id,
            'grading_scheme_id'  => $scheme,
            'is_override'        => 0,
            'remarks'            => 'Passed',
            'last_calculated_at' => date('Y-m-d H:i:s', time() - 300),
        ]);
    }

    private function finalized(int $offering, int $scheme, float $percentage, ?int $band, string $remarks = 'Passed'): void
    {
        $this->record($offering, $scheme, [
            'calculated_percentage' => $percentage, 'calculated_grade_scale_item_id' => $band,
            'final_percentage' => $percentage, 'final_grade_scale_item_id' => $band,
            'finalized_at' => '2026-12-01 10:00:00', 'remarks' => $remarks,
        ]);
    }

    private function codeOf(int $offering): string
    {
        return (string) db_connect()->table('course_offerings o')->select('c.course_code')->join('courses c', 'c.id = o.course_id')
            ->where('o.id', $offering)->get()->getRow()->course_code;
    }

    private function institutionOfTerm(): int
    {
        return (int) db_connect()->table('academic_terms')->where('id', $this->term)->get()->getRow()->institution_id;
    }

    private function defaultScale(int $scale): void
    {
        db_connect()->table('institution_academic_defaults')->insert(['institution_id' => $this->institutionOfTerm(), 'grade_scale_id' => $scale]);
    }

    private function open(string $query = ''): TestResponse
    {
        $this->loggedInAs($this->student);

        $result = $this->get('student/grades' . ($query !== '' ? '?' . $query : ''));
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

    private function row(DOMXPath $x, int $offering): ?DOMElement
    {
        $node = $x->query("//*[@data-grades-row][@data-offering-id='{$offering}']")->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function in(DOMXPath $x, DOMNode $context, string $query): ?DOMNode
    {
        return $x->query($query, $context)->item(0);
    }

    /** The value on a summary card, without the screen-reader tone word. */
    private function summary(DOMXPath $x, string $key): ?string
    {
        $value = $x->query("//*[@data-summary='{$key}']//span[contains(@class,'font-black')]")->item(0);

        return $value === null ? null : trim(str_replace(['Warning:', 'Needs attention:', 'On track:'], '', $this->text($value)));
    }

    public function testThePageListsTheTermsCoursesWithGradeChipAndStanding(): void
    {
        $scale = GradeFactory::scale();
        $band  = $this->band($scale, '1.75', 1.75);

        $final = $this->course('A');
        $this->finalized($final, $this->scheme($final, 'Published', $scale), 89.4, $band);
        CourseFactory::assignInstructor($final, PeopleFactory::person('instructor', 'Rosa', 'Santos', null, $this->institution));

        $inc = $this->course('B');
        $this->record($inc, $this->scheme($inc), [
            'calculated_percentage' => 71, 'remarks' => 'Incomplete', 'inc_set_at' => '2026-11-01 08:00:00',
            'inc_until' => '2026-12-20', 'inc_reason' => 'Missing final project',
        ]);

        $draft = $this->course('C');
        $this->scheme($draft, 'Draft');

        $withdrawn = $this->course('D', 'withdrawn');
        $this->record($withdrawn, $this->scheme($withdrawn), ['calculated_percentage' => 60, 'remarks' => 'Withdrawn']);

        $fresh = $this->course('E');
        $this->scheme($fresh);

        $result = $this->open();
        $x      = $this->xpath($result);

        $this->assertSame('Grades', $this->text($x->query('//h1')->item(0)));
        $result->assertSee('Your grades in every course, as they stand now.');
        $this->assertSame(0, $x->query('//*[@data-grades-tabs]')->length, 'the student has no tabs');

        $list = $x->query('//*[@data-breakdown-base]')->item(0);
        $this->assertSame(site_url('student/api/grades/courses'), $list->getAttribute('data-breakdown-base'));
        $this->assertCount(5, $x->query('//*[@data-grades-row]'));

        // Finalized: the band large, the percentage beside it, the chip and the standing beneath.
        $row = $this->row($x, $final);
        $this->assertStringContainsString($this->codeOf($final) . '-A · ', $this->text($row));
        $this->assertStringContainsString('3 units', $this->text($row));
        $this->assertStringContainsString('Rosa Santos', $this->text($row));
        $this->assertMatchesRegularExpression('/Updated (\d+ min ago|just now)/', $this->text($row), 'about five minutes ago; a minute may tick over while the page builds');
        $this->assertSame('1.75', $this->text($this->in($x, $row, './/*[@data-grade-main]')));
        $this->assertSame('89.40%', $this->text($this->in($x, $row, './/*[@data-grade-percentage]')));
        $this->assertSame('Finalized', $this->text($this->in($x, $row, './/*[@data-grade-status]')));
        $this->assertSame('Passed', $this->text($this->in($x, $row, './/*[@data-grade-tag]')));

        // Expandable: a disclosure button that controls the row's empty breakdown slot.
        $this->assertSame('1', $row->getAttribute('data-expandable'));
        $button = $this->in($x, $row, './/button[@aria-expanded]');
        $this->assertInstanceOf(DOMElement::class, $button);
        $this->assertSame('false', $button->getAttribute('aria-expanded'));
        $slot = $this->in($x, $row, './/*[@data-breakdown-slot]');
        $this->assertSame($slot->getAttribute('id'), $button->getAttribute('aria-controls'));
        $this->assertSame('', $this->text($slot));

        // INC: the finish-by date under the chip.
        $row = $this->row($x, $inc);
        $this->assertSame('In progress', $this->text($this->in($x, $row, './/*[@data-grade-status]')));
        $this->assertSame('INC · finish by Dec 20, 2026', $this->text($this->in($x, $row, './/*[@data-grade-tag]')));
        $this->assertSame('71.00%', $this->text($this->in($x, $row, './/*[@data-grade-main]')), 'no band: the percentage alone');

        // Not published: no grade, no standing, nothing to open.
        $row = $this->row($x, $draft);
        $this->assertSame('Not published yet', $this->text($this->in($x, $row, './/*[@data-grade-status]')));
        $this->assertSame('—', $this->text($this->in($x, $row, './/*[@data-grade-main]')));
        $this->assertNull($this->in($x, $row, './/*[@data-grade-tag]'));
        $this->assertSame('0', $row->getAttribute('data-expandable'));
        $this->assertNull($this->in($x, $row, './/button'));

        // Withdrawn: the chip says so; nothing to open.
        $row = $this->row($x, $withdrawn);
        $this->assertSame('Withdrawn', $this->text($this->in($x, $row, './/*[@data-grade-status]')));
        $this->assertSame('0', $row->getAttribute('data-expandable'));
        $this->assertNull($this->in($x, $row, './/button'));

        // Published but no record yet: a dash, in progress, and it can still be opened.
        $row = $this->row($x, $fresh);
        $this->assertSame('—', $this->text($this->in($x, $row, './/*[@data-grade-main]')));
        $this->assertSame('In progress', $this->text($this->in($x, $row, './/*[@data-grade-status]')));
        $this->assertSame('1', $row->getAttribute('data-expandable'));
        $this->assertStringNotContainsString('Updated', $this->text($row));
    }

    public function testEveryCourseThatCanStillBeOpenedLinksToIt(): void
    {
        $graded = $this->course('A');
        $this->record($graded, $this->scheme($graded), ['calculated_percentage' => 88]);

        $draft = $this->course('B');
        $this->scheme($draft, 'Draft');

        $bare = $this->course('C'); // no scheme at all

        $withdrawn = $this->course('D', 'withdrawn');

        $x = $this->xpath($this->open());

        foreach ([$graded, $draft, $bare] as $offering) {
            $row   = $this->row($x, $offering);
            $title = db_connect()->table('course_offerings o')->select('c.course_title')->join('courses c', 'c.id = o.course_id')->where('o.id', $offering)->get()->getRow()->course_title;
            $link  = $this->in($x, $row, './/h3//a[@href]');

            $this->assertInstanceOf(DOMElement::class, $link, "row {$offering} names its course as a link");
            $this->assertSame(site_url('student/courses/' . $offering . '/' . url_title((string) $title, '-', true)), $link->getAttribute('href'));
            $this->assertStringContainsString('focus-visible:ring', $link->getAttribute('class'), 'a visible focus ring');
            $this->assertNull($this->in($x, $link, 'ancestor::button | .//button'), 'not nested in (or around) the disclosure');
        }

        // The course routes refuse a withdrawn enrolment, so its row has nothing to open.
        $this->assertNull($this->in($x, $this->row($x, $withdrawn), './/a'));
    }

    public function testTheBarSaysTheTermCoursesUnitsAndEnd(): void
    {
        $a = $this->course('A');
        $this->course('B');
        $this->course('C', 'withdrawn'); // a course, but not its units
        $end = date('Y-m-d', strtotime('+40 days'));
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['name' => '1st Sem', 'end_date' => $end]);
        db_connect()->table('courses')->where('id', db_connect()->table('course_offerings')->where('id', $a)->get()->getRow()->course_id)->update(['units' => 1.5]);

        $x    = $this->xpath($this->open());
        $line = $x->query('//*[@data-term-line]')->item(0);

        $this->assertSame('1st Sem · 3 courses · 4.5 units · ends ' . date('M j', strtotime($end)), $this->text($line));
        $this->assertSame('1st Sem', $this->text($x->query('.//b | .//strong', $line)->item(0)), 'the term name is bold');

        $past = date('Y-m-d', strtotime('-3 days'));
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['end_date' => $past]);
        $this->assertStringEndsWith('· ended ' . date('M j', strtotime($past)), $this->text($this->xpath($this->open())->query('//*[@data-term-line]')->item(0)));

        // The picker reads "Change term" and lists the term.
        $picker = $x->query('//select[@data-term-select]')->item(0);
        $this->assertSame('Change term', $this->text($x->query('./option', $picker)->item(0)));
        $this->assertStringContainsString('1st Sem', $this->text($x->query('./option', $picker)->item(1)));
    }

    public function testTheGwaCardAndNotCountedNote(): void
    {
        $scale = GradeFactory::scale();

        $a = $this->course('A');
        $this->finalized($a, $this->scheme($a, 'Published', $scale), 89.4, $this->band($scale, '1.75', 1.75));
        $b = $this->course('B');
        $this->finalized($b, $this->scheme($b, 'Published', $scale), 86.0, $this->band($scale, '2.00', 2.0));
        $c = $this->course('C');
        $this->record($c, $this->scheme($c, 'Published', $scale), ['calculated_percentage' => 70, 'remarks' => 'Failed']);

        // No default scale: no GWA card, and no note about it.
        $x = $this->xpath($this->open());
        $this->assertSame(0, $x->query("//*[@data-summary='gwa']")->length);
        $this->assertSame(0, $x->query('//*[@data-not-counted]')->length);
        $this->assertSame('9', $this->summary($x, 'units'));
        $this->assertSame('2', $this->summary($x, 'passed'));
        $this->assertSame('0', $this->summary($x, 'failed'));
        $this->assertSame('0', $this->summary($x, 'inc'));

        $this->defaultScale($scale);

        $x = $this->xpath($this->open());
        $this->assertSame('1.88', $this->summary($x, 'gwa'));
        foreach (['GWA', 'Units', 'Passed', 'Failed', 'INC'] as $label) {
            $this->assertStringContainsString($label, $this->text($x->query('//*[@data-grades-summary]')->item(0)));
        }
        $this->assertSame('1 course not counted in the GWA: ' . $this->codeOf($c) . ' (in progress)', $this->text($x->query('//*[@data-not-counted]')->item(0)));

        // Nothing counted: a dash, and both left-out courses named.
        db_connect()->table('gradebook_records')->where('course_offering_id', $b)->update(['finalized_at' => null, 'final_percentage' => null, 'final_grade_scale_item_id' => null]);
        db_connect()->table('grading_schemes')->where('course_offering_id', $a)->update(['status' => 'Draft']);

        $x = $this->xpath($this->open());
        $this->assertSame('—', $this->summary($x, 'gwa'));
        $note = $this->text($x->query('//*[@data-not-counted]')->item(0));
        $this->assertStringStartsWith('3 courses not counted in the GWA: ', $note);
        $this->assertStringContainsString($this->codeOf($a) . ' (not published)', $note);
    }

    public function testAnotherTermByQuery(): void
    {
        $current = $this->course('A');
        $older   = CourseFactory::offering('B');
        CourseFactory::enroll($older, $this->student);
        $olderTerm = (int) db_connect()->table('course_offerings')->where('id', $older)->get()->getRow()->term_id;
        db_connect()->table('academic_terms')->where('id', $olderTerm)->update(['name' => 'Old term', 'start_date' => '2025-08-01', 'status' => 'completed']);

        $x = $this->xpath($this->open());
        $this->assertNotNull($this->row($x, $current));
        $this->assertNull($this->row($x, $older));

        $x = $this->xpath($this->open('term=' . $olderTerm));
        $this->assertNull($this->row($x, $current));
        $this->assertNotNull($this->row($x, $older));
        $this->assertStringStartsWith('Old term · 1 course · 3 units', $this->text($x->query('//*[@data-term-line]')->item(0)));

        // The picker links to each term on this page.
        $values = [];
        foreach ($x->query('//select[@data-term-select]/option') as $option) {
            $values[] = $option->getAttribute('value');
        }
        $this->assertContains(site_url('student/grades') . '?term=' . $this->term, $values);
    }

    public function testAnUnknownTermFallsBackToTheDefault(): void
    {
        $current = $this->course('A');
        $stranger = CourseFactory::offering('Z'); // a term the student is not in
        $theirs   = (int) db_connect()->table('course_offerings')->where('id', $stranger)->get()->getRow()->term_id;

        foreach (['term=' . $theirs, 'term=abc', 'term=999999'] as $query) {
            $x = $this->xpath($this->open($query));
            $this->assertNotNull($this->row($x, $current), $query);
            $this->assertSame(0, $x->query('//*[@data-load-error]')->length, $query);
        }
    }

    public function testNoCoursesShowsTheEmptyState(): void
    {
        $result = $this->open();
        $x      = $this->xpath($result);

        // No term at all: the empty state does not speak of a term.
        $result->assertSee('No courses yet');
        $result->assertDontSee('No courses this term');
        $this->assertSame(0, $x->query('//*[@data-grades-row] | //*[@data-grades-summary] | //select[@data-term-select] | //*[@data-load-error]')->length);
    }

    public function testATermWithNoCoursesSaysSo(): void
    {
        helper('grade');

        $html = view('pages/grades/partials/student/rows', ['rows' => [], 'hasTerms' => true], ['saveData' => false]);

        $this->assertStringContainsString('No courses this term', $html);
        $this->assertStringNotContainsString('No courses yet', $html);
    }

    public function testChangedShowsOnlyAfterAGradeChanges(): void
    {
        $offering = $this->course('A');
        $this->record($offering, $this->scheme($offering), ['calculated_percentage' => 80]);
        $badge = "//*[@data-grades-row][@data-offering-id='{$offering}']//*[@data-changed]";

        $this->assertSame(0, $this->xpath($this->open())->query($badge)->length, 'a first look is no change');
        $this->assertSame(0, $this->xpath($this->open())->query($badge)->length, 'nothing moved');

        db_connect()->table('gradebook_records')->where('course_offering_id', $offering)->update(['calculated_percentage' => 85]);

        $x = $this->xpath($this->open());
        $this->assertSame(1, $x->query($badge)->length);
        $this->assertSame('Changed', $this->text($x->query($badge)->item(0)));

        $this->assertSame(0, $this->xpath($this->open())->query($badge)->length, 'shown once');
    }

    public function testAGradePublishedForTheFirstTimeIsChanged(): void
    {
        $offering = $this->course('A');
        $scheme   = $this->scheme($offering, 'Draft');
        $badge    = "//*[@data-grades-row][@data-offering-id='{$offering}']//*[@data-changed]";

        $this->assertSame(0, $this->xpath($this->open())->query($badge)->length, 'not published yet, and a first look');

        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        $this->record($offering, $scheme, ['calculated_percentage' => 88]);

        $x = $this->xpath($this->open());
        $this->assertSame('Changed', $this->text($x->query($badge)->item(0)), 'the grade came out since the last look');

        $this->assertSame(0, $this->xpath($this->open())->query($badge)->length, 'shown once');
    }

    public function testACourseNeverSeenBeforeIsNotChanged(): void
    {
        $this->course('A');
        $this->open();

        // A new course, already graded, appears after the first look: it is new, not changed.
        $later = $this->course('B');
        $this->record($later, $this->scheme($later), ['calculated_percentage' => 88]);

        $this->assertSame(0, $this->xpath($this->open())->query("//*[@data-grades-row][@data-offering-id='{$later}']//*[@data-changed]")->length);
    }

    public function testAWithdrawnCourseIsRemembered(): void
    {
        $offering = $this->course('A', 'withdrawn');
        $this->scheme($offering, 'Draft');

        $this->open();

        $this->assertSame(1, db_connect()->table('grades_seen')->where('offering_id', $offering)->countAllResults(), 'what was shown is remembered');
    }

    public function testViewingAsAStudentWritesNothing(): void
    {
        $offering = $this->course('A');
        $this->record($offering, $this->scheme($offering), ['calculated_percentage' => 80]);

        $this->loggedInAs($this->student);
        session()->set('impersonator_id', 1);
        session()->set('impersonation', ['target_id' => (int) $this->student->id, 'reason' => 'Checking the grades', 'started_at' => time(), 'expires_at' => time() + 600]);
        $this->withSession();

        $this->get('student/grades')->assertStatus(200);
        $this->assertSame(0, db_connect()->table('grades_seen')->countAllResults());

        session()->remove(['impersonator_id', 'impersonation']);
        $this->withSession();

        $this->get('student/grades')->assertStatus(200);
        $this->assertSame(1, db_connect()->table('grades_seen')->countAllResults(), 'their own look is remembered');
    }

    public function testWithoutCoursesViewThereIsNoPage(): void
    {
        helper('navigation');
        $this->course('A');

        $this->open();
        $this->assertContains('grades', array_column(global_apps('student'), 'key'));

        db_connect()->table('role_permissions')->insert(['institution_id' => $this->institution, 'role' => 'student', 'permission' => 'courses.view', 'granted' => 0]);
        PermissionMatrix::flush();
        auth()->logout();
        $this->withSession([]);
        $this->student = model(UserModel::class)->findById($this->student->id);
        $this->loggedInAs($this->student);

        $this->step('get', 'student/grades');
        $this->assertSame(lang('Auth.notEnoughPrivilege'), session('error'));
        $this->assertNotContains('grades', array_column(global_apps('student'), 'key'), 'no page, no tile');
    }
}

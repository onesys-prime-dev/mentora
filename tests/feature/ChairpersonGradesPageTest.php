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
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The chairperson's Grades page end to end: My sections (the instructor workbench without its finalize
 * controls) and Program (the tracker, the section table and the selected section's grid, read-only), which tab
 * shows when, the permissions behind each control, the 404 for a section outside the program, and that
 * looking writes nothing.
 *
 * @internal
 */
final class ChairpersonGradesPageTest extends RouteTestCase
{
    /** @var array{institution: int, college: int} */
    private array $unit;

    private int $department;

    private int $program;

    private int $term;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();

        $this->unit    = DeanFactory::college('CGP');
        $this->department     = DeanFactory::department($this->unit['college'], 'CGP');
        $this->program = DeanFactory::program($this->department, 'CGP');
        $this->term    = DeanFactory::term($this->unit['institution']); // 2026-08-01 to 2026-12-20
        $this->chair   = DeanFactory::chair($this->department, 'Cara');
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

    /** A section of the chaired program in the test's term, taught by $teachers; IT101-A and IT101-B share a course. */
    private function section(string $code, string $section = 'A', User ...$teachers): int
    {
        $db     = db_connect();
        $course = $db->table('courses')->where('program_id', $this->program)->where('course_code', $code)->get()->getRow();

        if ($course === null) {
            $offering = DeanFactory::offering($this->program, $this->term, $code, $section);
        } else {
            $db->table('course_offerings')->insert(['course_id' => $course->id, 'term_id' => $this->term, 'section_code' => $section, 'status' => 'active']);
            $offering = (int) $db->insertID();
        }

        foreach ($teachers as $teacher) {
            CourseFactory::assignInstructor($offering, $teacher);
        }

        return $offering;
    }

    /** A section of another department of the same college: not the chairperson's. */
    private function outsideSection(string $code = 'CS101'): int
    {
        $department     = DeanFactory::department($this->unit['college'], 'OUT' . $code);
        $program = DeanFactory::program($department, 'OUT' . $code);

        return DeanFactory::offering($program, $this->term, $code);
    }

    private function publish(int $offering): int
    {
        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        GradeFactory::category($scheme, 'Exams', 100);

        return $scheme;
    }

    private function schemeOf(int $offering): ?int
    {
        $row = db_connect()->table('grading_schemes')->where('course_offering_id', $offering)->get()->getRow();

        return $row === null ? null : (int) $row->id;
    }

    /** An enrolled student with a gradebook record. */
    private function student(int $offering, ?float $percentage, string $remarks = 'Passed', string $first = 'Ada', string $last = 'Lovelace'): User
    {
        $student = PeopleFactory::person('student', $first, $last, null, $this->unit['institution']);
        CourseFactory::enroll($offering, $student);

        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => $this->schemeOf($offering),
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
        db_connect()->table('grade_finalizations')->insert([
            'course_offering_id' => $offering, 'finalized_by' => $this->chair->id, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1,
        ]);
    }

    /** Takes a permission away from every chairperson of the institution, and signs the chairperson in afresh. */
    private function clear(string $permission): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => $this->unit['institution'], 'role' => 'chairperson', 'permission' => $permission, 'granted' => 0]);
        PermissionMatrix::flush();

        auth()->logout();
        $this->withSession([]);
        $this->chair = model(UserModel::class)->findById($this->chair->id);
    }

    private function open(string $query = ''): TestResponse
    {
        $this->loggedInAs($this->chair);

        $result = $this->get('chairperson/grades' . ($query !== '' ? '?' . $query : ''));
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

    /** The query of a link on the page, as an array. */
    private function queryOf(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $query);

        return $query;
    }

    /** The program row of a section: its link, which carries the data the page script reads. */
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

    private function assert404(string $query): void
    {
        $this->loggedInAs($this->chair);

        try {
            $this->get('chairperson/grades?' . $query);
            $this->fail("chairperson/grades?{$query} should be a 404");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMySectionsIsTheWorkbenchWithoutFinalizeControls(): void
    {
        $ready = $this->section('IT101', 'A', $this->chair);
        $this->publish($ready);

        $busy = $this->section('IT102', 'A', $this->chair);
        $this->publish($busy);
        $this->waiting($busy, 2, $this->chair, 3);

        $this->publish($this->section('IT103', 'A', $this->instructor())); // the program's, not theirs

        $result = $this->open();
        $x      = $this->xpath($result);

        $result->assertSee("Your sections and your program's.");
        $this->assertSame(['My sections', 'Program'], $this->texts($x, "//*[@data-grades-tab]"));
        $this->assertSame('mine', $x->query("//*[@data-grades-tab][@aria-current='page']")->item(0)->getAttribute('data-grades-tab'), 'teaching a chaired section opens on My sections');
        $this->assertSame(['gradebook', 'to-grade', 'at-risk'], $this->attributes($x, '//*[@data-grades-sub]', 'data-grades-sub'));
        $this->assertSame(['Gradebook', 'To grade', 'At risk'], $this->texts($x, '//*[@data-grades-sub]/*[@data-department-label]'));

        // The instructor's cards, for the sections they teach in the program, opening on the busiest.
        $this->assertSame([(string) $ready, (string) $busy], $this->attributes($x, '//*[@data-section-card]', 'data-section-card'));
        $this->assertSame(['mine'], array_values(array_unique(array_map(fn (string $h): string => $this->queryOf($h)['tab'] ?? '', $this->attributes($x, '//*[@data-section-card]', 'href')))));

        $grid = $x->query('//*[@data-grades-grid]')->item(0);
        $this->assertSame(site_url("chairperson/api/courses/{$busy}"), $grid->getAttribute('data-api-base'));
        $this->assertSame((string) $busy, $grid->getAttribute('data-section-id'));
        $this->assertSame(site_url("chairperson/courses/{$busy}/course-it102/grades"), $x->query('//*[@data-open-in-course]')->item(0)->getAttribute('href'));
        $this->assertStringContainsString("chairperson/api/courses/{$busy}/grades/report/class", $x->query("//*[@data-download-link='class']")->item(0)->getAttribute('href'));

        // Finalizing is the instructor's: none of its controls, even with a ready section.
        $this->assertSame(0, $x->query("//*[@data-finalize-ready] | //*[@data-download-all] | //*[@data-open-finalize] | //*[@id='grade-finalize-modal'] | //*[@id='finalize-ready-dialog']")->length);
        $this->assertSame([], array_values(array_filter($this->texts($x, '//button | //a'), static fn (string $text): bool => str_starts_with($text, 'Finalize') || $text === 'Download all')));

        // To grade: the work waiting in their sections, Grade → opens the chairperson's course with the way back.
        $x    = $this->xpath($this->open('tab=mine&sub=to-grade'));
        $href = $x->query('//*[@data-to-grade-row]//a[@data-grade-link]')->item(0)->getAttribute('href');
        $this->assertStringStartsWith(site_url("chairperson/courses/{$busy}/course-it102/assessments"), html_entity_decode($href));
        $this->assertSame((string) parse_url(site_url('chairperson/grades'), PHP_URL_PATH) . '?tab=mine&sub=to-grade&term=' . $this->term, $this->queryOf($href)['return']);
        $this->assertSame('to-grade', $x->query("//*[@data-grades-sub][@aria-current='page']")->item(0)->getAttribute('data-grades-sub'));

        $this->open('tab=mine&sub=at-risk')->assertSee('No one is at risk');
    }

    public function testNoTaughtSectionsOpensOnProgramWithoutAMySectionsTab(): void
    {
        $offering = $this->section('IT101', 'A', $this->instructor());
        $this->publish($offering);

        foreach (['', 'tab=mine', 'tab=nonsense'] as $query) {
            $x = $this->xpath($this->open($query));

            $this->assertSame(0, $x->query('//*[@data-grades-tab]')->length, "only one tab, so no tab bar ({$query})");
            $this->assertSame('Program', $this->text($x->query('//*[@data-grades-tab-label]')->item(0)));
            $this->assertSame(['Sections', 'At risk'], $this->texts($x, '//*[@data-grades-sub]/*[@data-department-label]'));
            $this->assertSame([(string) $offering], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
            $this->assertSame(0, $x->query('//*[@data-section-card]')->length);
        }
    }

    public function testWithoutProgramMonitorThereIsNoProgramTab(): void
    {
        $this->publish($this->section('IT101', 'A', $this->instructor()));
        $this->clear('program.monitor');

        // Teaching nothing either: My sections alone, with its empty state, not an error or an empty bar.
        $result = $this->open();
        $x      = $this->xpath($result);

        $result->assertSee('No sections this term');
        $this->assertSame(0, $x->query('//*[@data-grades-tab] | //*[@data-load-error] | //*[@data-program-row] | //*[@data-tracker]')->length);
        $this->assertSame('My sections', $this->text($x->query('//*[@data-grades-tab-label]')->item(0)));

        $mine = $this->section('IT102', 'A', $this->chair);
        $this->publish($mine);

        $x = $this->xpath($this->open('tab=program'));
        $this->assertSame([(string) $mine], $this->attributes($x, '//*[@data-section-card]', 'data-section-card'));
        $this->assertSame(0, $x->query('//*[@data-grades-tab] | //*[@data-program-row] | //*[@data-nudge] | //*[@data-download-program]')->length);
        $this->assertSame(['Gradebook', 'To grade', 'At risk'], $this->texts($x, '//*[@data-grades-sub]/*[@data-department-label]'));
    }

    public function testTheProgramTabShowsTrackerTableAndTheSelectedGridReadOnly(): void
    {
        $santos = $this->instructor('Mara', 'Santos');
        $garcia = $this->instructor('Jose', 'Garcia');

        $finalized = $this->section('IT101', 'A', $santos);
        $this->publish($finalized);
        $this->finalize($finalized);

        $ready = $this->section('IT101', 'B', $garcia);
        $this->publish($ready);
        $this->student($ready, 80.0);
        $this->student($ready, 90.0);

        $waiting = $this->section('IT102', 'A', $santos, $garcia);
        $this->publish($waiting);
        $this->waiting($waiting, 3, $santos, 4);
        $this->student($waiting, 40.0, 'Failed');

        $draft = $this->section('IT103', 'A');
        GradeFactory::scheme($draft);
        $this->student($draft, 70.0);

        $result = $this->open('tab=program');
        $x      = $this->xpath($result);

        $result->assertSee("Your sections and your program's.");
        $this->assertSame('Finalized 1 of 4 · 1 ready · 2 blocked', $this->text($x->query('//*[@data-tracker-line]')->item(0)));
        $this->assertSame(1, $x->query('//a[@data-not-finalized]')->length);
        $this->assertStringContainsString('Not finalized only', $this->text($x->query('//a[@data-not-finalized]')->item(0)));

        $this->assertSame(['Section', 'Instructor', 'Average', 'At risk', 'Waiting', 'Status'], $this->texts($x, '//table[@data-program-table]//thead//*[@data-column-label]'));
        $this->assertSame([(string) $finalized, (string) $ready, (string) $waiting, (string) $draft], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));

        // What the page script needs, on each row.
        $row = $this->row($x, $waiting);
        $this->assertSame('course-it102', $row->getAttribute('data-slug'));
        $this->assertSame('waiting', $row->getAttribute('data-state'));
        $this->assertSame('IT102-A', $row->getAttribute('data-label'));
        $this->assertSame([['id' => (int) $garcia->id, 'name' => 'Jose Garcia'], ['id' => (int) $santos->id, 'name' => 'Mara Santos']], json_decode($row->getAttribute('data-instructors'), true));
        $this->assertSame('3', $row->getAttribute('data-waiting'));
        $this->assertSame('4', $row->getAttribute('data-oldest-days'));
        $this->assertSame('2026-12-20', $row->getAttribute('data-term-end'));
        $this->assertSame('', $this->row($x, $ready)->getAttribute('data-oldest-days'), 'nothing waiting, no age');
        $this->assertSame(['tab' => 'program', 'term' => (string) $this->term, 'section' => (string) $waiting], $this->queryOf($row->getAttribute('href')));

        // The columns, the state in a word beside its dot.
        $this->assertSame('Jose Garcia, Mara Santos', $this->cell($x, $waiting, 'instructor'));
        $this->assertSame('85.0%', $this->cell($x, $ready, 'average'));
        $this->assertSame('—', $this->cell($x, $draft, 'average'), 'not published: no average');
        $this->assertSame('1', $this->cell($x, $waiting, 'at_risk'));
        $this->assertSame('3', $this->cell($x, $waiting, 'waiting'));
        $this->assertSame('Finalized', $this->cell($x, $finalized, 'status'));
        $this->assertSame('Ready', $this->cell($x, $ready, 'status'));
        $this->assertSame('Waiting', $this->cell($x, $waiting, 'status'));
        $this->assertSame('Not published', $this->cell($x, $draft, 'status'));

        $this->assertSame(1, $x->query('//button[@type="button"][@data-nudge]')->length);
        $this->assertSame('Nudge', $this->text($x->query('//*[@data-nudge]')->item(0)));
        $this->assertSame('Download program', $this->text($x->query('//a[@data-download-program]')->item(0)));

        // The first row's grid, below the table, read-only until Edit as chairperson.
        $this->assertSame('true', $this->row($x, $finalized)->getAttribute('aria-current'));
        $grid = $x->query('//*[@data-grades-grid]')->item(0);
        $this->assertSame(site_url("chairperson/api/courses/{$finalized}"), $grid->getAttribute('data-api-base'));
        $this->assertSame((string) $finalized, $grid->getAttribute('data-section-id'));
        $this->assertSame('1', $grid->getAttribute('data-readonly'));

        $roster = $this->get("chairperson/api/courses/{$finalized}/grades/roster?embedded=1&readonly=1");
        $roster->assertStatus(200);
        $intro = html_entity_decode((string) json_decode((string) $roster->getJSON(), true)['html']);
        $this->assertStringContainsString('Read-only. Quiz and assignment scores come from Grade Work', $intro);
        $this->assertStringNotContainsString('Click a final grade', $intro);

        $this->assertSame('Edit as chairperson', $this->text($x->query('//button[@data-edit-as-chair]')->item(0)));
        $done = $x->query('//button[@data-edit-done]')->item(0);
        $this->assertSame('Done', $this->text($done));
        $this->assertTrue($done->hasAttribute('hidden'));
        $this->assertSame(site_url("chairperson/courses/{$finalized}/course-it101/grades"), $x->query('//*[@data-program-actions]//*[@data-open-in-course]')->item(0)->getAttribute('href'));
        $this->assertSame(0, $x->query("//*[@data-open-finalize] | //*[@id='grade-finalize-modal']")->length);

        // ?section= picks another row.
        $x = $this->xpath($this->open('tab=program&section=' . $waiting));
        $this->assertSame((string) $waiting, $x->query('//*[@data-grades-grid]')->item(0)->getAttribute('data-section-id'));
        $this->assertSame('true', $this->row($x, $waiting)->getAttribute('aria-current'));
        $this->assertFalse($this->row($x, $finalized)->hasAttribute('aria-current'));
        $this->assertSame('IT102-A', $this->text($x->query('//*[@data-program-actions]//*[@data-action-label]')->item(0)));
    }

    public function testSearchSortFilterAndPageAreInTheAddress(): void
    {
        $ids = [];

        for ($i = 1; $i <= 27; $i++) {
            $ids[$i] = $this->section(sprintf('IT%03d', $i));
            $this->publish($ids[$i]);
        }

        $this->finalize($ids[1]);

        // Pages of 25, the next page keeping the tab and the term.
        $x = $this->xpath($this->open('tab=program'));
        $this->assertCount(25, $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $next = $this->queryOf($x->query("//nav[@aria-label='Pages']//a[@rel='next']")->item(0)->getAttribute('href'));
        $this->assertSame(['tab' => 'program', 'term' => (string) $this->term, 'page' => '2'], $next);

        $x = $this->xpath($this->open('tab=program&page=2'));
        $this->assertSame([(string) $ids[26], (string) $ids[27]], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertSame('2', $this->queryOf($this->row($x, $ids[26])->getAttribute('href'))['page'], 'a row keeps the page');
        $this->assertSame((string) $ids[26], $x->query('//*[@data-grades-grid]')->item(0)->getAttribute('data-section-id'), "the page's first row");

        // Sorting: a header link per column; the sorted one turns the other way.
        $x       = $this->xpath($this->open('tab=program&page=2'));
        $average = $this->queryOf($x->query("//th[@data-column='average']//a")->item(0)->getAttribute('href'));
        $this->assertSame(['tab' => 'program', 'term' => (string) $this->term, 'sort' => 'average', 'dir' => 'asc'], $average, 'a new sort starts on page 1');
        $this->assertSame('ascending', $x->query("//th[@data-column='section']")->item(0)->getAttribute('aria-sort'));
        $this->assertSame('desc', $this->queryOf($x->query("//th[@data-column='section']//a")->item(0)->getAttribute('href'))['dir']);

        $x = $this->xpath($this->open('tab=program&sort=section&dir=desc'));
        $this->assertSame((string) $ids[27], $this->attributes($x, '//a[@data-program-row]', 'data-section-id')[0]);
        $this->assertSame('descending', $x->query("//th[@data-column='section']")->item(0)->getAttribute('aria-sort'));

        // Not finalized only.
        $x  = $this->xpath($this->open('tab=program'));
        $nf = $this->queryOf($x->query('//a[@data-not-finalized]')->item(0)->getAttribute('href'));
        $this->assertSame('1', $nf['nf']);
        $this->assertArrayNotHasKey('page', $nf);

        $x = $this->xpath($this->open('tab=program&nf=1'));
        $this->assertNotContains((string) $ids[1], $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $this->assertArrayNotHasKey('nf', $this->queryOf($x->query('//a[@data-not-finalized]')->item(0)->getAttribute('href')), 'the link turns it off again');
        $this->assertSame('Finalized 1 of 27 · 26 ready · 0 blocked', $this->text($x->query('//*[@data-tracker-line]')->item(0)), 'the tracker counts the whole term');

        // Search: the form keeps the other choices; the rows keep everything.
        $x = $this->xpath($this->open('tab=program&q=IT02&sort=average&dir=desc&nf=1'));
        $this->assertCount(8, $this->attributes($x, '//a[@data-program-row]', 'data-section-id'));
        $form = $x->query('//form[@data-program-search]')->item(0);
        $this->assertSame('IT02', $x->query('.//input[@name="q"][@data-auto-search]', $form)->item(0)->getAttribute('value'));
        $hidden = [];

        foreach ($x->query('.//input[@type="hidden"]', $form) as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $this->assertSame(['tab' => 'program', 'term' => (string) $this->term, 'nf' => '1', 'sort' => 'average', 'dir' => 'desc'], $hidden);

        $first = $this->attributes($x, '//a[@data-program-row]', 'data-section-id')[0];
        $this->assertSame(
            ['tab' => 'program', 'term' => (string) $this->term, 'q' => 'IT02', 'nf' => '1', 'sort' => 'average', 'dir' => 'desc', 'section' => $first],
            $this->queryOf($this->row($x, (int) $first)->getAttribute('href'))
        );

        $this->open('tab=program&q=nothing+like+it')->assertSee('No sections match');
    }

    public function testAnEmptyProgramSaysSo(): void
    {
        $this->publish($this->outsideSection());

        $result = $this->open('tab=program');
        $x      = $this->xpath($result);

        $result->assertSee('No sections in your program this term');
        $this->assertSame(0, $x->query('//*[@data-grades-grid] | //*[@data-nudge] | //*[@data-download-program] | //*[@data-load-error]')->length);
    }

    public function testASectionOutsideTheProgramIs404(): void
    {
        $this->publish($this->section('IT101', 'A', $this->chair));
        $notTaught = $this->section('IT102', 'A', $this->instructor());
        $outside   = $this->outsideSection();
        CourseFactory::assignInstructor($outside, $this->chair); // taught, but not chaired

        $this->assert404('tab=program&section=' . $outside);
        $this->assert404('tab=mine&section=' . $outside);
        $this->assert404('tab=mine&section=' . $notTaught);
        $this->assert404('tab=program&section=abc');

        $this->assertSame((string) $notTaught, $this->xpath($this->open('tab=program&section=' . $notTaught))->query('//*[@data-grades-grid]')->item(0)->getAttribute('data-section-id'));
    }

    public function testProgramAtRiskListsTheProgramsStudents(): void
    {
        $offering = $this->section('IT101', 'A', $this->instructor());
        $this->publish($offering);
        $this->student($offering, 40.0, 'Failed', 'Faye', 'Failing');
        $this->student($offering, 90.0, 'Passed', 'Pat', 'Passing');

        $outside = $this->outsideSection();
        $this->publish($outside);
        $this->student($outside, 30.0, 'Failed', 'Otto', 'Outside');

        $x = $this->xpath($this->open('tab=program&sub=at-risk'));

        $this->assertSame('at-risk', $x->query("//*[@data-grades-sub][@aria-current='page']")->item(0)->getAttribute('data-grades-sub'));
        $names = $this->texts($x, '//*[@data-risk-row]/th');
        $this->assertContains('Faye Failing', $names);
        $this->assertNotContains('Otto Outside', $names);
        $this->assertSame(['grade'], $this->attributes($x, "//*[@data-risk-row][th[normalize-space()='Faye Failing']]//*[@data-reason='grade']", 'data-reason'));
        $this->assertSame(site_url("chairperson/courses/{$offering}/course-it101/grades"), $x->query("//*[@data-risk-row][th[normalize-space()='Faye Failing']]//a")->item(0)->getAttribute('href'));
        $this->assertSame(0, $x->query('//a[@data-program-row] | //*[@data-grades-grid]')->length, 'the list, not the table');

        $x = $this->xpath($this->open('tab=program&sub=at-risk&reason=grade'));
        $this->assertSame(['Faye Failing'], $this->texts($x, '//*[@data-risk-row]/th'));
    }

    public function testControlsHiddenWithoutTheirPermissions(): void
    {
        $this->publish($this->section('IT101', 'A', $this->instructor()));
        $this->publish($this->section('IT102', 'A', $this->chair));

        $x = $this->xpath($this->open('tab=program'));
        $this->assertSame(1, $x->query('//*[@data-edit-as-chair]')->length);
        $this->assertSame(1, $x->query('//*[@data-nudge]')->length);

        $this->clear('grading.manage');

        $x = $this->xpath($this->open('tab=program'));
        $this->assertSame(0, $x->query('//*[@data-edit-as-chair] | //*[@data-edit-done]')->length);
        $this->assertSame(1, $x->query('//*[@data-nudge]')->length);
        $this->assertSame(1, $x->query('//*[@data-download-program]')->length, 'reading program gradebooks needs program.monitor only');
        $this->assertSame(0, $this->xpath($this->open('tab=mine'))->query('//*[@data-download-link]')->length);

        $this->clear('followups.manage');

        $x = $this->xpath($this->open('tab=program'));
        $this->assertSame(0, $x->query('//*[@data-nudge]')->length);
        $this->assertSame(0, $x->query('//*[@data-nudge-dialog]')->length, 'no Nudge, no nudge dialog');
        $this->assertSame(1, $x->query('//*[@data-download-program]')->length);
    }

    public function testTheAtRiskColumnSaysWhatItCounts(): void
    {
        $this->publish($this->section('IT101', 'A', $this->instructor()));

        $x      = $this->xpath($this->open('tab=program'));
        $header = $x->query("//th[@data-column='at_risk']")->item(0);

        $this->assertSame('Students failing or with an Incomplete', $x->query('.//a', $header)->item(0)->getAttribute('title'));
        $this->assertStringContainsString('Students failing or with an Incomplete', $this->text($x->query(".//*[contains(concat(' ', @class, ' '), ' sr-only ')]", $header)->item(0)));
    }

    public function testAFinalizedSectionHasNoNudgeAndNoEditAsChairperson(): void
    {
        $finalized = $this->section('IT101', 'A', $this->instructor());
        $this->publish($finalized);
        $this->finalize($finalized);
        $open = $this->section('IT102', 'A', $this->instructor());
        $this->publish($open);

        $x = $this->xpath($this->open('tab=program&section=' . $finalized));
        $this->assertTrue($x->query('//button[@data-nudge]')->item(0)->hasAttribute('hidden'), 'nothing to nudge about');
        $this->assertTrue($x->query('//button[@data-edit-as-chair]')->item(0)->hasAttribute('hidden'), 'edits are refused there');
        $this->assertSame('finalized', $x->query('//*[@data-program-actions]')->item(0)->getAttribute('data-state'));

        $x = $this->xpath($this->open('tab=program&section=' . $open));
        $this->assertFalse($x->query('//button[@data-nudge]')->item(0)->hasAttribute('hidden'));
        $this->assertFalse($x->query('//button[@data-edit-as-chair]')->item(0)->hasAttribute('hidden'));
    }

    public function testTheActionBarCarriesTheNudgeDataForASectionOnAnotherPage(): void
    {
        $ids = [];

        for ($i = 1; $i <= 26; $i++) {
            $ids[$i] = $this->section(sprintf('IT%03d', $i));
            $this->publish($ids[$i]);
        }

        // The last section, on page 2, is taught by the chairperson and another instructor, with work waiting.
        $teacher = $this->instructor('Mara', 'Santos');
        CourseFactory::assignInstructor($ids[26], $teacher);
        CourseFactory::assignInstructor($ids[26], $this->chair);
        $this->waiting($ids[26], 2, $teacher, 3);

        $x = $this->xpath($this->open('tab=program&section=' . $ids[26]));
        $this->assertSame(0, $x->query("//a[@data-program-row][@data-section-id='{$ids[26]}']")->length, 'not on page 1');
        $this->assertSame(0, $x->query('//a[@data-program-row][@aria-current]')->length);

        $bar = $x->query('//*[@data-program-actions]')->item(0);
        $this->assertSame((string) $ids[26], $bar->getAttribute('data-section-id'));
        $this->assertSame('IT026-A', $bar->getAttribute('data-label'));
        $this->assertSame('waiting', $bar->getAttribute('data-state'));
        $this->assertSame('2', $bar->getAttribute('data-waiting'));
        $this->assertSame('3', $bar->getAttribute('data-oldest-days'));
        $this->assertSame('2026-12-20', $bar->getAttribute('data-term-end'));
        $this->assertSame([['id' => (int) $teacher->id, 'name' => 'Mara Santos']], json_decode($bar->getAttribute('data-instructors'), true), 'the chairperson is not their own recipient');
        $this->assertFalse($x->query('//button[@data-nudge]')->item(0)->hasAttribute('hidden'));
    }

    public function testTheChairpersonIsNotOfferedAsTheRecipientOfTheirOwnSection(): void
    {
        $mine = $this->section('IT101', 'A', $this->chair);
        $this->publish($mine);

        $x = $this->xpath($this->open('tab=program&section=' . $mine));
        $this->assertSame('[]', $this->row($x, $mine)->getAttribute('data-instructors'));
        $this->assertSame('[]', $x->query('//*[@data-program-actions]')->item(0)->getAttribute('data-instructors'));
        $this->assertStringContainsString('Cara', $this->cell($x, $mine, 'instructor'), 'the column still names them');
    }

    public function testTheChairpersonAreaStillHasNoFinalizeRoute(): void
    {
        $collection = Services::routes(false);
        $collection->loadRoutes();

        $finalizing = array_filter(array_keys($collection->getRoutes('POST')), static fn (string $route): bool => str_starts_with($route, 'chairperson/') && str_ends_with($route, 'grades/finalization'));
        $this->assertSame([], array_values($finalizing), 'finalizing is the instructor\'s');
        $this->assertNotContains('chairperson/grades/finalize-ready', array_keys($collection->getRoutes('POST')));

        $offering = $this->section('IT101', 'A', $this->chair);
        $this->publish($offering);
        $this->loggedInAs($this->chair);

        try {
            $result = $this->post("chairperson/api/courses/{$offering}/grades/finalization");
            $result->assertStatus(404);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, db_connect()->table('grade_finalizations')->where('course_offering_id', $offering)->countAllResults());
    }

    public function testOpeningThePageWritesNothing(): void
    {
        $unschemed = $this->section('IT101', 'A', $this->chair);
        $this->waiting($unschemed, 2, $this->chair);
        $this->publish($this->section('IT102', 'A', $this->instructor()));
        $bare = $this->section('IT103', 'A', $this->instructor());

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

        foreach (['', 'tab=mine&sub=to-grade', 'tab=mine&sub=at-risk', 'tab=mine&section=' . $unschemed, 'tab=program', 'tab=program&section=' . $bare, 'tab=program&sub=at-risk', 'tab=program&q=IT&sort=status&dir=desc&nf=1'] as $query) {
            $this->open($query);
        }

        $this->assertSame($before, $counts());
        $this->assertSame(0, $db->table('grading_schemes')->whereIn('course_offering_id', [$unschemed, $bare])->countAllResults(), 'no scheme is created by looking');
    }
}

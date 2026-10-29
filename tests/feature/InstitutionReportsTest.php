<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The institution's reports: access, the numbers and what scopes them
 * (term, department, program, never another institution), the course opened
 * from the report, the CSV of each report (scoped, safe, and logged) and the
 * empty state.
 *
 * @internal
 */
final class InstitutionReportsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    /** @var array{department: int, program: int} */
    private array $it;

    /** @var array{department: int, program: int} */
    private array $cs;

    private int $itOffering;

    private int $csOffering;

    private int $term;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $db = db_connect();

        $this->institution = PeopleFactory::institution('RPTA');
        $this->other       = PeopleFactory::institution('RPTB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@rpt.example', $this->institution);

        $this->it = $this->unit($this->institution, 'IT');
        $this->cs = $this->unit($this->institution, 'CS');

        $this->term = $this->term($this->institution);

        $this->itOffering = $this->offering($this->it['program'], $this->term, 'IT101');
        $this->csOffering = $this->offering($this->cs['program'], $this->term, 'CS101');

        $this->populate($this->itOffering, 2, 2);
        $this->populate($this->csOffering, 1, 0);

        $db->table('audit_logs')->emptyTable();

        $this->signIn($this->admin);
    }

    /** @return array{department: int, program: int} */
    private function unit(int $institution, string $tag): array
    {
        $db = db_connect();

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => "D{$tag}{$institution}", 'name' => "College of {$tag}"]);
        $department = (int) $db->insertID();

        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => "S{$tag}{$institution}", 'name' => "Dept {$tag}"]);
        $sub = (int) $db->insertID();

        $db->table('programs')->insert(['sub_department_id' => $sub, 'code' => "P{$tag}{$institution}", 'name' => "Program {$tag}"]);

        return ['department' => $department, 'program' => (int) $db->insertID()];
    }

    private function term(int $institution, string $name = 'First Term'): int
    {
        db_connect()->table('academic_terms')->insert(['institution_id' => $institution, 'name' => $name, 'academic_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2026-12-20', 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    private function offering(int $program, int $term, string $code, string $title = ''): int
    {
        $db = db_connect();

        $db->table('courses')->insert(['program_id' => $program, 'course_code' => $code, 'course_title' => $title !== '' ? $title : "Course {$code}", 'units' => 3]);
        $course = (int) $db->insertID();

        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A', 'status' => 'active']);

        return (int) $db->insertID();
    }

    private function populate(int $offering, int $students, int $itemsDone): void
    {
        $module = CourseFactory::module($offering);
        $unit   = CourseFactory::unit($module);
        $items  = CourseFactory::items($unit, [['title' => 'One'], ['title' => 'Two']]);

        $scheme = GradeFactory::scheme($offering);
        $grade  = GradeFactory::item($scheme, null, 'Quiz', 10);

        for ($i = 0; $i < $students; $i++) {
            $student = CourseFactory::user('student');
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'Stu', 'last_name' => 'Dent' . $student->id]);
            CourseFactory::enroll($offering, $student);

            for ($k = 0; $k < $itemsDone; $k++) {
                CourseFactory::progress($items[$k], $student);
            }

            GradeFactory::score($offering, $student, $grade, 8.0 + $i);
        }
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** The overview ('') or one of its sub pages ('/courses', '/at-risk'). */
    private function page(string $query = '', string $path = ''): string
    {
        return $this->step('GET', 'institution/reports' . $path . $query)->response()->getBody();
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
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@rpt.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/reports')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', 'institution/reports/courses')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', 'institution/reports/at-risk')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', 'institution/reports/export/courses')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', "institution/reports/offerings/{$this->itOffering}")->response()->getStatusCode());
    }

    public function testTheOverviewShowsTheNumbersAndLeadsToTheSubPages(): void
    {
        $body = $this->page();

        $this->assertStringContainsString('data-report-totals', $body);
        $this->assertStringContainsString('data-report-distribution', $body);
        $this->assertStringContainsString('data-report-link="courses"', $body);
        $this->assertStringContainsString('data-report-link="at-risk"', $body);
        $this->assertMatchesRegularExpression('/data-export="courses"/', $body);

        // The tables live on their own pages now.
        $this->assertStringNotContainsString('IT101', $body);
        $this->assertStringNotContainsString('data-report-at-risk', $body);
    }

    public function testTheCoursesPageComparesTheInstitutionsCourses(): void
    {
        $body = $this->page('', '/courses');

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('CS101', $body);
        $this->assertStringNotContainsString('data-report-totals', $body);
        $this->assertStringNotContainsString('data-report-at-risk', $body);
    }

    public function testTheStudentsAtRiskPageListsThem(): void
    {
        $body = $this->page('', '/at-risk');

        $this->assertStringContainsString('data-report-at-risk', $body);
        $this->assertStringContainsString('CS101', $body, 'nobody there has done anything');
        $this->assertStringNotContainsString('data-report-totals', $body);
    }

    public function testEachPageHasTheTabsAndTheFiltersTravelInThem(): void
    {
        $department = $this->it['department'];

        foreach (['', '/courses', '/at-risk'] as $path) {
            $body = html_entity_decode($this->page("?department={$department}", $path));

            $this->assertStringContainsString('data-tabs', $body, $path);
            $this->assertMatchesRegularExpression('#href="[^"]*institution/reports\?department=' . $department . '"#', $body, $path);
            $this->assertMatchesRegularExpression('#href="[^"]*institution/reports/courses\?department=' . $department . '"#', $body, $path);
            $this->assertMatchesRegularExpression('#href="[^"]*institution/reports/at-risk\?department=' . $department . '"#', $body, $path);
        }
    }

    public function testAnotherInstitutionsCoursesAreNeverShown(): void
    {
        $theirs = $this->unit($this->other, 'ZZ');
        $this->offering($theirs['program'], $this->term($this->other), 'ZZ999');

        $this->assertStringNotContainsString('ZZ999', $this->page('', '/courses'));
    }

    public function testADepartmentAndAProgramNarrowTheReport(): void
    {
        $it = $this->page("?department={$this->it['department']}", '/courses');
        $this->assertStringContainsString('IT101', $it);
        $this->assertStringNotContainsString('CS101', $it);

        $cs = $this->page("?program={$this->cs['program']}", '/courses');
        $this->assertStringContainsString('CS101', $cs);
        $this->assertStringNotContainsString('IT101', $cs);
    }

    public function testATermNarrowsTheReport(): void
    {
        $second = $this->term($this->institution, 'Second Term');
        $this->offering($this->it['program'], $second, 'IT202');

        $body = $this->page("?term={$second}", '/courses');

        $this->assertStringContainsString('IT202', $body);
        $this->assertStringNotContainsString('IT101', $body);
    }

    public function testAnotherInstitutionsDepartmentOrProgramIsNotFound(): void
    {
        $theirs = $this->unit($this->other, 'ZZ');

        foreach (["?department={$theirs['department']}", "?program={$theirs['program']}"] as $query) {
            try {
                $this->call('GET', 'institution/reports' . $query);
                $this->fail("{$query} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheComparisonIsSortableAndSaysWhichWay(): void
    {
        $body = $this->page('?sort=enrolled&dir=desc', '/courses');

        $this->assertStringContainsString('aria-sort="descending"', $body);
        $this->assertLessThan(strpos($body, 'CS101'), strpos($body, 'IT101'), 'two students come before one');
    }

    public function testACourseCanBeOpenedFromTheReport(): void
    {
        $body = $this->step('GET', "institution/reports/offerings/{$this->itOffering}")->response()->getBody();

        $this->assertStringContainsString('IT101', $body);
        $this->assertStringContainsString('data-report-totals', $body);
        $this->assertStringContainsString('data-report-activity', $body);
        $this->assertStringContainsString('Back to reports', $body);
    }

    public function testAForeignCourseIsNotFound(): void
    {
        $theirs = $this->offering($this->unit($this->other, 'ZZ')['program'], $this->term($this->other), 'ZZ999');

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('GET', "institution/reports/offerings/{$theirs}");
    }

    public function testEachExportIsCsvOfTheSameScopeAndIsLogged(): void
    {
        foreach (array_keys(\App\Services\Institutions\InstitutionReports::EXPORTS) as $kind) {
            $response = $this->step('GET', "institution/reports/export/{$kind}")->response();

            $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'), $kind);
            $this->assertStringContainsString("report-{$kind}-", $response->getHeaderLine('Content-Disposition'), $kind);
        }

        $this->assertSame(4, db_connect()->table('audit_logs')->where('action', 'institution.report_exported')->countAllResults());
    }

    public function testTheCourseCsvHoldsOnlyTheScopedCourses(): void
    {
        $all = $this->step('GET', 'institution/reports/export/courses')->response()->getBody();
        $this->assertStringContainsString('IT101', $all);
        $this->assertStringContainsString('CS101', $all);

        $it = $this->step('GET', "institution/reports/export/courses?department={$this->it['department']}")->response()->getBody();
        $this->assertStringContainsString('IT101', $it);
        $this->assertStringNotContainsString('CS101', $it);
    }

    public function testTheAtRiskCsvListsTheStudentsAndTheReason(): void
    {
        $body = $this->step('GET', 'institution/reports/export/at-risk')->response()->getBody();

        $this->assertStringContainsString('Student,Course,Section,Why', $body);
        $this->assertStringContainsString('CS101', $body, 'nobody there has done anything');
    }

    public function testATitleThatLooksLikeAFormulaIsDefusedInTheCsv(): void
    {
        $this->offering($this->it['program'], $this->term, 'IT303', '=HYPERLINK("http://evil.example","x")');

        $body = $this->step('GET', 'institution/reports/export/courses')->response()->getBody();

        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
    }

    public function testAnUnknownExportIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->call('GET', 'institution/reports/export/everything');
    }

    public function testTheInstitutionsThresholdsDecideWhoNeedsAttention(): void
    {
        // Nobody in IT101 has been inactive for long; with a 1-day rule they all are.
        $before = substr_count($this->step('GET', 'institution/reports/export/at-risk')->response()->getBody(), 'IT101');

        (new \App\Services\Institutions\AcademicDefaults())->save($this->institution, ['inactive_days' => '1'], $this->admin);
        db_connect()->table('learning_completions')->update(['completed_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);

        $after = substr_count($this->step('GET', 'institution/reports/export/at-risk')->response()->getBody(), 'IT101');

        $this->assertGreaterThan($before, $after);
    }

    public function testAnInstitutionWithNothingYetSaysSo(): void
    {
        $empty = PeopleFactory::institution('RPTC');
        $this->signIn(PeopleFactory::person('institutional_admin', 'Eve', 'Empty', 'eve@rpt.example', $empty));

        $body = $this->page();

        $this->assertStringContainsString('No courses to report on yet', $body);
        $this->assertStringNotContainsString('data-report-totals', $body);
    }

    public function testTheReportsTileLeadsHere(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/reports"[^>]*data-app-tile="reports"/s', $dashboard));
    }
}

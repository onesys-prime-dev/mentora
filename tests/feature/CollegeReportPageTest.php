<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's college report taken out of the app: each CSV and the print page, their headers and
 * contents, that each is recorded, that the links on the pages carry the filters on screen, and that no
 * other role, college or missing assignment gets anything. A read-only institution can still export.
 *
 * @internal
 */
final class CollegeReportPageTest extends RouteTestCase
{
    private int $institution;
    private int $college;
    private int $departmentA;
    private int $departmentB;
    private int $term;
    private User $dean;
    private User $chair;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::college('R');
        $this->institution = $home['institution'];
        $this->college  = $home['college'];
        $this->departmentA        = DeanFactory::department($this->college, 'A');
        $this->departmentB        = DeanFactory::department($this->college, 'B');
        $this->term        = DeanFactory::term($this->institution);
        $this->dean        = DeanFactory::dean($this->institution, $this->college);
        $this->chair       = DeanFactory::chair($this->departmentA, 'Maria');
        $this->teacher     = PeopleFactory::person('instructor', 'Tess', 'Tan', null, $this->institution);

        $it = DeanFactory::offering(DeanFactory::program($this->departmentA, 'A'), $this->term, 'IT101', 'A', 'active', 'Intro to Computing');
        $lw = DeanFactory::offering(DeanFactory::program($this->departmentB, 'B'), $this->term, 'LW100', 'A', 'active', 'Intro to Law');
        CourseFactory::assignInstructor($it, $this->teacher);
        CourseFactory::assignInstructor($lw, PeopleFactory::person('instructor', 'Leo', 'Law', null, $this->institution));
        CourseFactory::enroll($it, PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution));

        $other = DeanFactory::college('N', $this->institution);
        $nx    = DeanFactory::offering(DeanFactory::program(DeanFactory::department($other['college'], 'N'), 'N'), $this->term, 'NX100', 'A', 'active', 'Foreign Course');
        CourseFactory::assignInstructor($nx, PeopleFactory::person('instructor', 'Zed', 'Zee', null, $this->institution));
    }

    private function fetch(string $uri, ?User $as = null): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($as ?? $this->dean);

        return $this->call('get', $uri);
    }

    private function page(string $uri): string
    {
        $response = $this->fetch($uri)->response();

        $this->assertSame(200, $response->getStatusCode(), $uri);

        return html_entity_decode($response->getBody());
    }

    /** @return list<array<string, mixed>> */
    private function audits(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getResultArray();
    }

    public function testEachPartDownloadsAsACsvWithAByteOrderMarkAndANamedFile(): void
    {
        foreach (['monitor', 'teaching-load', 'signoff'] as $section) {
            $response = $this->fetch("dean/reports/export/{$section}")->response();

            $this->assertSame(200, $response->getStatusCode(), $section);
            $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'), $section);
            $this->assertSame('attachment; filename="college-r-' . $section . '-' . date('Y-m-d') . '.csv"', $response->getHeaderLine('Content-Disposition'), $section);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getBody(), $section);
        }
    }

    public function testTheMonitorCsvHoldsTheCollegeAndNothingFromAnotherOne(): void
    {
        $body = $this->fetch('dean/reports/export/monitor')->response()->getBody();

        $this->assertStringContainsString('Department,Department,Courses', $body);
        $this->assertStringContainsString('College total', $body);
        $this->assertStringContainsString('Department A', $body);
        $this->assertStringContainsString('Department B', $body);
        $this->assertStringNotContainsString('NX100', $body);
    }

    public function testTheTeachingLoadAndSignOffCsvsHoldTheirPartsOnly(): void
    {
        $load = $this->fetch('dean/reports/export/teaching-load')->response()->getBody();
        $sign = $this->fetch('dean/reports/export/signoff')->response()->getBody();

        $this->assertStringContainsString('Tess Tan', $load);
        $this->assertStringContainsString('Leo Law', $load);
        $this->assertStringNotContainsString('Zed', $load);
        $this->assertStringContainsString('Why blocked', $sign);
        $this->assertStringContainsString('IT101', $sign);
        $this->assertStringNotContainsString('NX100', $sign);
    }

    public function testTheFiltersInTheAddressNarrowTheDownload(): void
    {
        $body = $this->fetch('dean/reports/export/teaching-load?department=' . $this->departmentB)->response()->getBody();

        $this->assertStringContainsString('Leo Law', $body);
        $this->assertStringNotContainsString('Tess Tan', $body);
    }

    public function testAnUnknownPartIsNotFound(): void
    {
        $this->loggedInAs($this->dean);

        foreach (['people', 'monitor.csv', '..'] as $section) {
            try {
                $status = $this->call('get', "dean/reports/export/{$section}")->response()->getStatusCode();
                $this->fail("{$section} must be a 404 (got {$status})");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $this->audits('college_report.exported'));
    }

    public function testEveryDownloadIsRecordedWithTheActingRoleThePartTheRowsAndTheFilters(): void
    {
        $this->fetch('dean/reports/export/teaching-load?department=' . $this->departmentA);

        $audit = $this->audits('college_report.exported');

        $this->assertCount(1, $audit);
        $this->assertSame('dean', $audit[0]['acting_role']);
        $this->assertSame('college', $audit[0]['target_type']);
        $this->assertSame((string) $this->college, $audit[0]['target_id']);

        $new = json_decode((string) $audit[0]['new_values'], true);

        $this->assertSame('teaching-load', $new['section']);
        $this->assertSame(1, $new['rows']);
        $this->assertSame((string) $this->departmentA, (string) $new['filters']['department']);
    }

    public function testThePrintPageHoldsAllThreePartsAndIsRecorded(): void
    {
        $body = $this->page('dean/reports/print');

        $this->assertStringContainsString('College R: college report', $body);
        $this->assertStringContainsString('Print or save as PDF', $body);

        foreach (['monitor', 'teaching-load', 'signoff'] as $part) {
            $this->assertStringContainsString('data-part="' . $part . '"', $body, $part);
        }

        $this->assertStringContainsString('Tess Tan', $body);
        $this->assertStringContainsString('Department A', $body);
        $this->assertStringNotContainsString('Zed', $body);
        $this->assertStringNotContainsString('NX100', $body);
        $this->assertCount(1, $this->audits('college_report.printed'));
        $this->assertSame('dean', $this->audits('college_report.printed')[0]['acting_role']);
    }

    public function testThePrintPageSaysWhatItIsFilteredBy(): void
    {
        $body = $this->page('dean/reports/print?term=' . $this->term . '&department=' . $this->departmentB);

        $this->assertStringContainsString('Term: First (2026-2027)', $body);
        $this->assertStringContainsString('Department: Department B', $body);
        $this->assertStringNotContainsString('Tess Tan', $body);
    }

    public function testTheThreePagesLinkTheirExportWithTheFiltersOnScreen(): void
    {
        $monitor = $this->page('dean/monitor-college?department=' . $this->departmentA . '&compare=program');

        $this->assertMatchesRegularExpression('#data-export="csv" href="[^"]*dean/reports/export/monitor\?[^"]*department=' . $this->departmentA . '[^"]*compare=program#', $monitor);
        $this->assertMatchesRegularExpression('#data-export="print" href="[^"]*dean/reports/print\?[^"]*department=' . $this->departmentA . '#', $monitor);
        $this->assertStringContainsString('Download CSV', $monitor);
        $this->assertStringContainsString('Print view', $monitor);

        $load = $this->page('dean/teaching-load?department=' . $this->departmentA . '&sort=name');

        $this->assertMatchesRegularExpression('#data-export="csv" href="[^"]*dean/reports/export/teaching-load\?[^"]*term=' . $this->term . '#', $load);
        $this->assertMatchesRegularExpression('#data-export="csv" href="[^"]*export/teaching-load\?[^"]*sort=name#', $load);

        $sign = $this->page('dean/signoff');

        $this->assertMatchesRegularExpression('#data-export="csv" href="[^"]*dean/reports/export/signoff\?term=' . $this->term . '#', $sign);
    }

    public function testNobodyButADeanGetsAnythingAndNothingIsRecorded(): void
    {
        foreach ([$this->chair, $this->teacher, PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution)] as $user) {
            foreach (['dean/reports/export/monitor', 'dean/reports/print'] as $uri) {
                $this->assertNotSame(200, $this->fetch($uri, $user)->response()->getStatusCode(), $uri);
            }
        }

        $this->assertSame([], $this->audits('college_report.exported'));
        $this->assertSame([], $this->audits('college_report.printed'));
    }

    public function testADeanWithoutACollegeHasNothingToExport(): void
    {
        $stray = PeopleFactory::person('dean', 'No', 'College', null, $this->institution);
        $this->loggedInAs($stray);

        foreach (['dean/reports/export/monitor', 'dean/reports/print'] as $uri) {
            try {
                $status = $this->call('get', $uri)->response()->getStatusCode();
                $this->fail("{$uri} must be a 404 (got {$status})");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $this->audits('college_report.exported'));
    }

    public function testAReadOnlyInstitutionsDeanCanStillExport(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(200, $this->fetch('dean/reports/export/monitor')->response()->getStatusCode());
        $this->assertStringContainsString('data-part="monitor"', $this->page('dean/reports/print'));
    }
}

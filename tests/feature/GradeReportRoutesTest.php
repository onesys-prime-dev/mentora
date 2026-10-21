<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\RouteTestCase;

/**
 * The grade reports over HTTP: who may ask for the class gradebook, the class
 * summary and a student's report, what comes back, the plain message when
 * there is nothing to give, and that every export is in the audit log.
 *
 * @internal
 */
final class GradeReportRoutesTest extends RouteTestCase
{
    private int $mine;

    private int $theirs;

    private User $teacher;

    private User $otherTeacher;

    private User $ann;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->mine = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');

        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $this->teacher);

        $this->otherTeacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->theirs, $this->otherTeacher);

        $this->ann = CourseFactory::user('student');
        $this->ben = CourseFactory::user('student');

        foreach ([$this->ann, $this->ben] as $student) {
            db_connect()->table('user_profiles')->insert(['user_id' => $student->id, 'first_name' => 'S', 'last_name' => 'T' . $student->id, 'student_number' => '2026-' . $student->id]);
            CourseFactory::enroll($this->mine, $student);
        }

        $scheme = GradeFactory::scheme($this->mine, 75.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
        $exams = GradeFactory::category($scheme, 'Exams', 100);
        $exam = GradeFactory::item($scheme, $exams, 'Midterm', 100);

        GradeFactory::score($this->mine, $this->ann, $exam, 88);
        GradeFactory::score($this->mine, $this->ben, $exam, 60);

        (new GradingEngineService())->recalculateOffering($this->mine);
    }

    private function as(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    /** @return array{0: int, 1: string, 2: string} status, body, content disposition */
    private function fetch(string $uri): array
    {
        try {
            $response = $this->step('GET', $uri)->response();

            // A file download sets its headers just before it is sent; do that here to read them.
            if ($response instanceof CodeIgniter\HTTP\DownloadResponse) {
                $response->buildHeaders();
            }

            return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Content-Disposition')];
        } catch (PageNotFoundException) {
            return [404, '', ''];
        }
    }

    private function teacherUrl(string $path, ?int $offering = null): string
    {
        return 'instructor/api/courses/' . ($offering ?? $this->mine) . '/grades/report/' . $path;
    }

    private function audit(string $kind): int
    {
        return db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->like('new_values', '"kind":"' . $kind . '"')->countAllResults();
    }

    public function testTheInstructorDownloadsTheClassGradebookAsCsvWithTheStudentsInIt(): void
    {
        $this->as($this->teacher);

        [$status, $body, $disposition] = $this->fetch($this->teacherUrl('class?format=csv'));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('.csv', $disposition);
        $this->assertSame(1, $this->audit('class_gradebook'));
    }

    public function testTheClassGradebookAlsoComesAsAnExcelFile(): void
    {
        $this->as($this->teacher);

        [$status, , $disposition] = $this->fetch($this->teacherUrl('class'));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    public function testTheClassSummaryIsAPdfOrAPrintView(): void
    {
        $this->as($this->teacher);

        [$status, $body, $disposition] = $this->fetch($this->teacherUrl('summary'));

        $this->assertSame(200, $status);
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString('.pdf', $disposition);

        [$status, $html] = $this->fetch($this->teacherUrl('summary?format=html'));

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Class summary', $html);
        $this->assertStringContainsString('Print or save as PDF', $html);
        $this->assertStringContainsString('T' . $this->ann->id, $html);
        $this->assertSame(2, $this->audit('class_summary'));
    }

    public function testTheInstructorGetsAnyStudentsReportButNotSomeoneOutsideTheSection(): void
    {
        $this->as($this->teacher);

        [$status, $body] = $this->fetch($this->teacherUrl('student/' . $this->ann->id));

        $this->assertSame(200, $status);
        $this->assertStringStartsWith('%PDF', $body);

        $stranger = CourseFactory::user('student');

        [$status, $json] = $this->fetch($this->teacherUrl('student/' . $stranger->id));

        $this->assertSame(404, $status);
        $this->assertStringContainsString('not in this section', $json);
    }

    public function testASectionWithNoGradingYetSaysSoInsteadOfAFile(): void
    {
        $bare = CourseFactory::offering('C');
        CourseFactory::assignInstructor($bare, $this->teacher);
        $this->as($this->teacher);

        foreach (['class?format=csv', 'summary'] as $path) {
            [$status, $body] = $this->fetch($this->teacherUrl($path, $bare));

            $this->assertSame(404, $status, $path);
            $this->assertStringContainsString('no grading yet', $body);
        }

        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->countAllResults(), 'nothing was exported');
    }

    public function testAnInstructorCannotReportOnASectionTheyDoNotTeach(): void
    {
        $this->as($this->otherTeacher);

        foreach (['class?format=csv', 'summary', 'student/' . $this->ann->id] as $path) {
            [$status] = $this->fetch($this->teacherUrl($path));

            $this->assertSame(404, $status, $path);
        }

        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->countAllResults());
    }

    public function testAStudentCannotAskForTheClassReports(): void
    {
        $this->as($this->ann);

        foreach (['class?format=csv', 'summary', 'student/' . $this->ben->id] as $path) {
            [$status] = $this->fetch('student/api/courses/' . $this->mine . '/grades/report/' . $path);

            $this->assertSame(404, $status, $path);
        }
    }

    public function testAStudentHasNoReportUntilTheGradesAreFinal_ThenOnlyTheirOwn(): void
    {
        $this->as($this->ann);

        [$status, $body] = $this->fetch('student/api/courses/' . $this->mine . '/grades/report/mine');

        $this->assertSame(404, $status);
        $this->assertStringContainsString('finalized', $body);

        (new GradeFinalizationService())->finalize($this->teacher, $this->mine);

        $this->as($this->ann);

        [$status, $pdf, $disposition] = $this->fetch('student/api/courses/' . $this->mine . '/grades/report/mine');

        $this->assertSame(200, $status);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('my-grades', $disposition);

        [, $html] = $this->fetch('student/api/courses/' . $this->mine . '/grades/report/mine?format=html');

        $this->assertStringContainsString('T' . $this->ann->id, $html);
        $this->assertStringNotContainsString('T' . $this->ben->id, $html, 'only their own');
        $this->assertStringNotContainsString('Class average', $html, 'the class average is for the instructor');
    }

    public function testAStudentOfAnotherSectionHasNoReportHere(): void
    {
        (new GradeFinalizationService())->finalize($this->teacher, $this->mine);

        $this->as(CourseFactory::user('student'));

        [$status] = $this->fetch('student/api/courses/' . $this->mine . '/grades/report/mine');

        $this->assertSame(404, $status);
    }
}

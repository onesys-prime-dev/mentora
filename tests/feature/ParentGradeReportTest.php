<?php

declare(strict_types=1);

use App\Services\AI\PdfTextExtractor;
use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The grade report a parent can download: only once the grades are final, as
 * a PDF without the item-by-item scores, audited, and only for their own child.
 *
 * @internal
 */
final class ParentGradeReportTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $child;

    private User $teacher;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = PeopleFactory::institution('PGR');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->offering = CourseFactory::offering('A');
        $module         = CourseFactory::module($this->offering);
        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->child);

        // Quizzes 40% (a quiz) and Exams 60% (a manual "Midterm"), published.
        $scheme   = GradeFactory::scheme($this->offering, 75.0);
        $quizzes  = GradeFactory::category($scheme, 'Quizzes', 40);
        $exams    = GradeFactory::category($scheme, 'Exams', 60, null, 2);
        $quiz     = CourseFactory::assessment($this->offering, $this->teacher, $module);
        db_connect()->table('assessments')->where('id', $quiz)->update(['status' => 'Published', 'title' => 'Gradebook Quiz', 'total_points' => 10]);
        $quizItem = GradeFactory::item($scheme, $quizzes, 'Quiz One', 10, 1, $quiz);
        $midterm  = GradeFactory::item($scheme, $exams, 'Midterm Exam', 100, 2);
        GradeFactory::score($this->offering, $this->child, $quizItem, 9.0);
        GradeFactory::score($this->offering, $this->child, $midterm, 88.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function uri(?int $child = null, ?int $offering = null, string $format = 'pdf'): string
    {
        return '/parent/children/' . ($child ?? $this->child->id) . '/api/courses/' . ($offering ?? $this->offering) . '/grades/report/mine?format=' . $format;
    }

    /** Makes the child's grade final (the record exists once someone has looked at the grades). */
    private function finalize(): void
    {
        (new App\Services\Courses\Grades\GradingEngineService())->recalculateStudent($this->offering, (int) $this->child->id);

        // Finalizing freezes the calculated percentage, which is what a final grade then shows.
        db_connect()->table('gradebook_records')->where(['course_offering_id' => $this->offering, 'student_id' => $this->child->id])
            ->set('final_percentage', 'calculated_percentage', false)
            ->update(['remarks' => 'Passed', 'finalized_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array{int, string, string} */
    private function open(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Content-Type')];
        } catch (PageNotFoundException) {
            return [404, '', ''];
        }
    }

    private function pdfText(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rep') . '.pdf';
        file_put_contents($path, $pdf);

        try {
            return (new PdfTextExtractor())->extract($path)['text'];
        } finally {
            @unlink($path);
        }
    }

    public function testAReportThatIsNotFinalIsRefusedWithAPlainMessage(): void
    {
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->uri());

        $this->assertSame(404, $status);
        $this->assertStringContainsString('final', json_decode($body, true)['message']);
    }

    public function testAFinalReportIsAPdfWithoutTheItemScores(): void
    {
        $this->finalize();
        $this->loggedInAs($this->parent);

        [$status, $body, $type] = $this->open($this->uri());

        $this->assertSame(200, $status);
        $this->assertStringContainsString('application/pdf', $type);
        $this->assertStringStartsWith('%PDF', $body);

        $text = $this->pdfText($body);

        $this->assertStringContainsString('Lee, Ann', $text, 'the report names the student surname first');
        $this->assertStringContainsString('88.8%', $text);
        $this->assertStringContainsString('How the grade is made up', $text);
        $this->assertStringContainsString('Quizzes', $text);
        $this->assertStringContainsString('Exams', $text);

        foreach (['Midterm Exam', 'Quiz One', 'Scores', 'Points'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, $forbidden);
        }
    }

    public function testTheStudentsOwnReportStillHasTheItemScores(): void
    {
        $this->finalize();
        $this->loggedInAs($this->child);

        [$status, $body] = $this->open('/student/api/courses/' . $this->offering . '/grades/report/mine?format=html');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Scores', $body);
        $this->assertStringContainsString('Midterm Exam', $body);
        $this->assertStringContainsString('Points', $body);
    }

    public function testTheDownloadIsAuditedWithTheParentAsActor(): void
    {
        $this->finalize();
        $this->loggedInAs($this->parent);

        $this->open($this->uri());

        $entry = db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->get()->getRowArray();

        $this->assertNotNull($entry);
        $this->assertSame((int) $this->parent->id, (int) $entry['actor_user_id']);
        $this->assertSame('parent', $entry['acting_role']);
        $this->assertSame((string) $this->offering, (string) $entry['target_id']);
        $this->assertSame((int) $this->child->id, (int) json_decode((string) $entry['new_values'], true)['student_id']);
    }

    public function testAChildOrCourseThatIsNotTheirsIs404(): void
    {
        $this->finalize();
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $other    = CourseFactory::offering('B');
        CourseFactory::enroll($other, $stranger);
        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->uri((int) $stranger->id, $other))[0]);
        $this->assertSame(404, $this->open($this->uri(null, $other))[0]);
        $this->assertNull(db_connect()->table('audit_logs')->where('action', 'grades.report_exported')->get()->getRowArray());
    }

    public function testASwitchedOffPortalGivesNoReport(): void
    {
        $this->finalize();
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open($this->uri());

        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('%PDF', $body);
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        $this->finalize();

        foreach (['instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            $this->assertSame(302, $this->open($this->uri())[0], $role);
        }
    }
}

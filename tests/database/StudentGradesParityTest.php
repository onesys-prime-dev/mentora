<?php

declare(strict_types=1);

use App\Services\Courses\GradeService;
use App\Services\Courses\Grades\GradingEngineService;
use App\Services\Grades\StudentGradesOverview;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * For the same student and course, the global Grades page and the course's Grades tab show the same
 * percentage, band and tag (spec §3). Each case is set up through the grading engine, the way the app
 * writes it.
 *
 * @internal
 */
final class StudentGradesParityTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    /** @return array<string, array{0: string}> */
    public static function cases(): array
    {
        return [
            'in progress'                       => ['in_progress'],
            'finalized, final band ≠ calculated' => ['finalized'],
            'overridden'                         => ['overridden'],
            'INC set'                            => ['inc'],
        ];
    }

    /** @dataProvider cases */
    public function testTheGlobalPageAndTheCourseTabAgree(string $case): void
    {
        $student    = CourseFactory::user('student');
        $instructor = CourseFactory::user('instructor');
        $offering   = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);

        $scheme = GradeFactory::scheme($offering);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $quizzes = GradeFactory::category($scheme, 'Quizzes', 100);
        $first   = GradeFactory::item($scheme, $quizzes, 'Quiz 1', 100, 1);
        GradeFactory::item($scheme, $quizzes, 'Quiz 2', 100, 2); // never graded: left out live, zero once finalized
        GradeFactory::score($offering, $student, $first, $case === 'inc' ? 70.0 : 95.0);

        $engine = new GradingEngineService();
        $engine->recalculateStudent($offering, (int) $student->id);

        match ($case) {
            'finalized'  => $this->assertTrue($engine->freezeStudent($engine->context($offering, [(int) $student->id]), (int) $student->id, (int) $instructor->id, date('Y-m-d H:i:s'))),
            'overridden' => $this->assertTrue($engine->overrideFinalGrade($instructor, $offering, (int) $student->id, 82.0, 'Regraded the project')['success']),
            'inc'        => $this->assertTrue($engine->markIncomplete($instructor, $offering, (int) $student->id, 'Missing final project', '2026-12-20')['success']),
            default      => null,
        };

        $record = db_connect()->table('gradebook_records')->where('course_offering_id', $offering)->where('student_id', $student->id)->get()->getRowArray();

        if ($case === 'finalized') {
            $this->assertNotSame($record['calculated_grade_scale_item_id'], $record['final_grade_scale_item_id'], 'the case needs a final band unlike the calculated one');
        }

        $row     = $this->row($student, $offering);
        $summary = (new GradeService())->buildStudentGrades(
            $student,
            db_connect()->table('course_offerings')->where('id', $offering)->get()->getRowArray(),
        )['summary'];

        $this->assertNotNull($summary['overall_percentage']);
        $this->assertSame((float) $summary['overall_percentage'], (float) $row['percentage'], 'percentage');
        $this->assertSame(round((float) $summary['overall_percentage'], 2), round((float) $row['percentage'], 2), 'percentage, as rounded');
        $this->assertSame($summary['grade_label'], $row['band'], 'band');
        $this->assertSame($summary['tag'], $row['tag'], 'tag');
    }

    /** @return array<string, mixed> */
    private function row(User $student, int $offering): array
    {
        $overview = new StudentGradesOverview();
        $termId   = (int) db_connect()->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
        $term     = array_column($overview->terms($student), null, 'id')[$termId];
        $rows     = array_column($overview->courses($student, $term), null, 'offering_id');

        $this->assertArrayHasKey($offering, $rows);

        return $rows[$offering];
    }
}

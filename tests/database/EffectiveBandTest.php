<?php

declare(strict_types=1);

use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\GradeService;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The band a student's grade sits in, picked the same way as the percentage:
 * the override, else the frozen final band once finalized, else the calculated.
 *
 * @internal
 */
final class EffectiveBandTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function record(array $overrides = []): array
    {
        return $overrides + [
            'is_override'                    => 0,
            'overridden_grade_scale_item_id' => 11,
            'finalized_at'                   => null,
            'final_grade_scale_item_id'      => 22,
            'calculated_grade_scale_item_id' => 33,
        ];
    }

    public function testOverrideWins(): void
    {
        $record = $this->record(['is_override' => 1, 'finalized_at' => '2026-10-01 00:00:00']);

        $this->assertSame(11, GradebookRecordModel::effectiveBandId($record));
    }

    public function testAFinalizedRecordUsesItsFinalBand(): void
    {
        $this->assertSame(22, GradebookRecordModel::effectiveBandId($this->record(['finalized_at' => '2026-10-01 00:00:00'])));
    }

    public function testOtherwiseTheCalculatedBand(): void
    {
        $this->assertSame(33, GradebookRecordModel::effectiveBandId($this->record()));
    }

    public function testNoRecordHasNoBand(): void
    {
        $this->assertNull(GradebookRecordModel::effectiveBandId(null));
    }

    public function testTheCourseTabShowsTheFinalBandOnceFinalized(): void
    {
        $student  = CourseFactory::user('student');
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);

        $scheme = GradeFactory::scheme($offering);
        $db     = db_connect();
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published']);
        $scaleId = (int) $db->table('grading_schemes')->where('id', $scheme)->get()->getRow()->grade_scale_id;

        $band = static fn (string $name): int => (int) db_connect()->table('grade_scale_items')
            ->where('grade_scale_id', $scaleId)->where('name', $name)->get()->getRow()->id;

        $db->table('gradebook_records')->insert([
            'course_offering_id'             => $offering,
            'student_id'                     => $student->id,
            'grading_scheme_id'              => $scheme,
            'calculated_percentage'          => 95,
            'calculated_grade_scale_item_id' => $band('A'),
            'final_percentage'               => 82,
            'final_grade_scale_item_id'      => $band('B'),
            'finalized_at'                   => '2026-10-01 00:00:00',
            'is_override'                    => 0,
        ]);

        $offeringRow = $db->table('course_offerings')->where('id', $offering)->get()->getRowArray();

        $grades = (new GradeService())->buildStudentGrades($student, $offeringRow);

        $this->assertSame('B', $grades['summary']['grade_label']);
    }
}

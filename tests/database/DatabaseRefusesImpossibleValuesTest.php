<?php

declare(strict_types=1);

use App\Database\Migrations\DatabaseRefusesImpossibleValues;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

require_once APPPATH . 'Database/Migrations/2026-11-20-000003_DatabaseRefusesImpossibleValues.php';

/**
 * The row rules hold in the database, not only in the screens that write
 * the rows (QA MISS-DB-01, TC-SA-55): a percentage is 0 to 100, units are
 * not negative, a section holds at least one student, a term ends after it
 * starts, and an assessment has one settings row.
 *
 * @internal
 */
final class DatabaseRefusesImpossibleValuesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
    }

    /** @param array<string, mixed> $values */
    private function refuses(string $table, array $values, int $id): void
    {
        try {
            $changed = db_connect()->table($table)->where('id', $id)->update($values);
        } catch (DatabaseException) {
            $changed = false;
        }

        $this->assertFalse($changed, $table . ' took ' . json_encode($values));
    }

    /** @param array<string, mixed> $values */
    private function takes(string $table, array $values, int $id): void
    {
        $this->assertTrue(db_connect()->table($table)->where('id', $id)->update($values), $table . ' refused ' . json_encode($values));
    }

    private function row(string $table, string $column, int|string $value): int
    {
        return (int) db_connect()->table($table)->where($column, $value)->get()->getRowArray()['id'];
    }

    public function testATermEndsAfterItStarts(): void
    {
        $term = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['term_id'];

        $this->refuses('academic_terms', ['end_date' => '2026-07-31'], $term);
        $this->refuses('academic_terms', ['end_date' => '2026-08-01'], $term);
        $this->takes('academic_terms', ['end_date' => '2026-08-02'], $term);
    }

    public function testUnitsAreNotNegative(): void
    {
        $course = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];

        $this->refuses('courses', ['units' => -1], $course);
        $this->takes('courses', ['units' => 0], $course);
    }

    public function testASectionHoldsAtLeastOneStudent(): void
    {
        $this->refuses('course_offerings', ['capacity' => 0], $this->offering);
        $this->refuses('course_offerings', ['capacity' => -5], $this->offering);
        $this->takes('course_offerings', ['capacity' => 1], $this->offering);
    }

    public function testPercentagesAreZeroToAHundred(): void
    {
        $instructor = CourseFactory::user('instructor');
        $assessment = CourseFactory::assessment($this->offering, $instructor);
        $scheme     = GradeFactory::scheme($this->offering);

        $this->refuses('assessments', ['passing_percentage' => 101], $assessment);
        $this->refuses('assessments', ['passing_percentage' => -1], $assessment);
        $this->takes('assessments', ['passing_percentage' => 100], $assessment);
        $this->takes('assessments', ['passing_percentage' => null], $assessment);

        $this->refuses('grading_schemes', ['passing_percentage' => 100.5], $scheme);
        $this->takes('grading_schemes', ['passing_percentage' => 0], $scheme);

        $module = CourseFactory::module($this->offering);
        $item   = CourseFactory::items(CourseFactory::unit($module), [['type' => 'reading']])[0];
        CourseFactory::progress($item, CourseFactory::user(), 'in_progress', 40);
        $completion = $this->row('learning_completions', 'learning_item_id', $item);

        $this->refuses('learning_completions', ['progress_percent' => 101], $completion);
        $this->takes('learning_completions', ['progress_percent' => 100], $completion);
    }

    public function testAnInstitutionsPassingMarkIsAPercentage(): void
    {
        $institution = (int) db_connect()->table('institutions')->orderBy('id', 'desc')->get()->getRowArray()['id'];

        try {
            $added = db_connect()->table('institution_academic_defaults')->insert(['institution_id' => $institution, 'passing_percentage' => 150]);
        } catch (DatabaseException) {
            $added = false;
        }

        $this->assertFalse($added);
    }

    public function testAnAssessmentHasOneSettingsRow(): void
    {
        $assessment = CourseFactory::assessment($this->offering, CourseFactory::user('instructor'));
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $assessment]);

        try {
            $added = db_connect()->table('assessment_settings')->insert(['assessment_id' => $assessment]);
        } catch (DatabaseException) {
            $added = false;
        }

        $this->assertFalse($added);
        $this->assertSame(1, db_connect()->table('assessment_settings')->where('assessment_id', $assessment)->countAllResults());
    }

    public function testTheMigrationRollsBackAndBringsOldRowsInside(): void
    {
        $migration = new DatabaseRefusesImpossibleValues();
        $migration->down();

        try {
            $this->takes('course_offerings', ['capacity' => 0], $this->offering);
        } finally {
            $migration->up();
        }

        $this->assertSame('1', (string) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['capacity']);
        $this->refuses('course_offerings', ['capacity' => 0], $this->offering);
    }

    public function testTheMigrationKeepsTheFirstSettingsRow(): void
    {
        $this->assertSame([5, 6], DatabaseRefusesImpossibleValues::duplicatesToDelete([
            ['id' => 4, 'assessment_id' => 1],
            ['id' => 5, 'assessment_id' => 1],
            ['id' => 6, 'assessment_id' => 1],
            ['id' => 7, 'assessment_id' => 2],
        ]));
    }
}

<?php

declare(strict_types=1);

use App\Services\Grades\GradesAtRisk;
use App\Services\Grades\InstructorGradesOverview;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * Who is at risk across an instructor's sections: by grade (failing so far,
 * failed, incomplete) and by activity (inactive, behind).
 *
 * @internal
 */
final class GradesAtRiskTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private User $instructor;

    private int $offering;

    private int $scheme;

    /** @var list<int> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = CourseFactory::user('instructor');
        $this->offering = CourseFactory::offering('A');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        $this->scheme = GradeFactory::scheme($this->offering);
        $this->items = CourseFactory::items(CourseFactory::unit(CourseFactory::module($this->offering)), array_fill(0, 10, []));
    }

    private function publish(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published']);
    }

    private function student(string $last = 'Lovelace', string $status = 'enrolled'): User
    {
        $student = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert([
            'user_id' => $student->id,
            'first_name' => 'Ada',
            'last_name' => $last,
        ]);
        CourseFactory::enroll($this->offering, $student, $status);

        return $student;
    }

    /** Keeps the student active (recent completions) so only the grade can flag them. */
    private function active(User $student): void
    {
        for ($i = 0; $i < 8; $i++) {
            CourseFactory::progress($this->items[$i], $student);
        }
    }

    private function record(User $student, array $values): void
    {
        db_connect()->table('gradebook_records')->insert($values + [
            'course_offering_id' => $this->offering,
            'student_id' => $student->id,
            'grading_scheme_id' => $this->scheme,
            'remarks' => 'Incomplete',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function risk(?string $filter = null, ?string $today = null): array
    {
        $termId = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRow()->term_id;
        $sections = (new InstructorGradesOverview())->sections($this->instructor, $termId);

        return (new GradesAtRisk())->forSections($sections, $filter, $today);
    }

    /** @return list<string> reason texts of the first row */
    private function texts(array $rows): array
    {
        return array_column($rows[0]['reasons'], 'text');
    }

    public function testFailingSoFarAndFailedAreGradeReasons(): void
    {
        $this->publish();
        $failing = $this->student('Adams');
        $failed = $this->student('Brown');
        $fine = $this->student('Clark');
        foreach ([$failing, $failed, $fine] as $s) {
            $this->active($s);
        }
        $this->record($failing, ['calculated_percentage' => 58, 'remarks' => 'Failed']);
        $this->record($failed, ['calculated_percentage' => 60, 'remarks' => 'Failed', 'finalized_at' => '2026-10-01 10:00:00', 'final_percentage' => 61.25]);
        $this->record($fine, ['calculated_percentage' => 90, 'remarks' => 'Passed']);

        $rows = $this->risk();

        $this->assertCount(2, $rows);
        $this->assertSame('Ada Adams', $rows[0]['name']);
        $this->assertSame([['kind' => 'grade', 'text' => 'Failing · 58.0%']], $rows[0]['reasons']);
        $this->assertSame([['kind' => 'grade', 'text' => 'Failed · 61.3%']], $rows[1]['reasons']);
        $this->assertStringEndsWith('-A', $rows[0]['section']);
        $this->assertSame($this->offering, $rows[0]['offering_id']);
        $this->assertSame((int) $failing->id, $rows[0]['student_id']);
        $this->assertArrayHasKey('slug', $rows[0]);
    }

    public function testIncShowsItsDateAndWhenItIsOverdue(): void
    {
        $this->publish();
        $student = $this->student();
        $this->active($student);
        $this->record($student, ['inc_set_at' => '2026-11-01 08:00:00', 'inc_until' => '2026-12-20']);

        $this->assertSame(['INC · finish by Dec 20, 2026'], $this->texts($this->risk(null, '2026-12-20')));
        $this->assertSame(['INC · overdue'], $this->texts($this->risk(null, '2026-12-21')));

        db_connect()->table('gradebook_records')->where('student_id', $student->id)->update(['inc_until' => null]);
        $this->assertSame(['INC'], $this->texts($this->risk()));
    }

    public function testInactiveStudentsComeFromTheDashboardRule(): void
    {
        $this->student('Never');
        $stale = $this->student('Stale');
        $behind = $this->student('Behind');
        $this->active($this->student('Okay'));
        CourseFactory::progress($this->items[0], $stale);
        CourseFactory::progress($this->items[0], $behind);
        db_connect()->table('learning_completions')->where('student_user_id', $stale->id)
            ->update(['completed_at' => date('Y-m-d H:i:s', time() - 20 * 86400)]);

        $byName = [];
        foreach ($this->risk() as $row) {
            $byName[$row['name']] = $row['reasons'][0]['text'];
        }

        $this->assertSame([
            'Ada Behind' => 'Behind on published work',
            'Ada Never' => 'Never active',
            'Ada Stale' => 'Inactive 20 days',
        ], $byName);
    }

    public function testAStudentWithBothReasonsIsOneRow(): void
    {
        $this->publish();
        $student = $this->student();
        $this->record($student, ['calculated_percentage' => 40, 'remarks' => 'Failed']);

        $rows = $this->risk();

        $this->assertCount(1, $rows);
        $this->assertSame([
            ['kind' => 'grade', 'text' => 'Failing · 40.0%'],
            ['kind' => 'activity', 'text' => 'Never active'],
        ], $rows[0]['reasons']);
    }

    public function testTheFilterKeepsOnlyThatKind(): void
    {
        $this->publish();
        $both = $this->student('Both');
        $grade = $this->student('Grade');
        $inc = $this->student('Inc');
        $this->active($grade);
        $this->active($inc);
        $this->record($both, ['calculated_percentage' => 40, 'remarks' => 'Failed']);
        $this->record($grade, ['calculated_percentage' => 50, 'remarks' => 'Failed']);
        $this->record($inc, ['inc_set_at' => '2026-11-01 08:00:00']);

        $names = static fn (array $rows): array => array_column($rows, 'name');

        $this->assertSame(['Ada Both', 'Ada Grade'], $names($this->risk('grade')));
        $this->assertSame(['Ada Inc'], $names($this->risk('inc')));
        $this->assertSame(['Ada Both'], $names($this->risk('activity')));
        $this->assertCount(3, $this->risk());
    }

    public function testAnUnpublishedSchemeGivesNoGradeReasons(): void
    {
        $student = $this->student();
        $this->active($student);
        $this->record($student, ['calculated_percentage' => 40, 'remarks' => 'Failed']);

        $this->assertSame([], $this->risk());
        $this->assertSame([], $this->risk('grade'));
    }

    public function testStudentsWhoLeftTheSectionAreNotListed(): void
    {
        $this->publish();
        $student = $this->student('Gone', 'dropped');
        $this->record($student, ['calculated_percentage' => 40, 'remarks' => 'Failed']);

        $this->assertSame([], $this->risk());
    }
}

<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeSheetExportService;
use App\Services\Courses\Grades\GradeSheetImportService;
use App\Services\Groups\ActivityGroupService;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * The offline grade sheet is course-level and per student (D2): a group activity adds no column to it,
 * and importing can never write a group activity's score, so group_member_scores stays untouched.
 *
 * @internal
 */
final class GroupGradeSheetTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    private int $quizItem;

    private int $attendance;

    private $ada;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $teacher        = CourseFactory::user('instructor');
        $this->ada      = CourseFactory::user('student');
        db_connect()->table('user_profiles')->insert(['user_id' => $this->ada->id, 'first_name' => 'Ada', 'last_name' => 'Adams', 'student_number' => '2026-001']);
        CourseFactory::enroll($this->offering, $this->ada);

        $scheme   = GradeFactory::scheme($this->offering, 75.0, false, GradeFactory::scale());
        $quizzes  = GradeFactory::category($scheme, 'Work', 60);
        $other    = GradeFactory::category($scheme, 'Participation', 40, null, 2);
        $assessId = CourseFactory::assessment($this->offering, $teacher, CourseFactory::module($this->offering), null, 'Assignment');
        AssessmentFactory::open($assessId, ['total_points' => 10]);

        $this->quizItem   = GradeFactory::item($scheme, $quizzes, 'Project', 10, 1, $assessId);
        $this->attendance = GradeFactory::item($scheme, $other, 'Attendance', 10, 2);

        (new ActivityGroupService())->attachAssessment($assessId, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], (int) $teacher->id);

        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => (int) $db->table('activity_group_configs')->get()->getRowArray()['id'], 'name' => 'Team Rocket', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            is_file($file) && unlink($file);
        }

        parent::tearDown();
    }

    private function csv(): array
    {
        $result = (new GradeSheetExportService())->exportToCsv($this->offering);
        $this->assertTrue($result['success']);
        $this->files[] = $result['file_path'];

        $handle = fopen($result['file_path'], 'r');
        fread($handle, 3);
        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function write(array $rows): string
    {
        $path          = WRITEPATH . 'uploads/exports/grp-' . uniqid() . '.csv';
        $this->files[] = $path;
        $handle        = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        fclose($handle);

        return $path;
    }

    public function testTheSheetOfAGroupActivityHasNoGroupColumn(): void
    {
        $grid = $this->csv();

        $this->assertNotContains('Group', $grid[1]);
        $this->assertNotContains('Group', $grid[2]);
        $this->assertContains((string) $this->quizItem, array_map(static fn ($k) => preg_replace('/\D+/', '', (string) $k), $grid[1]));
    }

    public function testASheetWithAnExtraGroupColumnIsRefusedLikeAnyAddedColumnAndWritesNothing(): void
    {
        $grid = $this->csv();

        foreach ($grid as $i => $row) {
            $grid[$i][] = $i === 1 ? 'group' : ($i === 2 ? 'Group' : 'Team Rocket');
        }

        $preview = (new GradeSheetImportService())->preview($this->offering, $this->write($grid), 'csv');

        $this->assertFalse($preview['templateValid']);
        $this->assertStringContainsString('Columns were added', $preview['templateError']);
        $this->assertSame(0, db_connect()->table('group_member_scores')->countAllResults());
    }

    public function testImportAppliesManualScoresButNeverWritesAGroupActivityItem(): void
    {
        $signature = $this->csv()[0][1];
        $user      = CourseFactory::user('instructor');

        $result = (new GradeSheetImportService())->apply($user, $this->offering, ['signature' => $signature, 'changes' => [
            ['student_id' => (int) $this->ada->id, 'grade_item_id' => $this->attendance, 'value' => 7],
            ['student_id' => (int) $this->ada->id, 'grade_item_id' => $this->quizItem, 'value' => 9],
        ]]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('1 score imported', $result['message']);

        $db = db_connect();
        $this->assertSame(1, $db->table('gradebook_item_scores')->where('grade_item_id', $this->attendance)->countAllResults());
        $this->assertSame(0, $db->table('gradebook_item_scores')->where('grade_item_id', $this->quizItem)->countAllResults());
        $this->assertSame(0, $db->table('group_member_scores')->countAllResults());
        $this->assertSame(0, $db->table('assessment_submissions')->countAllResults());
    }
}

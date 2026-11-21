<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Criteria written before scope existed read "individual" (per-student, as they always behaved); the way back drops
 * only that column. DDL commits on its own, so this class removes what it made and leaves the schema as it found it.
 *
 * @internal
 */
final class AddScopeToRubricCriteriaMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-12-01-000009_AddScopeToAssessmentRubricCriteria.php';

    private ?int $rubric = null;

    private ?User $user = null;

    private function migration(): object
    {
        require_once self::FILE;

        return new App\Database\Migrations\AddScopeToAssessmentRubricCriteria();
    }

    private function hasColumn(): bool
    {
        db_connect()->resetDataCache();

        return db_connect()->fieldExists('scope', 'assessment_rubric_criteria');
    }

    protected function tearDown(): void
    {
        if ($this->rubric !== null) {
            db_connect()->table('assessment_rubrics')->where('id', $this->rubric)->delete();
        }

        if ($this->user !== null) {
            db_connect()->table('users')->where('id', $this->user->id)->delete();
        }

        if (! $this->hasColumn()) {
            $this->migration()->up();
        }

        parent::tearDown();
    }

    public function testExistingCriteriaBecomeIndividualAndTheMigrationIsReversible(): void
    {
        $db = db_connect();
        $db->table('assessment_rubrics')->insert(['title' => 'Old rubric', 'total_points' => 5, 'status' => 'Active', 'created_by' => ($this->user = CourseFactory::user('instructor'))->id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->rubric = (int) $db->insertID();

        $this->migration()->down();
        $this->assertFalse($this->hasColumn(), 'down() drops the column');

        $db->table('assessment_rubric_criteria')->insert(['rubric_id' => $this->rubric, 'criteria_title' => 'Old', 'max_points' => 5, 'order_index' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $this->migration()->up();
        $this->assertTrue($this->hasColumn());

        $row = $db->table('assessment_rubric_criteria')->where('rubric_id', $this->rubric)->get()->getRowArray();
        $this->assertSame('individual', $row['scope']);
    }
}

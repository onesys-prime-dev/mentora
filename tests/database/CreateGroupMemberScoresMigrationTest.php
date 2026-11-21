<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * group_member_scores: one row per member per graded submission, and a migration that can be undone. DDL commits on
 * its own, so this class always puts the table back.
 *
 * @internal
 */
final class CreateGroupMemberScoresMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-12-01-000010_CreateGroupMemberScores.php';

    private function migration(): object
    {
        require_once self::FILE;

        return new App\Database\Migrations\CreateGroupMemberScores();
    }

    private function exists(): bool
    {
        db_connect()->resetDataCache();

        return db_connect()->tableExists('group_member_scores');
    }

    protected function tearDown(): void
    {
        if (! $this->exists()) {
            $this->migration()->up();
        }

        parent::tearDown();
    }

    public function testTableShapeAndTheMigrationIsReversible(): void
    {
        $this->assertTrue($this->exists());

        $fields = array_column(db_connect()->getFieldData('group_member_scores'), null, 'name');

        foreach (['submission_id', 'student_user_id', 'group_id', 'group_points', 'individual_points', 'individual_rubric_scores', 'adjustment', 'adjustment_reason',
            'private_note', 'final_score', 'final_percentage', 'excused', 'pending', 'graded_by', 'graded_at', 'created_at', 'updated_at'] as $field) {
            $this->assertArrayHasKey($field, $fields, $field);
        }

        $this->assertSame('decimal', strtolower($fields['final_score']->type));
        $this->assertSame(8, (int) $fields['final_score']->max_length);

        $unique = array_filter(db_connect()->getIndexData('group_member_scores'), static fn ($index) => $index->type === 'UNIQUE');
        $this->assertSame([['submission_id', 'student_user_id']], array_values(array_map(static fn ($index) => $index->fields, $unique)));

        $this->migration()->down();
        $this->assertFalse($this->exists(), 'down() drops the table');

        $this->migration()->up();
        $this->assertTrue($this->exists());
    }
}

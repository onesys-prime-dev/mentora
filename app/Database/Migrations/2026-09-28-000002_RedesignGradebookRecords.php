<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * gradebook_records becomes the cached final grade per student per course
 * OFFERING (it was per course, so two sections of one course shared rows).
 * GradingEngineService writes it; nothing else computes final grades.
 *
 * The old rows were demo-seed data that was never recalculated, so they are
 * dropped, not migrated. grade_history (override audit trail) is created here
 * because it references the new table.
 */
class RedesignGradebookRecords extends Migration
{
    private function id(bool $autoIncrement = false, bool $null = false): array
    {
        return array_filter([
            'type' => 'INT',
            'constraint' => 20,
            'unsigned' => true,
            'auto_increment' => $autoIncrement ?: null,
            'null' => $null ?: null,
        ], static fn ($value) => $value !== null);
    }

    private function foreignKey(string $table, string $name, string $column, string $references, string $onDelete): void
    {
        $this->db->query("
            ALTER TABLE {$table}
            ADD CONSTRAINT {$name}
            FOREIGN KEY ({$column})
            REFERENCES {$references}
            ON DELETE {$onDelete}
            ON UPDATE CASCADE
        ");
    }

    public function up()
    {
        $this->forge->dropTable('gradebook_records', true);

        $this->forge->addField([
            'id' => $this->id(true),
            'course_offering_id' => $this->id(),
            'student_id' => $this->id(),
            'grading_scheme_id' => $this->id(false, true),
            // NULL = nothing graded yet ("Incomplete").
            'calculated_percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'calculated_grade_scale_item_id' => $this->id(false, true),
            'is_override' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'overridden_percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'overridden_grade_scale_item_id' => $this->id(false, true),
            'override_reason' => ['type' => 'TEXT', 'null' => true],
            'overridden_by' => $this->id(false, true),
            'remarks' => [
                'type' => 'ENUM',
                'constraint' => ['Passed', 'Failed', 'Incomplete', 'Withdrawn'],
                'default' => 'Incomplete',
            ],
            'last_calculated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['course_offering_id', 'student_id'], 'uq_gradebook_offering_student');
        $this->forge->addKey('grading_scheme_id');
        $this->forge->createTable('gradebook_records');

        $this->foreignKey('gradebook_records', 'fk_gradebook_offering', 'course_offering_id', 'course_offerings(id)', 'CASCADE');
        $this->foreignKey('gradebook_records', 'fk_gradebook_scheme', 'grading_scheme_id', 'grading_schemes(id)', 'SET NULL');
        $this->foreignKey('gradebook_records', 'fk_gradebook_calc_band', 'calculated_grade_scale_item_id', 'grade_scale_items(id)', 'SET NULL');
        $this->foreignKey('gradebook_records', 'fk_gradebook_override_band', 'overridden_grade_scale_item_id', 'grade_scale_items(id)', 'SET NULL');

        /*
        | grade_history — written only when a final grade is overridden
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'gradebook_record_id' => $this->id(),
            'previous_percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'new_percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'reason' => ['type' => 'TEXT', 'null' => true],
            'changed_by' => $this->id(false, true),
            'changed_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('gradebook_record_id');
        $this->forge->createTable('grade_history');

        $this->foreignKey('grade_history', 'fk_history_record', 'gradebook_record_id', 'gradebook_records(id)', 'CASCADE');
    }

    public function down()
    {
        $this->forge->dropTable('grade_history', true);
        $this->forge->dropTable('gradebook_records', true);

        // Restore the previous course-scoped table. Migration files aren't
        // autoloadable (date-prefixed names), so load it explicitly.
        require_once __DIR__ . '/2026-07-17-000009_CreateGradebookRecords.php';

        (new CreateGradebookRecords($this->forge))->up();
    }
}

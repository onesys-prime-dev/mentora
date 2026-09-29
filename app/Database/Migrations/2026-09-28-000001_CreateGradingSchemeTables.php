<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Grading configuration for the gradebook:
 *
 *   grade_scales / grade_scale_items   percentage → final grade bands
 *   grading_schemes                    one per course offering (+ one system default template)
 *   grade_categories                   weighted groups (Quizzes 50%, ...)
 *   grade_items                        what counts: an assessment, or a manual item
 *   gradebook_item_scores              each student's score per grade item
 *
 * The system default scheme and grade scale are inserted here, not by the
 * demo seeder, so every install has them. Both are ordinary rows — editable,
 * never hard-coded — and an offering's scheme is a copy of the template.
 */
class CreateGradingSchemeTables extends Migration
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
        /*
        | grade_scales
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_by' => $this->id(false, true),
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_by' => $this->id(false, true),
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['Active', 'Archived'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('grade_scales');

        /*
        | grade_scale_items
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'grade_scale_id' => $this->id(),
            'name' => ['type' => 'VARCHAR', 'constraint' => 50],
            'minimum_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'maximum_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'numeric_value' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'sort_order' => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('grade_scale_id');
        $this->forge->createTable('grade_scale_items');

        $this->foreignKey('grade_scale_items', 'fk_scale_item_scale', 'grade_scale_id', 'grade_scales(id)', 'CASCADE');

        /*
        | grading_schemes — course_offering_id NULL = the system default template
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'course_offering_id' => $this->id(false, true),
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'is_system_default' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'passing_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 75.00],
            'grade_scale_id' => $this->id(false, true),
            'status' => ['type' => 'ENUM', 'constraint' => ['Draft', 'Published', 'Archived'], 'default' => 'Draft'],
            // Config changed since the last full recalculation.
            'needs_recalculation' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'last_recalculated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => $this->id(false, true),
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_by' => $this->id(false, true),
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // One scheme per offering; NULLs (the template) don't collide.
        $this->forge->addUniqueKey('course_offering_id', 'uq_grading_scheme_offering');
        $this->forge->addKey('grade_scale_id');
        $this->forge->createTable('grading_schemes');

        $this->foreignKey('grading_schemes', 'fk_scheme_offering', 'course_offering_id', 'course_offerings(id)', 'CASCADE');
        $this->foreignKey('grading_schemes', 'fk_scheme_scale', 'grade_scale_id', 'grade_scales(id)', 'SET NULL');

        /*
        | grade_categories
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'grading_scheme_id' => $this->id(),
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'weight_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['Active', 'Archived'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('grading_scheme_id');
        $this->forge->createTable('grade_categories');

        $this->foreignKey('grade_categories', 'fk_category_scheme', 'grading_scheme_id', 'grading_schemes(id)', 'CASCADE');

        /*
        | grade_items — max_points is a snapshot, never live-linked to the
        | assessment's total_points (editing a quiz must not silently reweight).
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'grading_scheme_id' => $this->id(),
            'category_id' => $this->id(false, true),
            'assessment_id' => $this->id(false, true),
            'name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'max_points' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'created_by' => $this->id(false, true),
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_by' => $this->id(false, true),
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['Active', 'Archived'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('grading_scheme_id');
        $this->forge->addKey('category_id');
        $this->forge->addUniqueKey('assessment_id', 'uq_grade_item_assessment');
        $this->forge->createTable('grade_items');

        $this->foreignKey('grade_items', 'fk_grade_item_scheme', 'grading_scheme_id', 'grading_schemes(id)', 'CASCADE');
        $this->foreignKey('grade_items', 'fk_grade_item_category', 'category_id', 'grade_categories(id)', 'SET NULL');
        // A deleted assessment leaves its (archived) item and scores for history.
        $this->foreignKey('grade_items', 'fk_grade_item_assessment', 'assessment_id', 'assessments(id)', 'SET NULL');

        /*
        | gradebook_item_scores — each student's score per grade item
        */

        $this->forge->addField([
            'id' => $this->id(true),
            'course_offering_id' => $this->id(),
            'student_id' => $this->id(),
            'grade_item_id' => $this->id(),
            // The submission that counts (AssessmentResultService); null for manual items.
            'assessment_submission_id' => $this->id(false, true),
            'raw_score' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'raw_percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'is_missing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'graded_by' => $this->id(false, true),
            'graded_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('course_offering_id');
        $this->forge->addKey('student_id');
        $this->forge->addUniqueKey(['grade_item_id', 'student_id'], 'uq_item_score_student');
        $this->forge->createTable('gradebook_item_scores');

        $this->foreignKey('gradebook_item_scores', 'fk_item_score_offering', 'course_offering_id', 'course_offerings(id)', 'CASCADE');
        $this->foreignKey('gradebook_item_scores', 'fk_item_score_item', 'grade_item_id', 'grade_items(id)', 'CASCADE');
        $this->foreignKey('gradebook_item_scores', 'fk_item_score_submission', 'assessment_submission_id', 'assessment_submissions(id)', 'SET NULL');

        $this->seedDefaults();
    }

    /**
     * The system default grade scale and grading scheme template.
     *
     * The scale is a common Philippine college scale (1.00–5.00, 75% passing).
     * It's a starting point to edit, not a rule — institutions differ.
     */
    private function seedDefaults(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('grade_scales')->insert([
            'name' => '1.00–5.00 (75% passing)',
            'created_at' => $now,
            'status' => 'Active',
        ]);

        $scaleId = (int) $this->db->insertID();

        $bands = [
            ['1.00', 97, 100], ['1.25', 94, 96.99], ['1.50', 91, 93.99], ['1.75', 88, 90.99],
            ['2.00', 85, 87.99], ['2.25', 82, 84.99], ['2.50', 79, 81.99], ['2.75', 76, 78.99],
            ['3.00', 75, 75.99], ['5.00', 0, 74.99],
        ];

        foreach ($bands as $index => [$name, $min, $max]) {
            $this->db->table('grade_scale_items')->insert([
                'grade_scale_id' => $scaleId,
                'name' => $name,
                'minimum_percentage' => $min,
                'maximum_percentage' => $max,
                'numeric_value' => (float) $name,
                'sort_order' => $index + 1,
            ]);
        }

        $this->db->table('grading_schemes')->insert([
            'course_offering_id' => null,
            'name' => 'Default Grading Scheme',
            'is_system_default' => 1,
            'passing_percentage' => 75,
            'grade_scale_id' => $scaleId,
            'status' => 'Published',
            'created_at' => $now,
        ]);

        $schemeId = (int) $this->db->insertID();

        // Mentora's two assessment types; Quiz → "Quizzes", Assignment → "Assignments".
        foreach ([['Quizzes', 50], ['Assignments', 50]] as $index => [$name, $weight]) {
            $this->db->table('grade_categories')->insert([
                'grading_scheme_id' => $schemeId,
                'name' => $name,
                'weight_percentage' => $weight,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'status' => 'Active',
            ]);
        }
    }

    public function down()
    {
        foreach (['gradebook_item_scores', 'grade_items', 'grade_categories', 'grading_schemes', 'grade_scale_items', 'grade_scales'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

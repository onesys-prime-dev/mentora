<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Grading on a curve. An adjustment changes how one item's scores (or every
 * student's final percentage) are counted, without touching the scores that
 * were entered. Its numbers are worked out once, when it is applied, and kept
 * (params), so later scores do not shift an adjustment that was agreed.
 */
class CreateGradeAdjustments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'course_offering_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            // 'item' = one grade item's scores; 'final' = every student's final percentage.
            'scope' => ['type' => 'VARCHAR', 'constraint' => 10],
            'grade_item_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'method' => ['type' => 'VARCHAR', 'constraint' => 20],
            'input' => ['type' => 'TEXT', 'null' => true],
            'params' => ['type' => 'TEXT'],
            'note' => ['type' => 'TEXT', 'null' => true],
            'students_raised' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_by' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'undone_by' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'undone_at' => ['type' => 'DATETIME', 'null' => true],
            // 1 while this adjustment counts, NULL once undone or replaced; with the
            // target below, the unique key allows only one live adjustment per target.
            'live_marker' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            // 0 for the final-grade scope, else the item id: makes (target, marker) unique.
            'target_key' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('course_offering_id');
        $this->forge->addUniqueKey(['course_offering_id', 'target_key', 'live_marker'], 'uq_grade_adjustment_live');
        $this->forge->createTable('grade_adjustments');

        $this->db->query('ALTER TABLE grade_adjustments ADD CONSTRAINT fk_grade_adjustment_offering FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON DELETE CASCADE ON UPDATE CASCADE');
        $this->db->query('ALTER TABLE grade_adjustments ADD CONSTRAINT fk_grade_adjustment_item FOREIGN KEY (grade_item_id) REFERENCES grade_items(id) ON DELETE CASCADE ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->forge->dropTable('grade_adjustments', true);
    }
}

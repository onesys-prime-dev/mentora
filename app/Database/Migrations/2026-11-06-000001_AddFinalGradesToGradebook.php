<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Finalizing a section's grades: each student's final grade (missing work
 * counted as 0) is frozen on their gradebook record, and one row per
 * finalization keeps who finalized, and who reopened it and why.
 */
class AddFinalGradesToGradebook extends Migration
{
    public function up()
    {
        $this->forge->addColumn('gradebook_records', [
            'finalized_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'remarks'],
            'finalized_by' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true, 'after' => 'finalized_at'],
            'final_percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true, 'after' => 'finalized_by'],
            'final_grade_scale_item_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true, 'after' => 'final_percentage'],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'course_offering_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'finalized_by' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'finalized_at' => ['type' => 'DATETIME'],
            'students' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reopened_by' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'reopened_at' => ['type' => 'DATETIME', 'null' => true],
            'reopen_reason' => ['type' => 'TEXT', 'null' => true],
            // 1 while this is the section's current finalization, NULL once reopened:
            // a unique key on (section, marker) allows only one current row.
            'open_marker' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['course_offering_id', 'open_marker'], 'uq_grade_finalization_current');
        $this->forge->createTable('grade_finalizations');

        $this->db->query('ALTER TABLE grade_finalizations ADD CONSTRAINT fk_grade_finalization_offering FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON DELETE CASCADE ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->forge->dropTable('grade_finalizations', true);

        $this->forge->dropColumn('gradebook_records', ['finalized_at', 'finalized_by', 'final_percentage', 'final_grade_scale_item_id']);
    }
}

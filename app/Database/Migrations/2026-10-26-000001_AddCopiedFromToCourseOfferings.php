<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An offering made by "copy offerings into another term" remembers the one it
 * was copied from. That lets the source term refuse to be reopened once
 * something has been built on it, and says where a copy came from. Existing
 * offerings were not copied, so the column stays empty for them.
 */
class AddCopiedFromToCourseOfferings extends Migration
{
    public function up()
    {
        $this->forge->addColumn('course_offerings', [
            'copied_from_offering_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
        ]);

        $this->db->query('CREATE INDEX idx_course_offerings_copied_from ON course_offerings (copied_from_offering_id)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX idx_course_offerings_copied_from ON course_offerings');
        $this->forge->dropColumn('course_offerings', 'copied_from_offering_id');
    }
}

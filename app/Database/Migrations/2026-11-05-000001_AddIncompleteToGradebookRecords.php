<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An instructor can mark a student's grade Incomplete (INC): not failed, not
 * passed, with a reason and an optional date to finish by. The tag lives in
 * the existing remarks column (new value INC), the details beside it.
 */
class AddIncompleteToGradebookRecords extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE gradebook_records MODIFY remarks ENUM('Passed','Failed','Incomplete','Withdrawn','INC') NOT NULL DEFAULT 'Incomplete'");

        $this->forge->addColumn('gradebook_records', [
            'inc_set_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'remarks'],
            'inc_set_by' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true, 'after' => 'inc_set_at'],
            'inc_reason' => ['type' => 'TEXT', 'null' => true, 'after' => 'inc_set_by'],
            'inc_until' => ['type' => 'DATE', 'null' => true, 'after' => 'inc_reason'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('gradebook_records', ['inc_set_at', 'inc_set_by', 'inc_reason', 'inc_until']);

        $this->db->query("UPDATE gradebook_records SET remarks = 'Incomplete' WHERE remarks = 'INC'");
        $this->db->query("ALTER TABLE gradebook_records MODIFY remarks ENUM('Passed','Failed','Incomplete','Withdrawn') NOT NULL DEFAULT 'Incomplete'");
    }
}

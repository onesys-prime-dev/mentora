<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What final grade each student has already been sent to the SIS for each
 * offering (SA10, outbound). A run sends only what is new or changed since,
 * so grades are never sent twice. `batch_key` is the delivery the value went
 * out in. Removed with the institution or the offering.
 */
class CreateSisGradeExports extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'offering_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'percentage' => ['type' => 'DECIMAL', 'constraint' => '6,2'],
            'remarks' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => ''],
            'batch_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['offering_id', 'student_id']);
        $this->forge->addKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sis_grade_exports');
    }

    public function down()
    {
        $this->forge->dropTable('sis_grade_exports', true);
    }
}

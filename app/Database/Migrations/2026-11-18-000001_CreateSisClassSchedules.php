<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which sections' class times the SIS supplies (audit decisions D8, D9), as `sis_staff_assignments` does for
 * staff: one row per offering the SIS owns. While the institution's connection is enabled, those times are
 * locked on the Class times page; a sync that no longer lists the offering clears its SIS times and this row.
 */
class CreateSisClassSchedules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'offering_id'    => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('offering_id');
        $this->forge->addKey('institution_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sis_class_schedules');
    }

    public function down(): void
    {
        $this->forge->dropTable('sis_class_schedules', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which deans, chairpersons and instructors an institution's SIS supplied (`sis_staff_assignments`).
 *
 * One row per assignment the SIS listed: who (the person), what they lead or teach (the department,
 * sub-department or offering) and which assignment row it became (a dean assignment, a chairperson
 * assignment or an instructor assignment). Two things depend on it: the sync ends only what the SIS
 * itself supplied once the SIS stops listing it (an assignment an administrator made by hand is never
 * touched), and, while the connection is enabled, the assignments it supplied are not editable by hand.
 * The row says nothing about history: that stays in the assignment tables and the audit log.
 *
 * `local_id` points at a different table for each role, so it has no foreign key. A row whose
 * assignment has already ended is cleaned up by the next sync. Removed with the institution.
 */
class CreateSisStaffAssignments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'role'           => ['type' => 'VARCHAR', 'constraint' => 20],
            'person_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'target_id'      => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'local_id'       => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['institution_id', 'role', 'person_user_id', 'target_id']);
        $this->forge->addKey(['role', 'local_id']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sis_staff_assignments');
    }

    public function down()
    {
        $this->forge->dropTable('sis_staff_assignments', true);
    }
}

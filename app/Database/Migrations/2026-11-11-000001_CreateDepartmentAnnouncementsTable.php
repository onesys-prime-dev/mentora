<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A dean's announcement to part of their department (DepartmentAnnouncementService).
 *
 * `scope_type` and `scope_id` say whom it is for: the whole department, one sub-department or one
 * program (the id is that row's id, checked by the service, so there is no foreign key on it).
 * `audience` narrows by role: everyone, staff (instructors and chairpersons) or students. Who actually
 * receives it is never stored: it is worked out from the hierarchy, the sections people teach and the
 * enrolments they hold, every time. It shows until `ends_on` (that whole day) or until the dean ends
 * it, which keeps the row as history.
 */
class CreateDepartmentAnnouncementsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'department_id' => ['type' => 'INT', 'unsigned' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'scope_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'scope_id' => ['type' => 'INT', 'unsigned' => true],
            'audience' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'everyone'],
            'title' => ['type' => 'VARCHAR', 'constraint' => 160],
            'message' => ['type' => 'TEXT'],
            'ends_on' => ['type' => 'DATE', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'active'],
            'ended_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ended_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['department_id', 'status']);
        $this->forge->addKey(['scope_type', 'scope_id']);
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('ended_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('department_announcements');
    }

    public function down()
    {
        $this->forge->dropTable('department_announcements');
    }
}

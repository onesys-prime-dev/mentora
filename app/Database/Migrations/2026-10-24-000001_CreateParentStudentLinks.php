<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which students a parent account is responsible for (`parent_student_links`).
 * The institutional administrator makes the links, inside one institution
 * (both people must belong to it). A link is never edited: it is made or
 * removed, and each is audited. Removed with the institution.
 */
class CreateParentStudentLinks extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'parent_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'created_by_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['institution_id', 'parent_user_id', 'student_user_id']);
        $this->forge->addKey('student_user_id');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('parent_student_links');
    }

    public function down()
    {
        $this->forge->dropTable('parent_student_links', true);
    }
}

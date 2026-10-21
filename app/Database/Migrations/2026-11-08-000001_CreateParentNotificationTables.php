<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The two small tables behind the parent notifications.
 *
 * `parent_child_preferences`: whether a parent wants email about a child (the
 * app's own notification is always on). No row means yes; a row is written the
 * first time the parent saves their choices.
 *
 * `parent_alerted_items`: the things a child's parents have already been told
 * about that are not a notification of their own to deduplicate (a piece of
 * work not handed in), so each is reported once, ever.
 */
class CreateParentNotificationTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'parent_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'email_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['parent_user_id', 'student_user_id']);
        $this->forge->createTable('parent_child_preferences');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'kind' => ['type' => 'VARCHAR', 'constraint' => 20],
            'ref_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['student_user_id', 'kind', 'ref_id']);
        $this->forge->createTable('parent_alerted_items');
    }

    public function down()
    {
        $this->forge->dropTable('parent_alerted_items', true);
        $this->forge->dropTable('parent_child_preferences', true);
    }
}

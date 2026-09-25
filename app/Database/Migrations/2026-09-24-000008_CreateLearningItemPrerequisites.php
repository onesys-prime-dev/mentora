<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The items a learning item waits on when its prerequisite_mode is
 * "custom". Both sides cascade, so deleting either item drops the link.
 */
class CreateLearningItemPrerequisites extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'learning_item_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],

            'prerequisite_item_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('prerequisite_item_id');

        $this->forge->addUniqueKey(['learning_item_id', 'prerequisite_item_id']);

        $this->forge->addForeignKey('learning_item_id', 'learning_items', 'id', 'CASCADE', 'CASCADE');

        $this->forge->addForeignKey('prerequisite_item_id', 'learning_items', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('learning_item_prerequisites');
    }

    public function down()
    {
        $this->forge->dropTable('learning_item_prerequisites', true);
    }
}

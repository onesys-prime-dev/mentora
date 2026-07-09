<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLearningCompletionsTable extends Migration
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

            'student_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'not_started',
                    'in_progress',
                    'completed',
                ],
                'default'    => 'not_started',
            ],

            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('learning_item_id');
        $this->forge->addKey('student_user_id');
        $this->forge->addKey('status');

        $this->forge->addUniqueKey([
            'learning_item_id',
            'student_user_id',
        ]);

        $this->forge->addForeignKey(
            'learning_item_id',
            'learning_items',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'student_user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('learning_completions');
    }

    public function down()
    {
        $this->forge->dropTable('learning_completions', true);
    }
}
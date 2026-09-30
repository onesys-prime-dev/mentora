<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Personal to-dos a student or instructor sets for themselves — the thing
 * the dashboard's Reminders card and the Calendar's "Add Reminder" action
 * both write to. Optionally linked to a course, but never shared: a
 * reminder is only ever visible to the user who created it.
 */
class CreateRemindersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],

            'user_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            // Optional context — "Bring rubric to class" ties naturally to a
            // course, but a personal to-do doesn't have to.
            'offering_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
            ],

            'title' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],

            'note' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'remind_at' => [
                'type' => 'DATETIME',
            ],

            'status' => [
                'type' => 'ENUM',
                'constraint' => ['pending', 'done'],
                'default' => 'pending',
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

        $this->forge->addKey('user_id');
        $this->forge->addKey('remind_at');

        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('reminders');
    }

    public function down()
    {
        $this->forge->dropTable('reminders');
    }
}

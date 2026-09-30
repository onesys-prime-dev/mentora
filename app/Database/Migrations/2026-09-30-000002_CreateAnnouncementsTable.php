<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Instructor posts to their own section — the Announcements tab's real
 * backing (currently a stub view with no data), and one of the event
 * kinds the Calendar aggregates.
 *
 * Course-scoped rather than section-wide by design: two sections of the
 * same course can be run at a different pace, so a post that says "read
 * chapter 4 before Friday" needs to land on one section's calendar, not
 * both.
 */
class CreateAnnouncementsTable extends Migration
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

            'offering_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'author_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'title' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],

            'body' => [
                'type' => 'TEXT',
            ],

            'status' => [
                'type' => 'ENUM',
                'constraint' => ['Draft', 'Published'],
                'default' => 'Published',
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

        $this->forge->addKey('offering_id');
        $this->forge->addKey('created_at');

        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');

        $this->forge->addForeignKey('author_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('announcements');
    }

    public function down()
    {
        $this->forge->dropTable('announcements');
    }
}

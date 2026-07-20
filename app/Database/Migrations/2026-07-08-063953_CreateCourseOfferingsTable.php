<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCourseOfferingsTable extends Migration
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

            'course_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'term_id' => [
                'type' => 'INT',
                'constraint' => 20,
                'unsigned' => true,
            ],

            'section_code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],

            'capacity' => [
                'type' => 'INT',
                'constraint' => 5,
                'default' => 40,
            ],
            
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'status' => [
                'type' => 'ENUM',
                'constraint' => [
                    'active',
                    'completed',
                    'archived',
                ],
                'default' => 'active',
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

        $this->forge->addKey('course_id');
        $this->forge->addKey('term_id');

        $this->forge->addUniqueKey([
            'course_id',
            'term_id',
            'section_code',
        ]);

        $this->forge->addForeignKey(
            'course_id',
            'courses',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->addForeignKey(
            'term_id',
            'academic_terms',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('course_offerings');
    }

    public function down()
    {
        $this->forge->dropTable('course_offerings');
    }
}
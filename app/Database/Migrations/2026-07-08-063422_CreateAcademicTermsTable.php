<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAcademicTermsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([

            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'institution_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'academic_year' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],

            'start_date' => [
                'type' => 'DATE',
            ],

            'end_date' => [
                'type' => 'DATE',
            ],

            'status' => [
                'type' => 'ENUM',
                'constraint' => [
                    'upcoming',
                    'active',
                    'completed',
                    'archived',
                ],
                'default' => 'upcoming',
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

        $this->forge->addKey('institution_id');

        $this->forge->addKey('status');

        $this->forge->addUniqueKey([
            'institution_id',
            'name',
            'academic_year',
        ]);

        $this->forge->addForeignKey(
            'institution_id',
            'institutions',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('academic_terms');
    }

    public function down()
    {
        $this->forge->dropTable('academic_terms');
    }
}
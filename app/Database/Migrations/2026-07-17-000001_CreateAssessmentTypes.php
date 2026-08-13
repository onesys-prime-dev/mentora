<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentTypes extends Migration
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

            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'isSystem' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'createdBy' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'createdAt' => [
                'type' => 'DATETIME',
            ],

            'updatedBy' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'updatedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['Active', 'Archived'],
                'default'    => 'Active',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('name', false, true);
        $this->forge->addKey('createdBy');
        $this->forge->addKey('updatedBy');

        $this->forge->createTable('assessment_types');

        $this->db->query("
            ALTER TABLE assessment_types
            ADD CONSTRAINT fk_assessment_types_created_by
            FOREIGN KEY (createdBy)
            REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_types
            ADD CONSTRAINT fk_assessment_types_updated_by
            FOREIGN KEY (updatedBy)
            REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_types', true);
    }
}
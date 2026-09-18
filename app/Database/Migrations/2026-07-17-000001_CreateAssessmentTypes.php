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

            'is_system' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],

            'created_by' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],

            'updated_by' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'updated_at' => [
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
        $this->forge->addKey('created_by');
        $this->forge->addKey('updated_by');

        $this->forge->createTable('assessment_types');

        $this->db->query("
            ALTER TABLE assessment_types
            ADD CONSTRAINT fk_assessment_types_created_by
            FOREIGN KEY (created_by)
            REFERENCES users(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_types
            ADD CONSTRAINT fk_assessment_types_updated_by
            FOREIGN KEY (updated_by)
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

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssessmentSettings extends Migration
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

            'assessment_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'allow_late_submission' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'show_results_immediately' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'show_correct_answers' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'allow_review_after_submission' => [
                'type' => 'BOOLEAN',
                'default' => true,
            ],

            'require_password' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'password' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],

            'randomize_questions' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'randomize_choices' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'browser_lockdown' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'allow_multiple_attempts' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'created_at' => [
                'type' => 'DATETIME',
            ],

            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('assessment_id');

        $this->forge->createTable('assessment_settings');

        $this->db->query("
            ALTER TABLE assessment_settings
            ADD CONSTRAINT fk_assessment_settings_assessment
            FOREIGN KEY (assessment_id)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->forge->dropTable('assessment_settings', true);
    }
}

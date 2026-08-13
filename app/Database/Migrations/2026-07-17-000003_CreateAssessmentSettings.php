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

            'assessmentId' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'allowLateSubmission' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'showResultsImmediately' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'showCorrectAnswers' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'allowReviewAfterSubmission' => [
                'type' => 'BOOLEAN',
                'default' => true,
            ],

            'requirePassword' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'password' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],

            'randomizeQuestions' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'randomizeChoices' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'browserLockdown' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'allowMultipleAttempts' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],

            'createdAt' => [
                'type' => 'DATETIME',
            ],

            'updatedAt' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('assessmentId');

        $this->forge->createTable('assessment_settings');

        $this->db->query("
            ALTER TABLE assessment_settings
            ADD CONSTRAINT fk_assessment_settings_assessment
            FOREIGN KEY (assessmentId)
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
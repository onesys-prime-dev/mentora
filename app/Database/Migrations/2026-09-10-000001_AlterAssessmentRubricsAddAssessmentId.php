<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentRubricsAddAssessmentId extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('assessment_rubrics', [
            'question_id' => [
                'name'       => 'question_id',
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
        ]);

        $this->forge->addColumn('assessment_rubrics', [
            'assessment_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'question_id',
            ],
        ]);

        $this->db->query("
            ALTER TABLE assessment_rubrics
            ADD CONSTRAINT fk_rubric_assessment
            FOREIGN KEY (assessment_id)
            REFERENCES assessments(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->db->query("
            ALTER TABLE assessment_rubrics
            DROP FOREIGN KEY fk_rubric_assessment
        ");

        $this->forge->dropColumn('assessment_rubrics', 'assessment_id');

        $this->forge->modifyColumn('assessment_rubrics', [
            'question_id' => [
                'name'       => 'question_id',
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => false,
            ],
        ]);
    }
}

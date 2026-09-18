<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentSubmissionsAddGradingStatus extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('assessment_submissions', [
            'status' => [
                'name'       => 'status',
                'type'       => 'ENUM',
                'constraint' => [
                    'In Progress',
                    'Submitted',
                    'Grading',
                    'Graded',
                    'Returned',
                    'Cancelled'
                ],
                'default' => 'In Progress',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('assessment_submissions', [
            'status' => [
                'name'       => 'status',
                'type'       => 'ENUM',
                'constraint' => [
                    'In Progress',
                    'Submitted',
                    'Graded',
                    'Returned',
                    'Cancelled'
                ],
                'default' => 'In Progress',
            ],
        ]);
    }
}

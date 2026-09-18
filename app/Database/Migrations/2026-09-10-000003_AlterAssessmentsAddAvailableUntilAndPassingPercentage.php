<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentsAddAvailableUntilAndPassingPercentage extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessments', [
            'available_until' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'due_date',
            ],
            'passing_percentage' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'null' => true,
                'after' => 'passing_score',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assessments', [
            'available_until',
            'passing_percentage',
        ]);
    }
}

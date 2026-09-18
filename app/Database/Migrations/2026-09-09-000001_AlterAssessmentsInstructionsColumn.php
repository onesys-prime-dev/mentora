<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentsInstructionsColumn extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('assessments', [
            'instructions' => [
                'name' => 'instructions',
                'type' => 'LONGTEXT',
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('assessments', [
            'instructions' => [
                'name' => 'instructions',
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
    }
}

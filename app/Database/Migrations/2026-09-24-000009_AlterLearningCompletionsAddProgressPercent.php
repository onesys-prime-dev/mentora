<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How far a student has watched a video item (0–100). Only ever
 * increases; "Mark as Completed" unlocks at 90.
 */
class AlterLearningCompletionsAddProgressPercent extends Migration
{
    public function up()
    {
        $this->forge->addColumn('learning_completions', [
            'progress_percent' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 0,
                'null'       => false,
                'after'      => 'status',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('learning_completions', 'progress_percent');
    }
}

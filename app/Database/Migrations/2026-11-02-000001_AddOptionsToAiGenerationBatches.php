<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the teacher steered a generation with: difficulty, thinking level,
 * language, a focus note and the number of choices. Kept with the batch so a
 * replacement question is written the same way.
 */
class AddOptionsToAiGenerationBatches extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generation_batches', [
            'options' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'JSON: difficulty, level, language, focus, choices',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generation_batches', 'options');
    }
}

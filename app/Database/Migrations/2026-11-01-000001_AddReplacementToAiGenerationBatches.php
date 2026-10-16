<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A one-question batch can stand in for a staged question the teacher asked
 * to replace, with a short instruction ("harder", "a different angle").
 */
class AddReplacementToAiGenerationBatches extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generation_batches', [
            'replaces_question_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'The staged question this one-question batch replaces',
            ],
            'instruction' => [
                'type'       => 'VARCHAR',
                'constraint' => 300,
                'null'       => true,
                'comment'    => 'What the teacher asked for in the replacement',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generation_batches', ['replaces_question_id', 'instruction']);
    }
}

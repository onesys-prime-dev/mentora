<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a generation produced and why it fell short (per-item coverage, what
 * was dropped and for which reason), and a link from a top-up batch to the
 * batch it adds questions to.
 */
class AddDiagnosticsToAiGenerationBatches extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generation_batches', [
            'diagnostics' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'JSON: coverage per item, dropped reasons, notes, truncated',
            ],
            'extends_batch_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'The batch this one adds questions to',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generation_batches', ['diagnostics', 'extends_batch_id']);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The learning items a batch was generated from, with the text read from each.
 * Generation has one source, the quiz's learning unit, and the person chooses
 * which readings and PDFs of it to use; this keeps that choice (and what was
 * read) with the batch so the worker is given exactly those items.
 */
class AddSourceItemsToAiGenerationBatches extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generation_batches', [
            'source_items' => [
                'type'    => 'LONGTEXT',
                'null'    => true,
                'after'   => 'source_text',
                'comment' => 'JSON: the selected learning items [{id, title, type, text}] this batch was generated from',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generation_batches', 'source_items');
    }
}

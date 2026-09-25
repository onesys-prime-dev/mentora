<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAiGenerationBatchesAddRequestContext extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generation_batches', [

            'topic' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'source_type',
            ],

            'source_text' => [
                'type'    => 'LONGTEXT',
                'null'    => true,
                'after'   => 'source_file_id',
                'comment' => 'Pasted text, or text extracted from source_file_id — cached so each chunk call reuses it without re-extracting',
            ],

            'type_mix' => [
                'type'  => 'JSON',
                'null'  => true,
                'after' => 'source_text',
            ],

        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generation_batches', ['topic', 'source_text', 'type_mix']);
    }
}

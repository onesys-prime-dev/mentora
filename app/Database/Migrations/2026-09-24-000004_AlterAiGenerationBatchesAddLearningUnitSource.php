<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAiGenerationBatchesAddLearningUnitSource extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('ai_generation_batches', [

            'source_type' => [
                'name'       => 'source_type',
                'type'       => 'ENUM',
                'constraint' => ['topic', 'text', 'file', 'learning_unit'],
                'null'       => false,
            ],

        ]);

        $this->forge->addColumn('ai_generation_batches', [

            'learning_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'source_file_id',
                'comment'    => 'Set when source_type is learning_unit — the unit whose readings/PDFs were extracted into source_text',
            ],

        ]);

        // SET NULL, not CASCADE: deleting a unit shouldn't take the batch
        // (and its already-accepted question history) down with it.
        $this->db->query("
            ALTER TABLE ai_generation_batches
            ADD CONSTRAINT fk_ai_generation_batch_learning_unit
            FOREIGN KEY (learning_unit_id)
            REFERENCES learning_units(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE
        ");
    }

    public function down()
    {
        $this->db->query('ALTER TABLE ai_generation_batches DROP FOREIGN KEY fk_ai_generation_batch_learning_unit');

        $this->forge->dropColumn('ai_generation_batches', 'learning_unit_id');

        $this->forge->modifyColumn('ai_generation_batches', [

            'source_type' => [
                'name'       => 'source_type',
                'type'       => 'ENUM',
                'constraint' => ['topic', 'text', 'file'],
                'null'       => false,
            ],

        ]);
    }
}

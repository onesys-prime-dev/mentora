<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the question builder knew about each question it wrote: why the answer
 * is right, the line of the source it came from (and its page and title), and
 * how it was checked. Kept so the review tray can show a teacher where a
 * question came from before they accept it.
 */
class AddEvidenceToAiGeneratedQuestions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generated_questions', [
            'explanation' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'Why the marked answer is right, as written by the builder',
            ],
            'evidence_quote' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'The line of the source the question was written from',
            ],
            'source_title' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'source_page' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'verification' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON: solver_agrees, grounded, regenerated',
            ],
            'flags' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON list of short flags the builder raised',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generated_questions', ['explanation', 'evidence_quote', 'source_title', 'source_page', 'verification', 'flags']);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An attempt keeps the questions it started with (QA AMB-ASM-03): the questions, their choices and
 * the answer key, as they were when the attempt began. An instructor's edit while the attempt runs
 * does not change what the student sees or how the attempt is scored. Attempts from before this
 * migration have none and use the quiz as it is now.
 */
class AttemptKeepsItsQuestions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('assessment_submissions', [
            'questions_snapshot' => ['type' => 'LONGTEXT', 'null' => true, 'after' => 'rubric_scores'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('assessment_submissions', 'questions_snapshot');
    }
}

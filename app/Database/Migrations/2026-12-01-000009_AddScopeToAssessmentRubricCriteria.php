<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A rubric criterion is scored once for the whole group ('group') or for each member ('individual').
 * Existing criteria default to 'individual', so rubrics written before groups behave exactly as they did.
 */
class AddScopeToAssessmentRubricCriteria extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_rubric_criteria', [
            'scope' => ['type' => 'ENUM', 'constraint' => ['group', 'individual'], 'default' => 'individual', 'null' => false, 'after' => 'max_points'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assessment_rubric_criteria', 'scope');
    }
}

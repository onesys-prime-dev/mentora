<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The number of units an instructor teaches in a term from which the dean's teaching-load page marks
 * them as carrying a high load. Another of the institution's shared thresholds
 * (institution_academic_defaults): optional, and an institution that sets nothing gets the platform's.
 */
class AddHighLoadUnitsToAcademicDefaults extends Migration
{
    public function up()
    {
        $this->forge->addColumn('institution_academic_defaults', [
            'high_load_units' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true, 'after' => 'overdue_grading_days'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('institution_academic_defaults', 'high_load_units');
    }
}

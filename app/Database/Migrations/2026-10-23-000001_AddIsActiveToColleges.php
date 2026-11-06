<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Colleges could not be switched off: departments have `is_active` and
 * programs have a `status`, colleges had neither. The institutional
 * administrator deactivates a college that is no longer used (it keeps its
 * history; nothing is deleted), so it gets the same flag as departments.
 * Every existing college stays active.
 */
class AddIsActiveToColleges extends Migration
{
    public function up()
    {
        if (\Config\Database::connect($this->DBGroup)->fieldExists('is_active', 'colleges')) {
            return;
        }

        $this->forge->addColumn('colleges', [
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'after' => 'established_year'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('colleges', 'is_active');
    }
}

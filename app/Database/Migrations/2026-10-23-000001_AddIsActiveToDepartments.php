<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Departments could not be switched off: sub-departments have `is_active` and
 * programs have a `status`, departments had neither. The institutional
 * administrator deactivates a department that is no longer used (it keeps its
 * history; nothing is deleted), so it gets the same flag as sub-departments.
 * Every existing department stays active.
 */
class AddIsActiveToDepartments extends Migration
{
    public function up()
    {
        $this->forge->addColumn('departments', [
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'after' => 'established_year'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('departments', 'is_active');
    }
}

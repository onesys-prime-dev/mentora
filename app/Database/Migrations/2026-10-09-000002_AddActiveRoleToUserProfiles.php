<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A multi-role user (e.g. instructor + chairperson) has one role "active" at
 * a time. This is what makes that choice durable across logins — the session
 * holds it for the current visit, this column is what seeds the session on
 * the next one.
 */
class AddActiveRoleToUserProfiles extends Migration
{
    public function up()
    {
        $this->forge->addColumn('user_profiles', [
            'active_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'program_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('user_profiles', 'active_role');
    }
}

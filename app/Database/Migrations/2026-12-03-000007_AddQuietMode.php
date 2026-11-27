<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: quiet hours for everyone as a three-way choice (FR9). `quiet_mode` is NULL (never set: follow the school
 * default), 'own' (the person's times) or 'none'. Anyone who already set both times keeps them as 'own' (BC-3).
 */
class AddQuietMode extends Migration
{
    public function up()
    {
        $this->forge->addColumn('message_preferences', [
            'quiet_mode' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
        ]);
        $this->db->query('UPDATE message_preferences SET quiet_mode = ? WHERE quiet_start IS NOT NULL AND quiet_end IS NOT NULL', ['own']);
    }

    public function down()
    {
        $this->forge->dropColumn('message_preferences', 'quiet_mode');
    }
}

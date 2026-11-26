<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Messages: first contact (a thread the recipient has not yet accepted) and the email scope preference.
 * No backfill: existing threads are not first contacts, and email_alerts = 0 stays the source of "off".
 */
class AddFirstContactAndEmailScope extends Migration
{
    public function up()
    {
        $this->forge->addColumn('conversations', [
            'first_contact'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            'first_contact_cleared_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addColumn('message_preferences', [
            'email_scope' => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'everyone', 'null' => false],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('conversations', ['first_contact', 'first_contact_cleared_at']);
        $this->forge->dropColumn('message_preferences', 'email_scope');
    }
}

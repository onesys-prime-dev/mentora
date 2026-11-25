<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** An administrator's or import's change of someone's sign-in email, and an administrator clearing a profile field, are emailed through the outbox. */
class AddProfileKindsToMailOutbox extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE mail_outbox MODIFY kind ENUM('welcome', 'enrolled', 'teaching', 'dean', 'chairperson', 'group', 'photo', 'email_changed', 'field_cleared') NOT NULL");
    }

    public function down()
    {
        // The old ENUM cannot hold these rows.
        $this->db->table('mail_outbox')->whereIn('kind', ['email_changed', 'field_cleared'])->delete();
        $this->db->query("ALTER TABLE mail_outbox MODIFY kind ENUM('welcome', 'enrolled', 'teaching', 'dean', 'chairperson', 'group', 'photo') NOT NULL");
    }
}

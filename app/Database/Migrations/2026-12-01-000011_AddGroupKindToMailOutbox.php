<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Group activity news (D19) is emailed through the outbox as its own kind. */
class AddGroupKindToMailOutbox extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE mail_outbox MODIFY kind ENUM('welcome', 'enrolled', 'teaching', 'dean', 'chairperson', 'group') NOT NULL");
    }

    public function down()
    {
        // The old ENUM cannot hold these rows.
        $this->db->table('mail_outbox')->where('kind', 'group')->delete();
        $this->db->query("ALTER TABLE mail_outbox MODIFY kind ENUM('welcome', 'enrolled', 'teaching', 'dean', 'chairperson') NOT NULL");
    }
}

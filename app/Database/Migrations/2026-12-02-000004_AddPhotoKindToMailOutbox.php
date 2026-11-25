<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** An administrator removing or adding someone's profile photo is emailed through the outbox as its own kind. */
class AddPhotoKindToMailOutbox extends Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE mail_outbox MODIFY kind ENUM('welcome', 'enrolled', 'teaching', 'dean', 'chairperson', 'group', 'photo') NOT NULL");
    }

    public function down()
    {
        // The old ENUM cannot hold these rows.
        $this->db->table('mail_outbox')->where('kind', 'photo')->delete();
        $this->db->query("ALTER TABLE mail_outbox MODIFY kind ENUM('welcome', 'enrolled', 'teaching', 'dean', 'chairperson', 'group') NOT NULL");
    }
}

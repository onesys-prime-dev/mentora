<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** User Profile rebuild: the optional profile fields, the photo and home department, and the institution's photo switch. */
class AddProfileColumns extends Migration
{
    public function up()
    {
        $this->forge->addColumn('user_profiles', [
            'preferred_first_name'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'pronouns'               => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'bio'                    => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'office_location'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'consultation_hours'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'work_phone'             => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'photo_file_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'home_department_id'     => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'two_factor_opt_in'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'checklist_dismissed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $profiles = $this->db->prefixTable('user_profiles');
        $this->db->query("ALTER TABLE {$profiles}"
            . ' ADD CONSTRAINT fk_profile_photo FOREIGN KEY (photo_file_id) REFERENCES ' . $this->db->prefixTable('files') . ' (id) ON DELETE SET NULL ON UPDATE CASCADE,'
            . ' ADD CONSTRAINT fk_profile_home_department FOREIGN KEY (home_department_id) REFERENCES ' . $this->db->prefixTable('departments') . ' (id) ON DELETE SET NULL ON UPDATE CASCADE');

        $this->forge->addColumn('institutions', [
            'profile_photos_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('institutions', 'profile_photos_enabled');

        $profiles = $this->db->prefixTable('user_profiles');
        $this->db->query("ALTER TABLE {$profiles} DROP FOREIGN KEY fk_profile_photo, DROP FOREIGN KEY fk_profile_home_department");
        $this->forge->dropColumn('user_profiles', [
            'preferred_first_name', 'pronouns', 'bio', 'office_location', 'consultation_hours', 'work_phone',
            'photo_file_id', 'home_department_id', 'two_factor_opt_in', 'checklist_dismissed_at',
        ]);
    }
}

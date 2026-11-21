<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * student_groups.config_id and student_group_members.config_id have existed since Phase 1 without a key;
 * now that activity_group_configs exists, deleting a config removes its groups and members.
 */
class AddConfigForeignKeysToGroups extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE student_groups ADD CONSTRAINT fk_student_groups_config FOREIGN KEY (config_id) REFERENCES activity_group_configs (id) ON DELETE CASCADE ON UPDATE CASCADE');
        $this->db->query('ALTER TABLE student_group_members ADD CONSTRAINT fk_student_group_members_config FOREIGN KEY (config_id) REFERENCES activity_group_configs (id) ON DELETE CASCADE ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE student_group_members DROP FOREIGN KEY fk_student_group_members_config');
        $this->db->query('ALTER TABLE student_groups DROP FOREIGN KEY fk_student_groups_config');
    }
}

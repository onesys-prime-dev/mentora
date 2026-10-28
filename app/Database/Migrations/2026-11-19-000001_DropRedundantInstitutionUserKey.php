<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One institution per account (D1) put a unique key on `institution_users.user_id`, which makes the older
 * `institution_user_unique (institution_id, user_id)` redundant: no pair can repeat when a person appears once.
 * The foreign key on `institution_id` keeps its own index (`institution_id`).
 */
class DropRedundantInstitutionUserKey extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('institution_users') . ' DROP INDEX institution_user_unique');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('institution_users') . ' ADD UNIQUE KEY institution_user_unique (institution_id, user_id)');
    }
}

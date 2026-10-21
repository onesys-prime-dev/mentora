<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets an invitation to a parent name the students they will be linked to.
 *
 * A parent has no account until they accept, and a link needs an account, so
 * the administrator's list is checked when the invitation is sent and kept
 * here as a JSON list of student user ids; the links are made in the same
 * transaction that makes the account. Empty (NULL) for every other invitation.
 */
class AddStudentsToInstitutionInvitations extends Migration
{
    public function up()
    {
        $this->forge->addColumn('institution_invitations', [
            'student_ids' => ['type' => 'TEXT', 'null' => true, 'after' => 'last_name'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('institution_invitations', 'student_ids');
    }
}

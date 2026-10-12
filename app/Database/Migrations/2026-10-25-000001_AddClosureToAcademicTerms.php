<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Closing a term (institutional administrator) completes its offerings and
 * enrolments; to be able to reopen it exactly, the term remembers who closed
 * it, when, and which offerings and enrolments the close changed (as JSON ids)
 * so that work already completed or archived beforehand is left alone.
 * Terms completed any other way have no snapshot and cannot be reopened.
 */
class AddClosureToAcademicTerms extends Migration
{
    public function up()
    {
        $this->forge->addColumn('academic_terms', [
            'closed_at'          => ['type' => 'DATETIME', 'null' => true],
            'closed_by_user_id'  => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'close_snapshot'     => ['type' => 'MEDIUMTEXT', 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('academic_terms', ['closed_at', 'closed_by_user_id', 'close_snapshot']);
    }
}

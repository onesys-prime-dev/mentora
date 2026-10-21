<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The dean's sign-off of an offering's finalized grades (GradeSignoffService).
 *
 * `open_marker` is 1 while the sign-off stands and NULL once the dean has
 * reopened it; the unique key over (offering, marker) is how an offering has at
 * most one current sign-off, while any number of ended ones stay as history,
 * without a database-specific partial index.
 */
class CreateGradeSignoffsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'offering_id' => ['type' => 'INT', 'unsigned' => true],
            'signed_by' => ['type' => 'INT', 'unsigned' => true],
            'signed_at' => ['type' => 'DATETIME'],
            'reopened_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reopened_at' => ['type' => 'DATETIME', 'null' => true],
            'reopen_reason' => ['type' => 'TEXT', 'null' => true],
            'open_marker' => ['type' => 'TINYINT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['offering_id', 'open_marker']);
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('signed_by', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reopened_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('grade_signoffs');
    }

    public function down()
    {
        $this->forge->dropTable('grade_signoffs');
    }
}

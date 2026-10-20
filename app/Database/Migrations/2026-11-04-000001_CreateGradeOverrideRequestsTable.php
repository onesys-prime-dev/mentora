<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A request to override a student's final grade. The calculated grade stays in
 * force until the request is approved (see GradeOverrideRequestService).
 *
 * `open_marker` is 1 while a request is open (waiting for someone, or sent back
 * to its requester) and NULL once it is decided or withdrawn; the unique key over
 * (offering, student, marker) is how a student has at most one open request per
 * offering without a database-specific partial index.
 *
 * `requested_percentage` NULL means "remove the current override".
 */
class CreateGradeOverrideRequestsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'offering_id' => ['type' => 'INT', 'unsigned' => true],
            'student_id' => ['type' => 'INT', 'unsigned' => true],
            'requested_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'calculated_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'previous_percentage' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'reason' => ['type' => 'TEXT'],
            'requested_by' => ['type' => 'INT', 'unsigned' => true],
            'requester_role' => ['type' => 'VARCHAR', 'constraint' => 20],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24],
            'open_marker' => ['type' => 'TINYINT', 'null' => true],
            'endorsed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'endorsed_at' => ['type' => 'DATETIME', 'null' => true],
            'endorse_comment' => ['type' => 'TEXT', 'null' => true],
            'returned_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'returned_at' => ['type' => 'DATETIME', 'null' => true],
            'return_comment' => ['type' => 'TEXT', 'null' => true],
            'decided_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'decided_at' => ['type' => 'DATETIME', 'null' => true],
            'decision_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addKey('requested_by');
        $this->forge->addUniqueKey(['offering_id', 'student_id', 'open_marker']);
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('requested_by', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('endorsed_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('returned_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('decided_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('grade_override_requests');
    }

    public function down()
    {
        $this->forge->dropTable('grade_override_requests');
    }
}

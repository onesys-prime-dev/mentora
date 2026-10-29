<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Follow-up requests (FollowUpService): a dean asks a chairperson or instructor of the
 * department to act on something, or a chairperson asks an instructor of their
 * sub-departments, and the two talk it through in a small reply thread.
 *
 * `open_marker` is 1 while a request is open and NULL once it is done. The unique key over
 * (sender, recipient, subject, marker) is how the same subject cannot be asked twice of the
 * same person while the first is open, while any number of done ones stay as history, without a
 * database-specific partial index. `student_id` is 0 (not NULL) for a request about a whole
 * course, because a NULL would let a unique key repeat.
 *
 * `parent_id` links a request a chairperson passed down to the dean's request it came from,
 * so the dean still sees where it went.
 */
class CreateFollowUpsTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'institution_id' => ['type' => 'INT', 'unsigned' => true],
            'parent_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sender_id' => ['type' => 'INT', 'unsigned' => true],
            'sender_role' => ['type' => 'VARCHAR', 'constraint' => 20],
            'recipient_id' => ['type' => 'INT', 'unsigned' => true],
            'recipient_role' => ['type' => 'VARCHAR', 'constraint' => 20],
            'offering_id' => ['type' => 'INT', 'unsigned' => true],
            'subject_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'student_id' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'other'],
            'title' => ['type' => 'VARCHAR', 'constraint' => 200],
            'message' => ['type' => 'TEXT'],
            'due_date' => ['type' => 'DATE', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'open'],
            'open_marker' => ['type' => 'TINYINT', 'null' => true],
            'done_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'done_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['recipient_id', 'status']);
        $this->forge->addKey(['sender_id', 'status']);
        $this->forge->addKey('parent_id');
        $this->forge->addUniqueKey(['sender_id', 'recipient_id', 'subject_type', 'offering_id', 'student_id', 'open_marker'], 'follow_ups_one_open');
        $this->forge->addForeignKey('institution_id', 'institutions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('sender_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('recipient_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('parent_id', 'follow_ups', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('follow_ups');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'follow_up_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'body' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('follow_up_id');
        $this->forge->addForeignKey('follow_up_id', 'follow_ups', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('follow_up_replies');
    }

    public function down()
    {
        $this->forge->dropTable('follow_up_replies');
        $this->forge->dropTable('follow_ups');
    }
}

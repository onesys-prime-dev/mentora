<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A dean's "I have looked at this" mark on one thing a chairperson did in a course
 * (ChairpersonReviewService). The marks are the dean's own: a second dean, or the next
 * one, starts with none. A mark changes nothing about the change it marks; the audit
 * entry itself is never touched.
 */
class CreateChairpersonActionReviewsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'audit_log_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'reviewer_id' => ['type' => 'INT', 'unsigned' => true],
            'reviewed_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['audit_log_id', 'reviewer_id']);
        $this->forge->addKey('reviewer_id');
        $this->forge->addForeignKey('audit_log_id', 'audit_logs', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reviewer_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('chairperson_action_reviews');
    }

    public function down()
    {
        $this->forge->dropTable('chairperson_action_reviews');
    }
}

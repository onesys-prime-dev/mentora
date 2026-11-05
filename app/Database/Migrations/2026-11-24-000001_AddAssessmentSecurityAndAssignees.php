<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Settings that are really enforced (an earlier rule: a setting nothing acts on is not stored).
 *
 *  - assessment_settings.detect_tab_switch: record when a student leaves the quiz page (when, for how long). A web
 *    page cannot see WHERE they went, so only that they left is recorded.
 *  - assessment_settings.assign_to_specific_students and assessment_assignees: an assessment given only to the
 *    students listed, not to everyone enrolled.
 *  - assessment_security_events: what the quiz page saw during an attempt (left the page, left fullscreen, tried to
 *    copy, paste, print or open a menu), shown to the instructor with the attempt.
 *
 * `password` (existing) holds a hash of the quiz password, never the password itself; `randomize_questions`,
 * `require_password` and `browser_lockdown` (existing, until now unused) are now acted on.
 */
class AddAssessmentSecurityAndAssignees extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_settings', [
            'detect_tab_switch'           => ['type' => 'BOOLEAN', 'default' => false],
            'assign_to_specific_students' => ['type' => 'BOOLEAN', 'default' => false],
        ]);

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'assessment_id'   => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('student_user_id');
        $this->forge->addUniqueKey(['assessment_id', 'student_user_id'], 'uq_assessment_assignee');
        $this->forge->addForeignKey('assessment_id', 'assessments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('assessment_assignees');

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'submission_id'    => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'kind'             => ['type' => 'ENUM', 'constraint' => ['left_page', 'left_fullscreen', 'blocked_action']],
            'detail'           => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'duration_seconds' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'occurred_at'      => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['submission_id', 'occurred_at']);
        $this->forge->addForeignKey('submission_id', 'assessment_submissions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('assessment_security_events');
    }

    public function down()
    {
        $this->forge->dropTable('assessment_security_events', true);
        $this->forge->dropTable('assessment_assignees', true);
        $this->forge->dropColumn('assessment_settings', ['detect_tab_switch', 'assign_to_specific_students']);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Wrong quiz passwords, kept so that guessing is slowed down: a student who gets it wrong five times in ten
 * minutes is locked out of that quiz for the rest of the window. A right password clears their failures.
 */
class CreateQuizPasswordFailures extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'assessment_id'   => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'student_user_id' => ['type' => 'INT', 'constraint' => 20, 'unsigned' => true],
            'failed_at'       => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['assessment_id', 'student_user_id', 'failed_at'], false, false, 'idx_quiz_password_failures');
        $this->forge->addForeignKey('assessment_id', 'assessments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('quiz_password_failures');
    }

    public function down()
    {
        $this->forge->dropTable('quiz_password_failures', true);
    }
}

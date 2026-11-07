<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What each viewer last saw of a student's grade in each course (GradesSeen):
 * a hash of the grade as it stood, kept so the Grades page can badge a course
 * "Changed" once, the next time it is opened.
 */
class CreateGradesSeenTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'viewer_user_id' => ['type' => 'INT', 'unsigned' => true],
            'student_user_id' => ['type' => 'INT', 'unsigned' => true],
            'offering_id' => ['type' => 'INT', 'unsigned' => true],
            'signature' => ['type' => 'VARCHAR', 'constraint' => 64],
            'seen_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['viewer_user_id', 'student_user_id', 'offering_id']);
        $this->forge->addForeignKey('viewer_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('student_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('offering_id', 'course_offerings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('grades_seen');
    }

    public function down()
    {
        $this->forge->dropTable('grades_seen');
    }
}

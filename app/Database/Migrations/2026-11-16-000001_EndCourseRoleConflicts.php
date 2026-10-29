<?php

namespace App\Database\Migrations;

use App\Services\Courses\CourseRoleConflicts;
use CodeIgniter\Database\Migration;

/**
 * Existing student-and-staff conflicts are fixed (audit decisions 9.6, 9.7).
 *
 * Every current enrolment that CourseRoleConflicts now refuses (the person
 * teaches, chairs or leads the course, or administers its institution), and
 * every completed one taken while teaching the course that same term, is ended
 * (a former student who is staff later keeps their record, D10):
 * status `dropped`, its work and grades kept, as an enrolment is never deleted.
 * The staff role stays. Each one is audited as the system.
 *
 * down() changes nothing back: a dropped enrolment can be reinstated by hand,
 * and the guard then checks it.
 */
class EndCourseRoleConflicts extends Migration
{
    public function up(): void
    {
        self::endConflicts();
    }

    public function down(): void
    {
    }

    /** @return int how many enrolments were ended */
    public static function endConflicts(): int
    {
        $db        = db_connect();
        $conflicts = new CourseRoleConflicts();
        $ended     = 0;

        $rows = $db->table('enrollments')->select('id, offering_id, student_user_id, status')->whereIn('status', ['enrolled', 'completed'])->get()->getResultArray();

        foreach ($rows as $row) {
            // A completed enrolment is history: only teaching the course that same term clashes with it (D10).
            $reason = $conflicts->forExistingEnrolment((int) $row['student_user_id'], (int) $row['offering_id'], (string) $row['status']);

            if ($reason === null) {
                continue;
            }

            $db->table('enrollments')->where('id', $row['id'])->update(['status' => 'dropped', 'updated_at' => date('Y-m-d H:i:s')]);
            $ended++;

            service('auditLogger')->log('enrolment.status_changed', null, [
                'actor_label' => 'system', 'acting_role' => 'system', 'target_type' => 'enrollment', 'target_id' => (int) $row['id'],
                'old' => ['status' => $row['status']],
                'new' => ['status' => 'dropped', 'reason' => 'course role conflict: ' . $reason, 'offering_id' => (int) $row['offering_id'], 'student_user_id' => (int) $row['student_user_id']],
            ]);
        }

        return $ended;
    }
}

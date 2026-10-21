<?php

declare(strict_types=1);

use App\Models\Courses\EnrollmentModel;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Every enrolment of a student, for the places that must show history (a
 * parent sees a dropped or finished course too), while the ordinary lists of
 * the student's own pages stay exactly as they were.
 *
 * @internal
 */
final class EnrollmentHistoryTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function enrolled(string $section, string $enrolment, string $offeringStatus = 'active', $student = null)
    {
        $student ??= CourseFactory::user('student');
        $offering  = CourseFactory::offering($section);

        db_connect()->table('course_offerings')->where('id', $offering)->update(['status' => $offeringStatus]);
        CourseFactory::enroll($offering, $student, $enrolment);

        return [$student, $offering];
    }

    public function testHistoryReturnsEveryEnrolmentWithItsStatuses(): void
    {
        [$student, $current] = $this->enrolled('A', 'enrolled');
        [, $dropped]         = $this->enrolled('B', 'dropped', 'active', $student);
        [, $withdrawn]       = $this->enrolled('C', 'withdrawn', 'active', $student);
        [, $completed]       = $this->enrolled('D', 'completed', 'completed', $student);
        [, $oldTerm]         = $this->enrolled('E', 'enrolled', 'completed', $student);

        $rows = (new EnrollmentModel())->retrieveStudentCourseOfferings((int) $student->id, false, true);

        $byOffering = array_column($rows, null, 'offering_id');

        $this->assertCount(5, $rows);
        $this->assertSame('enrolled', $byOffering[$current]['enrollment_status']);
        $this->assertSame('dropped', $byOffering[$dropped]['enrollment_status']);
        $this->assertSame('withdrawn', $byOffering[$withdrawn]['enrollment_status']);
        $this->assertSame('completed', $byOffering[$completed]['enrollment_status']);
        $this->assertSame('completed', $byOffering[$oldTerm]['offering_status']);
        $this->assertArrayHasKey('instructor_name', $byOffering[$current]);
    }

    public function testTheOrdinaryListsAreUnchanged(): void
    {
        [$student, $current] = $this->enrolled('A', 'enrolled');
        $this->enrolled('B', 'dropped', 'active', $student);
        $this->enrolled('C', 'withdrawn', 'active', $student);
        [, $completed] = $this->enrolled('D', 'completed', 'completed', $student);

        $model = new EnrollmentModel();

        $this->assertSame([$current], array_map('intval', array_column($model->retrieveStudentCourseOfferings((int) $student->id), 'offering_id')));
        $this->assertSame([$completed], array_map('intval', array_column($model->retrieveStudentCourseOfferings((int) $student->id, true), 'offering_id')));
    }

    public function testAnotherStudentsEnrolmentsAreNeverIncluded(): void
    {
        [$mine]   = $this->enrolled('A', 'enrolled');
        [$theirs] = $this->enrolled('B', 'enrolled');

        $rows = (new EnrollmentModel())->retrieveStudentCourseOfferings((int) $mine->id, false, true);

        $this->assertCount(1, $rows);
        $this->assertNotSame((int) $theirs->id, (int) $mine->id);
    }
}

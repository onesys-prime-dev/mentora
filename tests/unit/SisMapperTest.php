<?php

declare(strict_types=1);

use App\Services\Sis\SisMapper;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * How a SIS record becomes Mentora values, and how "what changed" is decided.
 *
 * @internal
 */
final class SisMapperTest extends CIUnitTestCase
{
    public function testAStaffAssignmentTakesItsTargetFromTheColumnOfItsRole(): void
    {
        $dean       = SisMapper::staffAssignment(['role' => 'Dean', 'person_external_id' => 'P1', 'department_code' => 'CCS', 'sub_department_code' => 'IT', 'course_external_id' => 'C1', 'section' => 'B']);
        $chair      = SisMapper::staffAssignment(['role' => 'chairperson', 'person_external_id' => 'P2', 'department_code' => 'CCS', 'sub_department_code' => 'IT', 'course_external_id' => 'C1', 'section' => 'B']);
        $instructor = SisMapper::staffAssignment(['role' => 'INSTRUCTOR', 'person_external_id' => 'P3', 'department_code' => 'CCS', 'sub_department_code' => 'IT', 'course_external_id' => 'C1', 'section' => 'B']);

        $this->assertSame(['role' => 'dean', 'person_external_id' => 'P1', 'target' => 'CCS', 'section' => 'A'], $dean, 'a section means nothing for a dean');
        $this->assertSame(['role' => 'chairperson', 'person_external_id' => 'P2', 'target' => 'IT', 'section' => 'A'], $chair);
        $this->assertSame(['role' => 'instructor', 'person_external_id' => 'P3', 'target' => 'C1', 'section' => 'B'], $instructor);
    }

    public function testAnInstructorWithNoSectionTeachesSectionA(): void
    {
        $this->assertSame('A', SisMapper::staffAssignment(['role' => 'instructor', 'person_external_id' => 'P3', 'course_external_id' => 'C1', 'section' => ''])['section']);
        $this->assertSame('A', SisMapper::staffAssignment(['role' => 'instructor', 'person_external_id' => 'P3', 'course_external_id' => 'C1'])['section']);
    }

    public function testAPersonIsMappedWithALowerCasedEmailAndAnOptionalStudentNumber(): void
    {
        $this->assertSame(
            ['external_id' => 'S1', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'student_number' => '2026-001'],
            SisMapper::person(['external_id' => 'S1', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'Ann@Example.COM', 'student_number' => '2026-001']),
        );

        $this->assertNull(SisMapper::person(['external_id' => 'S1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.example', 'student_number' => ''])['student_number']);
        $this->assertNull(SisMapper::person(['external_id' => 'I1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.example'])['student_number']);
    }

    public function testACourseKeepsUnitsToOneDecimalAndEmptyMeansNotSaid(): void
    {
        $course = SisMapper::course(['external_id' => 'C1', 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => '2.75', 'program_code' => 'BSIT']);

        $this->assertSame(2.8, $course['units']);
        $this->assertSame('BSIT', $course['program_code']);

        $bare = SisMapper::course(['external_id' => 'C1', 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => '', 'program_code' => '']);

        $this->assertNull($bare['units']);
        $this->assertNull($bare['program_code']);
    }

    public function testAnEnrollmentDefaultsToSectionAAndEnrolled(): void
    {
        $this->assertSame(
            ['course_external_id' => 'C1', 'student_external_id' => 'S1', 'section' => 'A', 'status' => 'enrolled'],
            SisMapper::enrollment(['course_external_id' => 'C1', 'student_external_id' => 'S1', 'section' => '', 'status' => '']),
        );

        $this->assertSame('dropped', SisMapper::enrollment(['course_external_id' => 'C1', 'student_external_id' => 'S1', 'section' => 'B', 'status' => 'Dropped'])['status']);
        $this->assertSame('B', SisMapper::enrollment(['course_external_id' => 'C1', 'student_external_id' => 'S1', 'section' => 'B'])['section']);
    }

    public function testChangesListsOnlyWhatDiffers(): void
    {
        $current  = ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com'];
        $incoming = ['first_name' => 'Anna', 'last_name' => 'Lee', 'email' => 'ann@example.com'];

        $this->assertSame(['first_name' => 'Anna'], SisMapper::changes($current, $incoming, ['first_name', 'last_name', 'email']));
        $this->assertSame([], SisMapper::changes($current, $current, ['first_name', 'last_name', 'email']));
    }

    public function testAFieldTheSisLeftEmptyIsNotAChange(): void
    {
        $this->assertSame([], SisMapper::changes(['student_number' => '2026-001'], ['student_number' => null], ['student_number']));
        $this->assertSame(['student_number' => '2026-009'], SisMapper::changes(['student_number' => '2026-001'], ['student_number' => '2026-009'], ['student_number']));
    }

    public function testNumbersCompareAsNumbers(): void
    {
        $this->assertSame([], SisMapper::changes(['units' => '3.0'], ['units' => 3.0], ['units']));
        $this->assertSame(['units' => 4.0], SisMapper::changes(['units' => '3.0'], ['units' => 4.0], ['units']));
    }

    public function testAFieldMissingFromTheCurrentRowCountsAsChanged(): void
    {
        $this->assertSame(['first_name' => 'Ann'], SisMapper::changes([], ['first_name' => 'Ann'], ['first_name']));
    }
}

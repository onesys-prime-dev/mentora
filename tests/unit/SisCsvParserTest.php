<?php

declare(strict_types=1);

use App\Services\Sis\SisBatch;
use App\Services\Sis\SisCsvParser;
use App\Services\Sis\SisException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * How a SIS export is read: good rows come through, each bad row is rejected
 * with its row number and reason, and a file that cannot be used at all is
 * refused whole.
 *
 * @internal
 */
final class SisCsvParserTest extends CIUnitTestCase
{
    public function testGoodStudentRowsComeThroughWithOnlyTheKnownColumns(): void
    {
        $batch = SisCsvParser::parse('students', "external_id,email,first_name,last_name,student_number,favourite_colour\nS1,ann@example.com,Ann,Lee,2026-001,blue\nS2,ben@example.com,Ben,Ng,,red\n");

        $this->assertInstanceOf(SisBatch::class, $batch);
        $this->assertSame('students', $batch->dataset);
        $this->assertSame([], $batch->rejected);
        $this->assertSame(2, $batch->total());
        $this->assertSame(['external_id' => 'S1', 'email' => 'ann@example.com', 'first_name' => 'Ann', 'last_name' => 'Lee', 'student_number' => '2026-001'], $batch->records[0]);
        $this->assertSame('', $batch->records[1]['student_number']);
    }

    public function testHeadersAreMatchedLoosely(): void
    {
        $batch = SisCsvParser::parse('students', "\xEF\xBB\xBFExternal ID, EMAIL ,First Name,LAST_NAME\r\nS1,ann@example.com,Ann,Lee\r\n");

        $this->assertCount(1, $batch->records);
        $this->assertSame('S1', $batch->records[0]['external_id']);
    }

    public function testEachBadRowIsRejectedWithItsRowNumberAndReason(): void
    {
        $csv = implode("\n", [
            'external_id,email,first_name,last_name',
            'S1,ann@example.com,Ann,Lee',        // row 2: good
            'S2,not-an-email,Ben,Ng',            // row 3
            ',cy@example.com,Cy,Ho',             // row 4
            'S1,dup@example.com,Dee,Ko',         // row 5: repeats row 2
            'S5,eve@example.com,Eve',            // row 6: short
            '',                                  // blank, skipped
            'S6,fay@example.com,Fay,Wu',         // row 8: good
        ]);

        $batch = SisCsvParser::parse('students', $csv);

        $this->assertSame(['S1', 'S6'], array_column($batch->records, 'external_id'));
        $this->assertSame([3, 4, 5, 6], array_column($batch->rejected, 'row'));
        $this->assertStringContainsString('email', $batch->rejected[0]['reason']);
        $this->assertStringContainsString('external_id is empty', $batch->rejected[1]['reason']);
        $this->assertStringContainsString('Repeats row 2', $batch->rejected[2]['reason']);
        $this->assertStringContainsString('Expected 4 columns, found 3', $batch->rejected[3]['reason']);
    }

    public function testCoursesCheckUnits(): void
    {
        $batch = SisCsvParser::parse('courses', "external_id,course_code,course_title,units\nC1,IT101,Intro,3\nC2,IT102,Next,abc\nC3,IT103,Big,150\nC4,IT104,Free,\n");

        $this->assertSame(['C1', 'C4'], array_column($batch->records, 'external_id'));
        $this->assertSame([3, 4], array_column($batch->rejected, 'row'));
    }

    public function testEnrollmentsAreUniquePerCourseStudentAndSectionAndCheckStatus(): void
    {
        $batch = SisCsvParser::parse('enrollments', "course_external_id,student_external_id,section,status\nC1,S1,A,enrolled\nC1,S1,B,Enrolled\nC1,S1,A,enrolled\nC1,S2,A,gone\n");

        $this->assertCount(2, $batch->records);
        $this->assertSame([4, 5], array_column($batch->rejected, 'row'));
        $this->assertStringContainsString('status must be one of', $batch->rejected[1]['reason']);
    }

    public function testATooLongValueIsRejected(): void
    {
        $batch = SisCsvParser::parse('instructors', "external_id,email,first_name,last_name\nI1,i@example.com," . str_repeat('x', 300) . ",Lee\n");

        $this->assertSame([], $batch->records);
        $this->assertStringContainsString('255', $batch->rejected[0]['reason']);
    }

    public function testQuotedCellsKeepTheirCommasAndNewlines(): void
    {
        $batch = SisCsvParser::parse('courses', "external_id,course_code,course_title\nC1,IT101,\"Intro, part\n1\"\n");

        $this->assertSame("Intro, part\n1", $batch->records[0]['course_title']);
    }

    public function testAMissingRequiredColumnRefusesTheWholeFile(): void
    {
        $this->expectException(SisException::class);
        $this->expectExceptionMessage('missing the column(s): email, last_name');

        SisCsvParser::parse('students', "external_id,first_name\nS1,Ann\n");
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(SisException::class);
        $this->expectExceptionMessage('empty');

        SisCsvParser::parse('students', '');
    }

    public function testTooManyRowsAreRefused(): void
    {
        $rows = "external_id,course_code,course_title\n" . str_repeat("C,IT,T\n", SisCsvParser::MAX_ROWS + 1);

        $this->expectException(SisException::class);
        $this->expectExceptionMessage('more than');

        SisCsvParser::parse('courses', $rows);
    }

    public function testAnUnknownDatasetIsRefused(): void
    {
        $this->expectException(SisException::class);

        SisCsvParser::parse('grades', "a\n1\n");
    }

    public function testTheDatasetsAreTheInboundOnes(): void
    {
        $this->assertSame(['students', 'instructors', 'courses', 'enrollments', 'staff_assignments', 'class_schedules'], SisCsvParser::datasets());
    }

    private const STAFF = "role,person_external_id,department_code,sub_department_code,course_external_id,section\n";

    public function testEachStaffRoleNeedsTheColumnThatNamesWhatItLeadsOrTeaches(): void
    {
        $batch = SisCsvParser::parse('staff_assignments', self::STAFF
            . "dean,P1,CCS,,,\n"
            . "chairperson,P2,,IT,,\n"
            . "instructor,P3,,,C1,B\n"
            . "Dean,P4,CCS,,,\n");

        $this->assertSame([], $batch->rejected);
        $this->assertCount(4, $batch->records);
        $this->assertSame('CCS', $batch->records[0]['department_code']);
        $this->assertSame('IT', $batch->records[1]['sub_department_code']);
        $this->assertSame(['C1', 'B'], [$batch->records[2]['course_external_id'], $batch->records[2]['section']]);
    }

    public function testAStaffRowThatLacksItsTargetOrHasAnUnknownRoleIsRejectedWithItsReason(): void
    {
        $batch = SisCsvParser::parse('staff_assignments', self::STAFF
            . "dean,P1,,,,\n"
            . "chairperson,P2,,,,\n"
            . "instructor,P3,,,,\n"
            . "registrar,P4,CCS,,,\n"
            . ",P5,CCS,,,\n"
            . "dean,,CCS,,,\n");

        $this->assertSame([], $batch->records);
        $this->assertSame([
            ['row' => 2, 'reason' => 'department_code is required for a dean.'],
            ['row' => 3, 'reason' => 'sub_department_code is required for a chairperson.'],
            ['row' => 4, 'reason' => 'course_external_id is required for an instructor.'],
            ['row' => 5, 'reason' => 'role must be one of: dean, chairperson, instructor.'],
            ['row' => 6, 'reason' => 'role is empty.'],
            ['row' => 7, 'reason' => 'person_external_id is empty.'],
        ], $batch->rejected);
    }

    public function testTheSamePersonMayLeadSeveralThingsButNotTheSameOneTwice(): void
    {
        $batch = SisCsvParser::parse('staff_assignments', self::STAFF
            . "chairperson,P1,,IT,,\n"
            . "chairperson,P1,,NET,,\n"
            . "instructor,P1,,,C1,A\n"
            . "instructor,P1,,,C1,B\n"
            . "CHAIRPERSON,p1,,it,,\n");

        $this->assertCount(4, $batch->records, 'different things are different rows');
        $this->assertSame([['row' => 6, 'reason' => 'Repeats row 2 (role + person_external_id + department_code + sub_department_code + course_external_id + section).']], $batch->rejected, 'the same thing in other capitals is a repeat');
    }

    public function testAStaffFileWithoutItsRequiredColumnsIsRefusedWhole(): void
    {
        $this->expectException(SisException::class);
        $this->expectExceptionMessage('staff_assignments.csv is missing the column(s): person_external_id.');

        SisCsvParser::parse('staff_assignments', "role,department_code\ndean,CCS\n");
    }

    public function testTheTemplateOfTheStaffFileListsItsColumns(): void
    {
        $spec = SisCsvParser::spec('staff_assignments');

        $this->assertSame(['role', 'person_external_id'], $spec['required']);
        $this->assertSame(['department_code', 'sub_department_code', 'course_external_id', 'section'], $spec['optional']);
        $this->assertSame(['dean' => 'department_code', 'chairperson' => 'sub_department_code', 'instructor' => 'course_external_id'], SisCsvParser::STAFF_TARGET_COLUMN);
    }
}

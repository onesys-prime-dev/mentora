<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradingEngineService;
use App\Services\Institutions\EnrolmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The enrolment rules: who can be enrolled and how they are named, capacity
 * and its override, status changes that delete nothing, closed offerings,
 * the SIS lock, the roster list, and scoping.
 *
 * @internal
 */
final class EnrolmentServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $course;

    private int $offering;

    private User $actor;

    private EnrolmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('ENRA');
        $this->other       = SisFixture::institution('ENRB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new EnrolmentService();

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);
        $this->course = (int) $db->insertID();

        $term           = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $term, 'section_code' => 'A', 'capacity' => '2'], $this->actor);

        $db->table('audit_logs')->emptyTable();
    }

    private function student(string $first, ?string $number = null, ?string $email = null, bool $active = true, ?int $institution = null): User
    {
        $user = PeopleFactory::person('student', $first, 'Student', $email, $institution ?? $this->institution, $active);

        if ($number !== null) {
            db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);
        }

        return $user;
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the change to be refused.');
    }

    private function placeOf(User $student): ?string
    {
        $row = db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $student->id])->get()->getRowArray();

        return $row['status'] ?? null;
    }

    private function enrollmentId(User $student): int
    {
        return (int) db_connect()->table('enrollments')->where('student_user_id', $student->id)->get()->getRowArray()['id'];
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    public function testStudentsAreEnrolledByNumberOrEmailAndAudited(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben', null, 'Ben@School.test');

        $result = $this->service->add($this->institution, $this->offering, "2026-001\nben@school.test", false, $this->actor);

        $this->assertSame(2, $result['added']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame('enrolled', $this->placeOf($ann));
        $this->assertSame('enrolled', $this->placeOf($ben));
        $this->assertSame(2, $this->audit('enrolment.added'));
    }

    public function testThoseWhoCannotBeEnrolledAreSkippedWithTheReasonAndTheRestGoIn(): void
    {
        $ann      = $this->student('Ann', '2026-001');
        $inactive = $this->student('Ina', '2026-002', null, false);
        $foreign  = $this->student('Fay', '2026-003', null, true, $this->other);
        $teacher  = PeopleFactory::person('instructor', 'Tom', 'Teacher', 'tom@school.test', $this->institution);

        $result = $this->service->add($this->institution, $this->offering, '2026-001, 2026-002, 2026-003, tom@school.test, nobody@school.test', false, $this->actor);

        $this->assertSame(1, $result['added']);
        $this->assertSame('enrolled', $this->placeOf($ann));
        $this->assertNull($this->placeOf($inactive));
        $this->assertNull($this->placeOf($foreign));
        $this->assertNull($this->placeOf($teacher));

        $reasons = array_column($result['skipped'], 'reason', 'identifier');
        $this->assertStringContainsString('inactive', $reasons['2026-002']);
        $this->assertStringContainsString('not a student number or email in your institution', $reasons['2026-003']);
        $this->assertStringContainsString('student role', $reasons['tom@school.test']);
        $this->assertStringContainsString('not a student number or email', $reasons['nobody@school.test']);
        $this->assertCount(1, $result['warnings']);
    }

    public function testNothingToEnrolIsRefusedWithTheReasons(): void
    {
        $e = $this->refused(fn () => $this->service->add($this->institution, $this->offering, 'nobody@school.test', false, $this->actor));

        $this->assertArrayHasKey('students', $e->errors());
        $this->assertStringContainsString('Nobody was enrolled', $e->errors()['students']);
    }

    public function testAnEmptyBoxAndTooManyAreRefused(): void
    {
        $e = $this->refused(fn () => $this->service->add($this->institution, $this->offering, "  \n", false, $this->actor));
        $this->assertArrayHasKey('students', $e->errors());

        $many = implode("\n", array_map(static fn (int $i): string => "S{$i}", range(1, 501)));
        $e    = $this->refused(fn () => $this->service->add($this->institution, $this->offering, $many, false, $this->actor));
        $this->assertStringContainsString('500', $e->errors()['students']);
    }

    public function testAlreadyEnrolledIsSkippedAndAFormerPlaceIsReinstated(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben', '2026-002');
        CourseFactory::enroll($this->offering, $ann);
        CourseFactory::enroll($this->offering, $ben, 'dropped');

        $result = $this->service->add($this->institution, $this->offering, "2026-001\n2026-002", false, $this->actor);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['reinstated']);
        $this->assertStringContainsString('already enrolled', $result['skipped'][0]['reason']);
        $this->assertSame('enrolled', $this->placeOf($ben));
        $this->assertSame(1, db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $ben->id])->countAllResults(), 'the same row, not a second one');
        $this->assertSame(1, $this->audit('enrolment.status_changed'));
    }

    /** Brought back through Add, the gradebook drops the Withdrawn tag as a status change does (QA AMB-INST-04). */
    public function testBringingSomeoneBackThroughAddRefreshesTheGradebookLikeAStatusChange(): void
    {
        $scheme = GradeFactory::scheme($this->offering);
        $quiz   = GradeFactory::item($scheme, GradeFactory::category($scheme, 'Quizzes', 100), 'Quiz 1', 20);
        $remark = fn (User $s) => db_connect()->table('gradebook_records')->where(['course_offering_id' => $this->offering, 'student_id' => $s->id])->get()->getRowArray()['remarks'];

        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben', '2026-002');

        foreach ([$ann, $ben] as $student) {
            CourseFactory::enroll($this->offering, $student);
            GradeFactory::score($this->offering, $student, $quiz, 18);
        }

        (new GradingEngineService())->recalculateOffering($this->offering);

        foreach ([$ann, $ben] as $student) {
            $this->service->setStatus($this->institution, $this->enrollmentId($student), 'withdrawn', false, $this->actor);
            $this->assertSame('Withdrawn', $remark($student));
        }

        $this->service->add($this->institution, $this->offering, '2026-001', false, $this->actor);
        $this->service->setStatus($this->institution, $this->enrollmentId($ben), 'enrolled', false, $this->actor);

        $this->assertSame('Passed', $remark($ben));
        $this->assertSame($remark($ben), $remark($ann), 'both ways give the same result');
    }

    public function testGoingOverCapacityIsRefusedUnlessTheAdministratorSaysSo(): void
    {
        $this->student('Ann', '2026-001');
        $this->student('Ben', '2026-002');
        $this->student('Cy', '2026-003');

        $e = $this->refused(fn () => $this->service->add($this->institution, $this->offering, "2026-001\n2026-002\n2026-003", false, $this->actor));

        $this->assertArrayHasKey('over_capacity', $e->errors());
        $this->assertStringContainsString('over capacity by 1', $e->errors()['over_capacity']);
        $this->assertSame(0, db_connect()->table('enrollments')->where('offering_id', $this->offering)->countAllResults(), 'all or nothing');

        $result = $this->service->add($this->institution, $this->offering, "2026-001\n2026-002\n2026-003", true, $this->actor);

        $this->assertSame(3, $result['added']);
    }

    public function testStatusChangesKeepTheEnrolmentAndAreAudited(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $this->service->add($this->institution, $this->offering, '2026-001', false, $this->actor);
        $id = $this->enrollmentId($ann);

        $this->service->setStatus($this->institution, $id, 'dropped', false, $this->actor);
        $this->assertSame('dropped', $this->placeOf($ann));

        $this->service->setStatus($this->institution, $id, 'enrolled', false, $this->actor);
        $this->assertSame('enrolled', $this->placeOf($ann));

        $this->assertSame(2, $this->audit('enrolment.status_changed'));
        $this->assertSame(1, db_connect()->table('enrollments')->where('offering_id', $this->offering)->countAllResults());
    }

    public function testASameOrUnknownStatusIsRefused(): void
    {
        $ann = $this->student('Ann', '2026-001');
        CourseFactory::enroll($this->offering, $ann);
        $id = $this->enrollmentId($ann);

        $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'enrolled', false, $this->actor));

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'banished', false, $this->actor));
        $this->assertArrayHasKey('status', $e->errors());
    }

    public function testBringingSomeoneBackIntoAFullSectionNeedsTheOverride(): void
    {
        CourseFactory::enroll($this->offering, $this->student('Ann', '2026-001'));
        CourseFactory::enroll($this->offering, $this->student('Ben', '2026-002'));

        $cy = $this->student('Cy', '2026-003');
        CourseFactory::enroll($this->offering, $cy, 'dropped');
        $id = $this->enrollmentId($cy);

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'enrolled', false, $this->actor));
        $this->assertArrayHasKey('over_capacity', $e->errors());

        $this->service->setStatus($this->institution, $id, 'enrolled', true, $this->actor);
        $this->assertSame('enrolled', $this->placeOf($cy));
    }

    public function testAnArchivedOfferingTakesNoChanges(): void
    {
        $ann = $this->student('Ann', '2026-001');
        CourseFactory::enroll($this->offering, $ann, 'dropped');
        $id = $this->enrollmentId($ann);
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'archived']);

        $e = $this->refused(fn () => $this->service->add($this->institution, $this->offering, '2026-001', false, $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'enrolled', false, $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());
    }

    public function testWhatTheSisOwnsIsNotChangedByHand(): void
    {
        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben', '2026-002');
        $now = date('Y-m-d H:i:s');

        // The connection must be on for the SIS to own anything.
        (new \App\Services\Flags\FeatureFlagAdmin())->setForInstitution($this->institution, 'sis.sync', 'on', 'Pilot', null, $this->actor);
        (new \App\Services\Sis\SisConnectionService())->save($this->institution, true, [], false, $this->actor);

        $links = db_connect()->table('sis_links');
        $links->insert(['institution_id' => $this->institution, 'entity' => 'course', 'external_id' => 'C-1', 'local_id' => $this->course, 'created_at' => $now]);
        $links->insert(['institution_id' => $this->institution, 'entity' => 'person', 'external_id' => 'S-1', 'local_id' => $ann->id, 'created_at' => $now]);

        $result = $this->service->add($this->institution, $this->offering, "2026-001\n2026-002", false, $this->actor);

        $this->assertNull($this->placeOf($ann), 'the SIS knows the course and Ann');
        $this->assertSame('enrolled', $this->placeOf($ben), 'Ben is unknown to the SIS, so he can be enrolled');
        $this->assertStringContainsString('student information system', $result['skipped'][0]['reason']);

        CourseFactory::enroll($this->offering, $ann);
        $id = $this->enrollmentId($ann);

        $e = $this->refused(fn () => $this->service->setStatus($this->institution, $id, 'dropped', false, $this->actor));
        $this->assertStringContainsString('student information system', $e->getMessage());

        $roster = $this->service->roster($this->institution, $this->offering);
        $this->assertTrue($roster['courseLocked']);

        $locked = [];

        foreach ($roster['rows'] as $row) {
            $locked[$row['student_id']] = $row['locked'];
        }

        $this->assertTrue($locked[(int) $ann->id]);
        $this->assertFalse($locked[(int) $ben->id]);
    }

    public function testTheRosterIsFilteredSearchedSortedAndPaged(): void
    {
        $ann = $this->student('Ann', '2026-001', 'ann@school.test');
        $ben = $this->student('Ben', '2026-002', 'ben@school.test');
        CourseFactory::enroll($this->offering, $ann);
        CourseFactory::enroll($this->offering, $ben, 'dropped');

        $all = $this->service->roster($this->institution, $this->offering);
        $this->assertSame(2, $all['total']);
        $this->assertSame(1, $all['counts']['enrolled']);
        $this->assertSame(1, $all['counts']['dropped']);
        $this->assertSame(1, $all['offering']['enrolled_count']);

        $this->assertSame(1, $this->service->roster($this->institution, $this->offering, ['status' => 'dropped'])['total']);
        $this->assertSame(1, $this->service->roster($this->institution, $this->offering, ['q' => '2026-002'])['total']);
        $this->assertSame(1, $this->service->roster($this->institution, $this->offering, ['q' => 'ann@school'])['total']);
        $this->assertSame(1, $this->service->roster($this->institution, $this->offering, ['q' => 'Ann Student'])['total']);

        $desc = $this->service->roster($this->institution, $this->offering, ['sort' => 'number', 'dir' => 'desc']);
        $this->assertSame('2026-002', $desc['rows'][0]['student_number']);

        $this->assertSame(1, $this->service->roster($this->institution, $this->offering, ['page' => 99])['page']);
    }

    public function testAnotherInstitutionsOfferingAndPlacesAreNotFound(): void
    {
        $theirs  = SisFixture::course($this->other, 'C9', 'IT900');
        $student = $this->student('Zed', null, null, true, $this->other);
        CourseFactory::enroll((int) $theirs['offering'], $student);
        $id = $this->enrollmentId($student);

        foreach ([
            fn () => $this->service->roster($this->institution, (int) $theirs['offering']),
            fn () => $this->service->add($this->institution, (int) $theirs['offering'], 'x', false, $this->actor),
            fn () => $this->service->setStatus($this->institution, $id, 'dropped', false, $this->actor),
        ] as $call) {
            try {
                $call();
                $this->fail('A foreign id must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

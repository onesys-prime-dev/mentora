<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The offering rules: section, capacity, uniqueness, which course and term
 * can be offered, archiving with students enrolled, instructors (who can be
 * assigned, unassigning keeps their work), listing with filters, and scoping.
 *
 * @internal
 */
final class OfferingServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $course;

    private int $term;

    private User $actor;

    private OfferingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('OFFA');
        $this->other       = SisFixture::institution('OFFB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new OfferingService();

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);

        $this->course = (int) $db->insertID();
        $this->term   = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $db->table('audit_logs')->emptyTable();
    }

    private function add(string $section = 'A', array $more = []): int
    {
        return $this->service->create($this->institution, $more + ['course_id' => $this->course, 'term_id' => $this->term, 'section_code' => $section, 'capacity' => '40'], $this->actor);
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

    private function audit(string $action): ?array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id', 'desc')->get()->getRowArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Creating
    |--------------------------------------------------------------------------
    */

    public function testAnOfferingIsCreatedActiveWithTheSectionInCapitalsAndAudited(): void
    {
        $id = $this->add(' 1a ', ['capacity' => '35', 'description' => ' Morning class. ']);

        $row = db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray();

        $this->assertSame('1A', $row['section_code']);
        $this->assertSame(35, (int) $row['capacity']);
        $this->assertSame('Morning class.', $row['description']);
        $this->assertSame('active', $row['status']);
        $this->assertNotNull($this->audit('structure.offering_created'));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadOfferings(): iterable
    {
        yield 'no section' => [['section_code' => ''], 'section_code'];
        yield 'a section with a symbol' => [['section_code' => 'A#'], 'section_code'];
        yield 'a section that is too long' => [['section_code' => str_repeat('A', 51)], 'section_code'];
        yield 'no capacity' => [['capacity' => ''], 'capacity'];
        yield 'zero capacity' => [['capacity' => '0'], 'capacity'];
        yield 'a capacity that is not a whole number' => [['capacity' => '12.5'], 'capacity'];
        yield 'a huge capacity' => [['capacity' => '5000'], 'capacity'];
        yield 'a description that is too long' => [['description' => str_repeat('x', 5001)], 'description'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadOfferings')]
    public function testBadInputIsRefusedWithTheFieldNamed(array $override, string $field): void
    {
        $input = $override + ['course_id' => $this->course, 'term_id' => $this->term, 'section_code' => 'A', 'capacity' => '40'];

        $e = $this->refused(fn () => $this->service->create($this->institution, $input, $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertSame(0, db_connect()->table('course_offerings')->where('course_id', $this->course)->countAllResults());
    }

    public function testASectionIsUniqueForACourseInATermOnly(): void
    {
        $this->add('A');

        $e = $this->refused(fn () => $this->add('a'));
        $this->assertArrayHasKey('section_code', $e->errors());

        $this->add('B');

        db_connect()->table('academic_terms')->insert(['institution_id' => $this->institution, 'name' => 'Second Term', 'academic_year' => '2026-2027', 'start_date' => '2027-01-10', 'end_date' => '2027-05-20', 'status' => 'upcoming']);
        $second = (int) db_connect()->insertID();

        $this->service->create($this->institution, ['course_id' => $this->course, 'term_id' => $second, 'section_code' => 'A', 'capacity' => '40'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testOnlyAnActiveCourseInAnOpenTermCanBeOffered(): void
    {
        db_connect()->table('courses')->where('id', $this->course)->update(['status' => 'inactive']);
        $e = $this->refused(fn () => $this->add());
        $this->assertArrayHasKey('course_id', $e->errors());

        db_connect()->table('courses')->where('id', $this->course)->update(['status' => 'active']);
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'completed']);
        $e = $this->refused(fn () => $this->add());
        $this->assertArrayHasKey('term_id', $e->errors());
    }

    public function testACourseOrTermOfAnotherInstitutionIsA404(): void
    {
        $foreignTerm = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        $this->expectException(PageNotFoundException::class);

        $this->service->create($this->institution, ['course_id' => $this->course, 'term_id' => $foreignTerm, 'section_code' => 'A', 'capacity' => '40'], $this->actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing and archiving
    |--------------------------------------------------------------------------
    */

    public function testAnOfferingCanBeEditedAndOnlyWhatChangedIsAudited(): void
    {
        $id = $this->add('A');

        $this->service->update($this->institution, $id, ['section_code' => 'A', 'capacity' => '30', 'description' => ''], $this->actor);

        $this->assertSame(30, (int) db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['capacity']);
        $log = $this->audit('structure.offering_updated');
        $this->assertStringContainsString('capacity', (string) $log['new_values']);
        $this->assertStringNotContainsString('section_code', (string) $log['new_values']);

        $this->service->update($this->institution, $id, ['section_code' => 'A', 'capacity' => '30', 'description' => ''], $this->actor);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'structure.offering_updated')->countAllResults(), 'no change, no entry');
    }

    public function testCapacityCannotGoBelowTheStudentsAlreadyEnrolled(): void
    {
        $id = $this->add('A');

        foreach (['One', 'Two', 'Three'] as $name) {
            CourseFactory::enroll($id, PeopleFactory::person('student', 'Stu', $name, null, $this->institution));
        }

        $e = $this->refused(fn () => $this->service->update($this->institution, $id, ['section_code' => 'A', 'capacity' => '2'], $this->actor));

        $this->assertStringContainsString('3 students are already enrolled', $e->getMessage());

        $this->service->update($this->institution, $id, ['section_code' => 'A', 'capacity' => '3'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testASectionCannotBeRenamedOntoAnotherSection(): void
    {
        $this->add('A');
        $second = $this->add('B');

        $e = $this->refused(fn () => $this->service->update($this->institution, $second, ['section_code' => 'A', 'capacity' => '40'], $this->actor));

        $this->assertArrayHasKey('section_code', $e->errors());
    }

    public function testAnOfferingWithStudentsEnrolledCannotBeArchivedAndOneWithoutCan(): void
    {
        $id = $this->add('A');
        CourseFactory::enroll($id, PeopleFactory::person('student', 'Stu', 'One', null, $this->institution));

        $e = $this->refused(fn () => $this->service->setArchived($this->institution, $id, true, $this->actor));
        $this->assertStringContainsString('1 student enrolled', $e->getMessage());

        db_connect()->table('enrollments')->where('offering_id', $id)->update(['status' => 'dropped']);
        $this->service->setArchived($this->institution, $id, true, $this->actor);

        $this->assertSame('archived', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertNotNull($this->audit('structure.offering_archived'));

        $this->service->setArchived($this->institution, $id, false, $this->actor);
        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertNotNull($this->audit('structure.offering_reactivated'));
    }

    public function testAnArchivedOfferingCannotBeBroughtBackIntoAClosedTermOrEdited(): void
    {
        $id = $this->add('A');
        $this->service->setArchived($this->institution, $id, true, $this->actor);

        $e = $this->refused(fn () => $this->service->update($this->institution, $id, ['section_code' => 'A', 'capacity' => '40'], $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());

        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'completed']);
        $e = $this->refused(fn () => $this->service->setArchived($this->institution, $id, false, $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());
    }

    /*
    |--------------------------------------------------------------------------
    | Instructors
    |--------------------------------------------------------------------------
    */

    public function testOnlyInstructorsOfTheInstitutionCanBeOffered(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', 'ivy@offer.example', $this->institution);
        PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        PeopleFactory::person('instructor', 'Els', 'Where', null, $this->other);
        $off = PeopleFactory::person('instructor', 'Off', 'Duty', null, $this->institution);
        db_connect()->table('users')->where('id', $off->id)->update(['active' => 0]);

        $candidates = $this->service->instructorCandidates($this->institution);

        $this->assertSame([(int) $teacher->id], array_column($candidates, 'id'));
        $this->assertSame('Ivy Tan', $candidates[0]['name']);
    }

    public function testAnInstructorIsAssignedShownAndRemovedWithTheirWorkKept(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $id      = $this->add('A');

        $this->service->assignInstructor($this->institution, $id, (int) $teacher->id, $this->actor);

        $listed = $this->service->list($this->institution)['rows'][0];
        $this->assertSame([(int) $teacher->id], array_column($listed['instructors'], 'user_id'));
        $this->assertNotNull($this->audit('structure.offering_instructor_assigned'));

        // Work they did: an announcement of theirs.
        db_connect()->table('announcements')->insert(['offering_id' => $id, 'author_id' => $teacher->id, 'subject' => 'Welcome', 'message' => '{}', 'created_at' => date('Y-m-d H:i:s')]);

        $assignment = (int) db_connect()->table('instructor_assignments')->where('offering_id', $id)->get()->getRowArray()['id'];
        $this->service->unassignInstructor($this->institution, $assignment, $this->actor);

        $this->assertSame(0, db_connect()->table('instructor_assignments')->where('offering_id', $id)->countAllResults());
        $this->assertSame(1, db_connect()->table('announcements')->where('author_id', $teacher->id)->countAllResults(), 'their work stays');
        $this->assertNotNull($this->audit('structure.offering_instructor_unassigned'));
    }

    public function testTheSameInstructorTwiceAndNonInstructorsAreRefused(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        $id      = $this->add('A');

        $this->service->assignInstructor($this->institution, $id, (int) $teacher->id, $this->actor);

        $e = $this->refused(fn () => $this->service->assignInstructor($this->institution, $id, (int) $teacher->id, $this->actor));
        $this->assertStringContainsString('already teaches', $e->getMessage());

        foreach ([(int) $student->id, 999999] as $userId) {
            $e = $this->refused(fn () => $this->service->assignInstructor($this->institution, $id, $userId, $this->actor));
            $this->assertArrayHasKey('user_id', $e->errors());
        }
    }

    public function testInstructorsCannotBeChangedOnAnArchivedOffering(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $id      = $this->add('A');
        $this->service->assignInstructor($this->institution, $id, (int) $teacher->id, $this->actor);
        $assignment = (int) db_connect()->table('instructor_assignments')->where('offering_id', $id)->get()->getRowArray()['id'];
        $this->service->setArchived($this->institution, $id, true, $this->actor);

        $e = $this->refused(fn () => $this->service->assignInstructor($this->institution, $id, (int) $teacher->id, $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());

        $e = $this->refused(fn () => $this->service->unassignInstructor($this->institution, $assignment, $this->actor));
        $this->assertStringContainsString('closed', $e->getMessage());
        $this->assertSame(1, db_connect()->table('instructor_assignments')->where('id', $assignment)->countAllResults());
    }

    public function testAnotherInstitutionsOfferingAndAssignmentAreA404(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $theirs  = SisFixture::course($this->other, 'C9', 'IT900');
        db_connect()->table('instructor_assignments')->insert(['offering_id' => $theirs['offering'], 'instructor_user_id' => $teacher->id, 'assigned_at' => date('Y-m-d H:i:s')]);
        $assignment = (int) db_connect()->insertID();

        foreach ([
            fn () => $this->service->assignInstructor($this->institution, $theirs['offering'], (int) $teacher->id, $this->actor),
            fn () => $this->service->unassignInstructor($this->institution, $assignment, $this->actor),
            fn () => $this->service->update($this->institution, $theirs['offering'], ['section_code' => 'Z', 'capacity' => '10'], $this->actor),
            fn () => $this->service->setArchived($this->institution, $theirs['offering'], true, $this->actor),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A foreign offering must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, db_connect()->table('instructor_assignments')->where('id', $assignment)->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    public function testTheListHoldsOnlyThisInstitutionsOfferingsWithCountsAndInstructors(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $a       = $this->add('A');
        $this->add('B');
        SisFixture::course($this->other, 'C9', 'IT900');

        $this->service->assignInstructor($this->institution, $a, (int) $teacher->id, $this->actor);
        CourseFactory::enroll($a, PeopleFactory::person('student', 'Stu', 'One', null, $this->institution));

        $result = $this->service->list($this->institution);

        $this->assertSame(2, $result['total']);
        $this->assertSame(['A', 'B'], array_column($result['rows'], 'section_code'));
        $this->assertSame(1, (int) $result['rows'][0]['enrolled_count']);
        $this->assertSame('Ivy Tan', $result['rows'][0]['instructors'][0]['name']);
        $this->assertSame([], $result['rows'][1]['instructors']);
    }

    public function testTheListCanBeFilteredSortedAndPaged(): void
    {
        $teacher = PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution);
        $a       = $this->add('A');
        $b       = $this->add('B', ['capacity' => '10']);
        $this->service->assignInstructor($this->institution, $a, (int) $teacher->id, $this->actor);
        $this->service->setArchived($this->institution, $b, true, $this->actor);

        $sections = static fn (array $f): array => array_column((new OfferingService())->list($f['institution'], $f['filters'])['rows'], 'section_code');
        $i        = $this->institution;

        $this->assertSame(['A'], $sections(['institution' => $i, 'filters' => ['instructor' => (string) $teacher->id]]));
        $this->assertSame(['B'], $sections(['institution' => $i, 'filters' => ['instructor' => 'none']]));
        $this->assertSame(['B'], $sections(['institution' => $i, 'filters' => ['status' => 'archived']]));
        $this->assertSame(['A', 'B'], $sections(['institution' => $i, 'filters' => ['q' => 'IT101']]));
        $this->assertSame(['A'], $sections(['institution' => $i, 'filters' => ['q' => 'a', 'term' => $this->term, 'status' => 'active']]));
        $this->assertSame(['B', 'A'], $sections(['institution' => $i, 'filters' => ['sort' => 'capacity', 'dir' => 'asc']]));
        $this->assertSame([], $sections(['institution' => $i, 'filters' => ['term' => 999999]]));
    }

    public function testTheListPagesAndClampsAnOutOfRangePage(): void
    {
        for ($n = 1; $n <= OfferingService::PER_PAGE + 3; $n++) {
            $this->add('S' . $n);
        }

        $this->assertCount(OfferingService::PER_PAGE, $this->service->list($this->institution)['rows']);
        $this->assertCount(3, $this->service->list($this->institution, ['page' => 2])['rows']);
        $this->assertSame(2, $this->service->list($this->institution, ['page' => 50])['page']);
    }

    public function testTheFormOptionsAreTheInstitutionsActiveCoursesAndOpenTerms(): void
    {
        db_connect()->table('academic_terms')->insert(['institution_id' => $this->institution, 'name' => 'Old Term', 'academic_year' => '2025-2026', 'start_date' => '2025-08-01', 'end_date' => '2025-12-20', 'status' => 'completed']);

        $options = $this->service->options($this->institution);

        $this->assertSame([$this->course], array_column($options['courses'], 'id'));
        $this->assertSame([$this->term], array_column($options['terms'], 'id'));

        $choices = $this->service->filterChoices($this->institution);
        $this->assertCount(2, $choices['terms'], 'the filter offers closed terms too');
    }
}

<?php

declare(strict_types=1);

use App\Services\Institutions\ClassSectionService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * Block sections (BSIT 1-A): making one, giving it courses, putting students in
 * it (which enrols them in each course), taking them out, the rules that keep
 * work and grades safe, and scoping to one institution.
 *
 * @internal
 */
final class ClassSectionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $term;

    private int $program;

    private int $it101;

    private int $it102;

    private User $actor;

    private ClassSectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CSA');
        $this->other       = SisFixture::institution('CSB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new ClassSectionService();

        $db = db_connect();

        $this->program = (int) $db->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $this->term    = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        $db->table('courses')->insert(['program_id' => $this->program, 'course_code' => 'IT101', 'course_title' => 'Intro to Computing', 'units' => 3]);
        $this->it101 = (int) $db->insertID();
        $db->table('courses')->insert(['program_id' => $this->program, 'course_code' => 'IT102', 'course_title' => 'Programming 1', 'units' => 3]);
        $this->it102 = (int) $db->insertID();

        $db->table('audit_logs')->emptyTable();
    }

    private function student(string $first, string $number, ?int $institution = null): User
    {
        $user = PeopleFactory::person('student', $first, 'Student', null, $institution ?? $this->institution);
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number]);

        return $user;
    }

    /** @param array<string, string> $over */
    private function section(array $over = []): int
    {
        return $this->service->create($this->institution, $over + ['term_id' => (string) $this->term, 'program_id' => (string) $this->program, 'year_level' => '1', 'code' => 'BSIT 1-A', 'capacity' => '3'], $this->actor);
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

    private function place(User $student, int $offering): ?string
    {
        return db_connect()->table('enrollments')->where(['offering_id' => $offering, 'student_user_id' => $student->id])->get()->getRowArray()['status'] ?? null;
    }

    private function audit(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    public function testASectionIsMadeInATermAndProgramAndAudited(): void
    {
        $id  = $this->section();
        $row = db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray();

        $this->assertSame(['BSIT 1-A', '1', '3', 'active'], [$row['code'], $row['year_level'], $row['capacity'], $row['status']]);
        $this->assertSame($this->institution, (int) $row['institution_id']);
        $this->assertSame(1, $this->audit('structure.class_section_created'));
    }

    public function testTheCodeIsShownInCapitalsAndIsUniqueInTheTermNotAcrossInstitutions(): void
    {
        $this->section(['code' => 'bsit 1-a']);

        $this->assertSame('BSIT 1-A', db_connect()->table('class_sections')->get()->getRowArray()['code']);

        $e = $this->refused(fn () => $this->section(['code' => 'BSIT 1-A']));
        $this->assertArrayHasKey('code', $e->errors());

        $otherProgram = (int) db_connect()->query('SELECT p.id FROM programs p JOIN departments sd ON sd.id = p.department_id JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$this->other])->getRow()->id;
        $otherTerm    = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        $id = $this->service->create($this->other, ['term_id' => (string) $otherTerm, 'program_id' => (string) $otherProgram, 'year_level' => '1', 'code' => 'BSIT 1-A', 'capacity' => '5']);
        $this->assertGreaterThan(0, $id, 'another institution may use the same name');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadSections(): iterable
    {
        yield 'no name' => [['code' => ''], 'code'];
        yield 'a name with a symbol' => [['code' => 'BSIT/1'], 'code'];
        yield 'year 0' => [['year_level' => '0'], 'year_level'];
        yield 'year 13' => [['year_level' => '13'], 'year_level'];
        yield 'capacity 0' => [['capacity' => '0'], 'capacity'];
        yield 'capacity over the limit' => [['capacity' => '1001'], 'capacity'];
        yield 'capacity not a number' => [['capacity' => 'many'], 'capacity'];
    }

    /** @param array<string, string> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadSections')]
    public function testASectionWithABadValueIsRefusedBesideItsField(array $over, string $field): void
    {
        $this->assertArrayHasKey($field, $this->refused(fn () => $this->section($over))->errors());
        $this->assertSame(0, db_connect()->table('class_sections')->countAllResults());
    }

    public function testNothingIsMadeInAClosedTermOrWithAForeignTermOrProgram(): void
    {
        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'completed']);

        $this->assertArrayHasKey('term_id', $this->refused(fn () => $this->section())->errors());

        $this->expectException(PageNotFoundException::class);
        $this->service->create($this->other, ['term_id' => (string) $this->term, 'program_id' => (string) $this->program, 'year_level' => '1', 'code' => 'X', 'capacity' => '5']);
    }

    public function testACourseIsAddedAsTheSectionsOwnOfferingAndNotTwice(): void
    {
        $id     = $this->section();
        $result = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor);

        $offering = db_connect()->table('course_offerings')->where('id', $result['offering_id'])->get()->getRowArray();

        $this->assertSame(['BSIT 1-A', (string) $id, '3', 'active'], [$offering['section_code'], $offering['class_section_id'], $offering['capacity'], $offering['status']]);
        $this->assertSame(1, $this->audit('structure.class_section_course_added'));

        $this->assertArrayHasKey('course_id', $this->refused(fn () => $this->service->addCourse($this->institution, $id, $this->it101, $this->actor))->errors());
    }

    public function testACourseWhoseSectionCodeIsTakenByAStandaloneOfferingIsRefused(): void
    {
        (new OfferingService())->create($this->institution, ['course_id' => $this->it101, 'term_id' => $this->term, 'section_code' => 'BSIT 1-A', 'capacity' => '5'], $this->actor);
        $id = $this->section();

        $e = $this->refused(fn () => $this->service->addCourse($this->institution, $id, $this->it101, $this->actor));

        $this->assertStringContainsString('not part of this one', $e->errors()['course_id']);
    }

    public function testAddingStudentsEnrolsThemInEveryCourseOfTheSection(): void
    {
        $id  = $this->section();
        $a   = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $b   = $this->service->addCourse($this->institution, $id, $this->it102, $this->actor)['offering_id'];
        $ann = $this->student('Ann', '2026-001');
        $ben = $this->student('Ben', '2026-002');

        $result = $this->service->addStudents($this->institution, $id, "2026-001\n" . $ben->email, false, $this->actor);

        $this->assertSame(2, $result['added']);

        foreach ([$ann, $ben] as $student) {
            $this->assertSame('enrolled', $this->place($student, $a));
            $this->assertSame('enrolled', $this->place($student, $b));
        }

        $this->assertSame(2, (int) db_connect()->table('class_section_students')->where('class_section_id', $id)->countAllResults());
        $this->assertSame(2, $this->audit('structure.class_section_student_added'));
    }

    public function testACourseAddedLaterEnrolsTheStudentsAlreadyInTheSection(): void
    {
        $id  = $this->section();
        $ann = $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $result = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor);

        $this->assertSame(1, $result['enrolled']);
        $this->assertSame('enrolled', $this->place($ann, $result['offering_id']));
    }

    public function testNamesThatCannotBeAddedAreSkippedWithTheReasonAndTheRestGoIn(): void
    {
        $id = $this->section();
        $this->service->addCourse($this->institution, $id, $this->it101, $this->actor);
        $this->student('Ann', '2026-001');
        $this->student('Zed', '2026-099', $this->other);

        $result = $this->service->addStudents($this->institution, $id, "2026-001\n2026-099\nnobody@nowhere.test", false, $this->actor);

        $this->assertSame(1, $result['added']);
        $this->assertSame(['2026-099', 'nobody@nowhere.test'], array_column($result['skipped'], 'identifier'));
        $this->assertStringContainsString('is not a student number or email in your institution', $result['warnings'][0]);
    }

    public function testNobodyAddedIsARefusalNamingWhy(): void
    {
        $id = $this->section();
        $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $e = $this->refused(fn () => $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor));

        $this->assertStringContainsString('2026-001 is already in this section', $e->errors()['students']);
    }

    public function testCapacityNeedsTheOverrideAndSaysByHowMany(): void
    {
        $id = $this->section(['capacity' => '1']);
        $this->student('Ann', '2026-001');
        $this->student('Ben', '2026-002');

        $e = $this->refused(fn () => $this->service->addStudents($this->institution, $id, "2026-001\n2026-002", false, $this->actor));

        $this->assertStringContainsString('over capacity by 1', $e->errors()['over_capacity']);
        $this->assertSame(0, db_connect()->table('class_section_students')->countAllResults(), 'none go in');

        $this->assertSame(2, $this->service->addStudents($this->institution, $id, "2026-001\n2026-002", true, $this->actor)['added']);
    }

    public function testAnIrregularStudentAlreadyInOneCourseIsNotEnrolledTwiceWhenTheyJoin(): void
    {
        $id  = $this->section();
        $a   = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $ann = $this->student('Ann', '2026-001');

        (new \App\Services\Institutions\EnrolmentService())->add($this->institution, $a, '2026-001', false, $this->actor);
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $this->assertSame(1, (int) db_connect()->table('enrollments')->where(['offering_id' => $a, 'student_user_id' => $ann->id])->countAllResults());
    }

    public function testTakingAStudentOutDropsTheirPlacesButKeepsThem(): void
    {
        $id  = $this->section();
        $a   = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $b   = $this->service->addCourse($this->institution, $id, $this->it102, $this->actor)['offering_id'];
        $ann = $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $result = $this->service->removeStudent($this->institution, $id, (int) $ann->id, $this->actor);

        $this->assertSame(2, $result['dropped']);
        $this->assertSame('dropped', $this->place($ann, $a));
        $this->assertSame('dropped', $this->place($ann, $b));
        $this->assertSame(0, db_connect()->table('class_section_students')->countAllResults());
        $this->assertSame(1, $this->audit('structure.class_section_student_removed'));

        $this->refused(fn () => $this->service->removeStudent($this->institution, $id, (int) $ann->id, $this->actor));
    }

    public function testACourseWithStudentsEnrolledCannotBeTakenOutButAnEmptyOneCanAndComesBack(): void
    {
        $id  = $this->section();
        $a   = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $this->assertStringContainsString('1 student enrolled', $this->refused(fn () => $this->service->removeCourse($this->institution, $id, $a, $this->actor))->getMessage());

        $this->service->removeStudent($this->institution, $id, (int) db_connect()->table('class_section_students')->get()->getRowArray()['student_user_id'], $this->actor);
        $this->service->removeCourse($this->institution, $id, $a, $this->actor);

        $this->assertSame('archived', db_connect()->table('course_offerings')->where('id', $a)->get()->getRowArray()['status']);

        $again = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor);
        $this->assertSame($a, $again['offering_id'], 'the same offering is brought back, with its work');
        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $a)->get()->getRowArray()['status']);
    }

    public function testRenamingMovesItsOfferingsAndCapacityCannotGoBelowTheStudents(): void
    {
        $id = $this->section();
        $a  = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $this->student('Ann', '2026-001');
        $this->student('Ben', '2026-002');
        $this->service->addStudents($this->institution, $id, "2026-001\n2026-002", false, $this->actor);

        $this->assertArrayHasKey('capacity', $this->refused(fn () => $this->service->update($this->institution, $id, ['code' => 'BSIT 1-A', 'year_level' => '1', 'capacity' => '1'], $this->actor))->errors());

        $this->service->update($this->institution, $id, ['code' => 'bsit 1-b', 'year_level' => '2', 'capacity' => '10'], $this->actor);

        $offering = db_connect()->table('course_offerings')->where('id', $a)->get()->getRowArray();
        $this->assertSame(['BSIT 1-B', '10'], [$offering['section_code'], $offering['capacity']]);
        $this->assertSame(1, $this->audit('structure.class_section_updated'));
    }

    public function testASectionWithStudentsCannotBeArchivedAnEmptyOneCanAndComesBack(): void
    {
        $id = $this->section();
        $a  = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $ann = $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $this->assertStringContainsString('1 student in it', $this->refused(fn () => $this->service->setArchived($this->institution, $id, true, $this->actor))->getMessage());

        $this->service->removeStudent($this->institution, $id, (int) $ann->id, $this->actor);
        $this->service->setArchived($this->institution, $id, true, $this->actor);

        $this->assertSame('archived', db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame('archived', db_connect()->table('course_offerings')->where('id', $a)->get()->getRowArray()['status']);
        $this->refused(fn () => $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor));

        $this->service->setArchived($this->institution, $id, false, $this->actor);
        $this->assertSame('active', db_connect()->table('class_sections')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testNothingChangesInASectionOfAClosedTerm(): void
    {
        $id  = $this->section();
        $a   = $this->service->addCourse($this->institution, $id, $this->it101, $this->actor)['offering_id'];
        $ann = $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        db_connect()->table('academic_terms')->where('id', $this->term)->update(['status' => 'completed']);

        foreach ([
            fn () => $this->service->update($this->institution, $id, ['code' => 'BSIT 1-A', 'year_level' => '1', 'capacity' => '9']),
            fn () => $this->service->addStudents($this->institution, $id, '2026-001', false),
            fn () => $this->service->removeStudent($this->institution, $id, (int) $ann->id),
            fn () => $this->service->removeCourse($this->institution, $id, $a),
        ] as $change) {
            $this->assertStringContainsString('is closed', $this->refused($change)->getMessage());
        }

        $this->assertSame(1, db_connect()->table('class_section_students')->countAllResults(), 'the student is still in the section');
        $this->assertSame('enrolled', $this->place($ann, $a));
    }

    public function testTheListCountsCoursesAndStudentsAndFilters(): void
    {
        $id = $this->section();
        $this->section(['code' => 'BSIT 2-A', 'year_level' => '2']);
        $this->service->addCourse($this->institution, $id, $this->it101, $this->actor);
        $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $list = $this->service->list($this->institution);
        $this->assertSame(2, $list['total']);

        $first = array_values(array_filter($list['rows'], static fn (array $r): bool => $r['code'] === 'BSIT 1-A'))[0];
        $this->assertSame([1, 1], [(int) $first['course_count'], (int) $first['student_count']]);

        $this->assertSame(1, $this->service->list($this->institution, ['q' => '2-A'])['total']);
        $this->assertSame(0, $this->service->list($this->other)['total'], 'another institution sees none');
    }

    public function testTheDetailListsCoursesStudentsAndWhatCouldStillBeAdded(): void
    {
        $id = $this->section();
        $this->service->addCourse($this->institution, $id, $this->it101, $this->actor);
        $this->student('Ann', '2026-001');
        $this->service->addStudents($this->institution, $id, '2026-001', false, $this->actor);

        $detail = $this->service->detail($this->institution, $id);

        $this->assertSame(['IT101'], array_column($detail['courses'], 'course_code'));
        $this->assertSame(['2026-001'], array_column($detail['students'], 'student_number'));
        $this->assertSame(1, $detail['students'][0]['places']);
        $this->assertSame(['IT102'], array_column($detail['available'], 'course_code'));
    }

    public function testAnotherInstitutionsSectionIsNotFoundInAnyAction(): void
    {
        $id = $this->section();

        foreach ([
            fn () => $this->service->detail($this->other, $id),
            fn () => $this->service->update($this->other, $id, ['code' => 'X', 'year_level' => '1', 'capacity' => '5']),
            fn () => $this->service->setArchived($this->other, $id, true),
            fn () => $this->service->addCourse($this->other, $id, $this->it101),
            fn () => $this->service->addStudents($this->other, $id, '2026-001', false),
            fn () => $this->service->removeStudent($this->other, $id, 1),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testActiveByCodeFindsOnlyAnActiveSectionOfTheTerm(): void
    {
        $id = $this->section();

        $this->assertSame($id, (int) $this->service->activeByCode($this->institution, $this->term, 'BSIT 1-A')['id']);
        $this->assertNull($this->service->activeByCode($this->institution, $this->term, 'BSIT 9-Z'));
        $this->assertNull($this->service->activeByCode($this->other, $this->term, 'BSIT 1-A'));

        $this->service->setArchived($this->institution, $id, true, $this->actor);
        $this->assertNull($this->service->activeByCode($this->institution, $this->term, 'BSIT 1-A'));
    }
}

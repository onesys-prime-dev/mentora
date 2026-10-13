<?php

declare(strict_types=1);

use App\Services\Institutions\OfferingCopyService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use App\Services\Institutions\TermCloseService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * Copying offerings into another term: what comes across and what never does,
 * ids remapped inside the copy, dates moved with the term, files shared not
 * duplicated, clashes refused, the source term then staying closed, audit and
 * scoping.
 *
 * @internal
 */
final class OfferingCopyServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $sourceTerm;

    private int $targetTerm;

    private int $course;

    private int $offering;

    private User $actor;

    private User $teacher;

    private OfferingCopyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CPYA');
        $this->other       = SisFixture::institution('CPYB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tom', 'Teacher', null, $this->institution);
        $this->service     = new OfferingCopyService();

        $db = db_connect();

        $program = (int) $db->query('SELECT p.id FROM programs p JOIN sub_departments sd ON sd.id = p.sub_department_id JOIN departments d ON d.id = sd.department_id WHERE d.institution_id = ?', [$this->institution])->getRow()->id;
        $db->table('courses')->insert(['program_id' => $program, 'course_code' => 'IT101', 'course_title' => 'Intro', 'units' => 3]);
        $this->course = (int) $db->insertID();

        $this->sourceTerm = (int) $db->table('academic_terms')->where('institution_id', $this->institution)->get()->getRowArray()['id'];

        // Source term starts 2026-08-01 (SisFixture); the target 90 days later.
        $db->table('academic_terms')->insert([
            'institution_id' => $this->institution, 'name' => 'Second Term', 'academic_year' => '2026-2027',
            'start_date' => '2026-10-30', 'end_date' => '2027-03-01', 'status' => 'upcoming', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->targetTerm = (int) $db->insertID();

        $this->offering = (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $this->sourceTerm, 'section_code' => 'A', 'capacity' => '35', 'description' => 'Morning'], $this->actor);

        $db->table('audit_logs')->emptyTable();
    }

    /** A source offering with something of everything. @return array<string, int> */
    private function fill(): array
    {
        $db = db_connect();

        $db->table('course_schedules')->insert(['offering_id' => $this->offering, 'day_of_week' => 'mon', 'start_time' => '08:00:00', 'end_time' => '09:30:00', 'room' => 'R1', 'delivery_mode' => 'onsite', 'created_at' => date('Y-m-d H:i:s')]);
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $module = CourseFactory::module($this->offering);
        $unit   = CourseFactory::unit($module);
        [$first, $second] = CourseFactory::items($unit, [['title' => 'Read me'], ['title' => 'Then this', 'mode' => 'custom']]);
        CourseFactory::requires($second, $first);

        $file = CourseFactory::file($this->teacher, 'lecture notes');
        CourseFactory::resource($first, 'pdf', null, $file['id']);

        $assessment = CourseFactory::assessment($this->offering, $this->teacher, $module, $second);

        $db->table('assessment_settings')->insert(['assessment_id' => $assessment, 'allow_text_entry' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('assessment_questions')->insert(['assessment_id' => $assessment, 'question_text' => 'What is 2+2?', 'question_type' => 'Multiple Choice', 'points' => 2, 'order_index' => 1, 'created_by' => $this->teacher->id, 'created_at' => date('Y-m-d H:i:s')]);
        $question = (int) $db->insertID();
        $db->table('assessment_question_choices')->insert(['question_id' => $question, 'choice_text' => '4', 'is_correct' => 1, 'order_index' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('assessment_rubrics')->insert(['assessment_id' => $assessment, 'title' => 'Essay rubric', 'total_points' => 10, 'created_by' => $this->teacher->id, 'created_at' => date('Y-m-d H:i:s')]);
        $rubric = (int) $db->insertID();
        $db->table('assessment_rubric_criteria')->insert(['rubric_id' => $rubric, 'criteria_title' => 'Clarity', 'max_points' => 5, 'order_index' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $scheme   = GradeFactory::scheme($this->offering);
        $period   = GradeFactory::period($scheme, 'Prelim', 100);
        $category = GradeFactory::category($scheme, 'Quizzes', 100, $period);
        GradeFactory::item($scheme, $category, 'Quiz 1', 20, 1, $assessment);

        // What must never come across.
        $student = PeopleFactory::person('student', 'Stu', 'Dent', null, $this->institution);
        CourseFactory::enroll($this->offering, $student);
        $db->table('assessment_submissions')->insert(['assessment_id' => $assessment, 'student_id' => $student->id, 'attempt_number' => 1, 'started_at' => date('Y-m-d H:i:s'), 'status' => 'Submitted']);
        CourseFactory::thread($this->offering, $student);

        return ['module' => $module, 'assessment' => $assessment, 'file' => $file['id']];
    }

    private function copied(): int
    {
        return (int) db_connect()->table('course_offerings')->where(['term_id' => $this->targetTerm, 'copied_from_offering_id' => $this->offering])->get()->getRowArray()['id'];
    }

    private function rowsIn(string $table, string $column, int $id): int
    {
        return db_connect()->table($table)->where($column, $id)->countAllResults();
    }

    protected function tearDown(): void
    {
        CourseFactory::removeCreatedFiles();

        parent::tearDown();
    }

    public function testThePlanSaysWhatEachOfferingHoldsAndTheDateShift(): void
    {
        $this->fill();

        $plan = $this->service->plan($this->institution, $this->sourceTerm, $this->targetTerm);

        $this->assertSame(90, $plan['shiftDays']);
        $this->assertCount(1, $plan['offerings']);
        $this->assertSame(1, (int) $plan['offerings'][0]['modules']);
        $this->assertSame(2, (int) $plan['offerings'][0]['items']);
        $this->assertSame(1, (int) $plan['offerings'][0]['assessments']);
        $this->assertSame(1, (int) $plan['offerings'][0]['instructors']);
        $this->assertFalse($plan['offerings'][0]['conflict']);
    }

    public function testTheContentComesAcrossWithItsLinksRemappedAndNothingElseDoes(): void
    {
        $src  = $this->fill();
        $done = $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], true, $this->actor);

        $this->assertSame(1, $done['offerings']);
        $this->assertSame(1, $done['modules']);
        $this->assertSame(2, $done['items']);
        $this->assertSame(1, $done['assessments']);
        $this->assertSame(1, $done['questions']);

        $new = $this->copied();
        $row = db_connect()->table('course_offerings')->where('id', $new)->get()->getRowArray();

        $this->assertSame('A', $row['section_code']);
        $this->assertSame(35, (int) $row['capacity']);
        $this->assertSame('Morning', $row['description']);
        $this->assertSame('active', $row['status']);
        $this->assertSame((int) $this->course, (int) $row['course_id']);

        // Schedule and instructors.
        $this->assertSame(1, $this->rowsIn('course_schedules', 'offering_id', $new));
        $this->assertSame(1, $this->rowsIn('instructor_assignments', 'offering_id', $new));

        // Content, with the new ids pointing inside the copy.
        $module = db_connect()->table('course_modules')->where('offering_id', $new)->get()->getRowArray();
        $this->assertNotSame($src['module'], (int) $module['id']);

        $items = db_connect()->table('learning_items i')->select('i.*')->join('learning_units u', 'u.id = i.learning_unit_id')->where('u.module_id', $module['id'])->orderBy('i.id')->get()->getResultArray();
        $this->assertCount(2, $items);

        $prereq = db_connect()->table('learning_item_prerequisites')->where('learning_item_id', $items[1]['id'])->get()->getRowArray();
        $this->assertSame((int) $items[0]['id'], (int) $prereq['prerequisite_item_id']);

        // The assessment points at the copy's module and item, with questions and rubric.
        $assessment = db_connect()->table('assessments')->where('module_id', $module['id'])->get()->getRowArray();
        $this->assertSame((int) $items[1]['id'], (int) $assessment['learning_item_id']);
        $this->assertSame(1, $this->rowsIn('assessment_settings', 'assessment_id', (int) $assessment['id']));
        $this->assertSame(1, $this->rowsIn('assessment_questions', 'assessment_id', (int) $assessment['id']));
        $this->assertSame(1, $this->rowsIn('assessment_rubrics', 'assessment_id', (int) $assessment['id']));
        $this->assertSame(1, db_connect()->table('assessment_question_choices c')->join('assessment_questions q', 'q.id = c.question_id')->where('q.assessment_id', $assessment['id'])->countAllResults());
        $this->assertSame(1, db_connect()->table('assessment_rubric_criteria k')->join('assessment_rubrics r', 'r.id = k.rubric_id')->where('r.assessment_id', $assessment['id'])->countAllResults());

        // The grading scheme and its gradebook item follow the copied assessment.
        $scheme = db_connect()->table('grading_schemes')->where('course_offering_id', $new)->get()->getRowArray();
        $this->assertNotNull($scheme);
        $this->assertSame(1, (int) $scheme['needs_recalculation']);
        $this->assertSame(1, $this->rowsIn('grading_periods', 'grading_scheme_id', (int) $scheme['id']));
        $this->assertSame(1, $this->rowsIn('grade_categories', 'grading_scheme_id', (int) $scheme['id']));
        $gradeItem = db_connect()->table('grade_items')->where('grading_scheme_id', $scheme['id'])->get()->getRowArray();
        $this->assertSame((int) $assessment['id'], (int) $gradeItem['assessment_id']);

        // Never copied.
        $this->assertSame(0, $this->rowsIn('enrollments', 'offering_id', $new));
        $this->assertSame(0, $this->rowsIn('assessment_submissions', 'assessment_id', (int) $assessment['id']));
        $this->assertSame(0, $this->rowsIn('discussion_threads', 'offering_id', $new));
        $this->assertSame(0, $this->rowsIn('gradebook_records', 'course_offering_id', $new));
    }

    public function testDatesMoveWithTheTermAndTheOriginalIsUntouched(): void
    {
        $src = $this->fill();

        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], false, $this->actor);

        $original = db_connect()->table('assessments')->where('id', $src['assessment'])->get()->getRowArray();
        $this->assertSame('2026-09-01 08:00:00', $original['available_from']);

        $module     = db_connect()->table('course_modules')->where('offering_id', $this->copied())->get()->getRowArray();
        $assessment = db_connect()->table('assessments')->where('module_id', $module['id'])->get()->getRowArray();

        // +90 days from the source term's start to the target's.
        $this->assertSame('2026-11-30 08:00:00', $assessment['available_from']);
        $this->assertSame('2027-03-01 23:59:00', $assessment['due_date']);
        $this->assertNull($assessment['available_until']);
    }

    public function testInstructorsAreOnlyCopiedWhenAsked(): void
    {
        $this->fill();

        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], false, $this->actor);

        $this->assertSame(0, $this->rowsIn('instructor_assignments', 'offering_id', $this->copied()));
    }

    public function testFilesAreSharedNotDuplicated(): void
    {
        $src    = $this->fill();
        $before = db_connect()->table('files')->countAllResults();

        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], false, $this->actor);

        $this->assertSame($before, db_connect()->table('files')->countAllResults(), 'no extra file rows');
        $this->assertSame(2, db_connect()->table('learning_resources')->where('file_id', $src['file'])->countAllResults(), 'the original and the copy point at one file');
    }

    public function testASectionThatAlreadyExistsInTheTargetIsRefusedAndNothingIsCopied(): void
    {
        $this->fill();
        (new OfferingService())->create($this->institution, ['course_id' => $this->course, 'term_id' => $this->targetTerm, 'section_code' => 'A', 'capacity' => '10'], $this->actor);

        $plan = $this->service->plan($this->institution, $this->sourceTerm, $this->targetTerm);
        $this->assertTrue($plan['offerings'][0]['conflict']);

        try {
            $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], false, $this->actor);
            $this->fail('A clash must be refused.');
        } catch (StructureException $e) {
            $this->assertStringContainsString('already has a section', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('course_offerings')->where('copied_from_offering_id', $this->offering)->countAllResults());
    }

    /** @return iterable<string, array{int, int, list<int>}> */
    public static function provideRefusals(): iterable
    {
        yield 'nothing ticked' => [0, 0, []];
        yield 'an offering that is not in the source term' => [0, 0, [999999]];
    }

    /**
     * @param list<int> $ids
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusals')]
    public function testBadChoicesAreRefused(int $unusedA, int $unusedB, array $ids): void
    {
        $this->fill();

        $this->expectException(StructureException::class);
        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, $ids, false, $this->actor);
    }

    public function testTheTargetMustBeAnOpenTermAndNotTheSource(): void
    {
        $this->fill();

        $e = null;

        try {
            $this->service->plan($this->institution, $this->sourceTerm, $this->sourceTerm);
        } catch (StructureException $e) {
        }

        $this->assertInstanceOf(StructureException::class, $e);
        $this->assertArrayHasKey('target', $e->errors());

        db_connect()->table('academic_terms')->where('id', $this->targetTerm)->update(['status' => 'completed']);

        $this->expectException(StructureException::class);
        $this->service->plan($this->institution, $this->sourceTerm, $this->targetTerm);
    }

    public function testArchivedOfferingsAreNotOffered(): void
    {
        $this->fill();
        db_connect()->table('course_offerings')->where('id', $this->offering)->update(['status' => 'archived']);

        $this->assertSame([], $this->service->plan($this->institution, $this->sourceTerm, $this->targetTerm)['offerings']);
    }

    public function testTheSourceTermCannotBeReopenedOnceSomethingWasCopiedFromIt(): void
    {
        $this->fill();

        $close = new TermCloseService();
        $close->close($this->institution, $this->sourceTerm, $this->actor);

        $term = db_connect()->table('academic_terms')->where('id', $this->sourceTerm)->get()->getRowArray();
        $this->assertNull($close->reopenBlock($term));

        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], false, $this->actor);

        $this->assertStringContainsString('copied from', (string) $close->reopenBlock($term));

        $this->expectException(StructureException::class);
        $close->reopen($this->institution, $this->sourceTerm, $this->actor);
    }

    public function testACompletedOfferingCanBeCopiedAndTheCopyIsActive(): void
    {
        $this->fill();
        (new TermCloseService())->close($this->institution, $this->sourceTerm, $this->actor);

        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], false, $this->actor);

        $this->assertSame('active', db_connect()->table('course_offerings')->where('id', $this->copied())->get()->getRowArray()['status']);
    }

    public function testTheCopyIsAudited(): void
    {
        $this->fill();

        $this->service->copy($this->institution, $this->sourceTerm, $this->targetTerm, [$this->offering], true, $this->actor);

        $audit = db_connect()->table('audit_logs')->where('action', 'structure.offerings_copied')->get()->getRowArray();

        $this->assertNotNull($audit);
        $this->assertSame($this->targetTerm, (int) $audit['target_id']);
    }

    public function testAnotherInstitutionsTermsAreNotFound(): void
    {
        $theirs = (int) db_connect()->table('academic_terms')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([
            fn () => $this->service->plan($this->institution, $theirs, $this->targetTerm),
            fn () => $this->service->plan($this->institution, $this->sourceTerm, $theirs),
            fn () => $this->service->copy($this->institution, $theirs, $this->targetTerm, [1], false, $this->actor),
        ] as $do) {
            try {
                $do();
                $this->fail('A foreign id must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheTermListsOfferTheRightSourcesAndTargets(): void
    {
        $terms = $this->service->terms($this->institution);

        $this->assertCount(2, $terms['sources']);
        $this->assertSame([$this->targetTerm, $this->sourceTerm], array_map('intval', array_column($terms['targets'], 'id')));
    }
}

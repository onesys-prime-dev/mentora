<?php

declare(strict_types=1);

use App\Services\Courses\ContentTransferService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Copying and moving a module, a learning unit and a learning item between course offerings: where it may go, what
 * comes along, what is left behind, and the refusals.
 *
 * @internal
 */
final class ContentTransferServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ContentTransferService $service;

    private User $instructor;

    private int $a; // the offering the content is in

    private int $b; // another of this instructor's offerings, same institution, a later term

    private int $module;

    private int $unit1;

    private int $unit2;

    private int $reading;

    private int $quiz;

    private int $assignment;

    private int $discussion;

    private int $reading2;

    private int $quizAssessment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = new ContentTransferService();
        $this->instructor = CourseFactory::user('instructor');

        $this->a = CourseFactory::offering('A');
        CourseFactory::assignInstructor($this->a, $this->instructor);
        $this->b = $this->siblingOffering($this->a, '2026-09-01');
        CourseFactory::assignInstructor($this->b, $this->instructor);

        $this->module = CourseFactory::module($this->a, 'published', 1);
        $this->unit1  = CourseFactory::unit($this->module, 'published', 1);
        $this->unit2  = CourseFactory::unit($this->module, 'published', 2);

        [$this->reading, $this->quiz, $this->assignment, $this->discussion] = CourseFactory::items($this->unit1, [
            ['title' => 'Read me', 'type' => 'reading'],
            ['title' => 'Quiz 1', 'type' => 'quiz'],
            ['title' => 'Essay', 'type' => 'assignment'],
            ['title' => 'Talk', 'type' => 'discussion'],
        ]);
        [$this->reading2] = CourseFactory::items($this->unit2, [['title' => 'Second reading', 'type' => 'reading']]);

        $this->quizAssessment = CourseFactory::assessment($this->a, $this->instructor, $this->module, $this->quiz, 'Quiz');
        db_connect()->table('assessments')->where('id', $this->quizAssessment)->update([
            'status' => 'Published', 'total_points' => 20, 'available_from' => '2026-08-10 08:00:00', 'due_date' => '2026-08-20 17:00:00',
        ]);
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->quizAssessment, 'assign_to_specific_students' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        AssessmentFactory::multipleChoice($this->quizAssessment, $this->instructor, [true, false, false]);
        AssessmentFactory::multipleChoice($this->quizAssessment, $this->instructor, [false, true]);

        db_connect()->table('assessment_rubrics')->insert(['assessment_id' => $this->quizAssessment, 'title' => 'Rubric', 'total_points' => 10, 'created_by' => $this->instructor->id, 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('assessment_rubric_criteria')->insert(['rubric_id' => (int) db_connect()->insertID(), 'criteria_title' => 'Clarity', 'max_points' => 10, 'created_at' => date('Y-m-d H:i:s')]);

        $assignmentAssessment = CourseFactory::assessment($this->a, $this->instructor, $this->module, $this->assignment, 'Assignment');
        db_connect()->table('assessments')->where('id', $assignmentAssessment)->update(['status' => 'Published']);

        db_connect()->table('discussion_threads')->insert(['offering_id' => $this->a, 'learning_item_id' => $this->discussion, 'author_id' => $this->instructor->id, 'title' => 'Talk', 'body' => 'What do you think?', 'last_activity_at' => date('Y-m-d H:i:s')]);
    }

    /** Another offering of the same institution, in a term that starts later. */
    private function siblingOffering(int $like, string $termStart): int
    {
        $db  = db_connect();
        $src = $db->table('course_offerings o')->select('o.course_id, c.program_id, t.institution_id')->join('courses c', 'c.id = o.course_id')->join('academic_terms t', 't.id = o.term_id')->where('o.id', $like)->get()->getRowArray();

        $db->table('academic_terms')->insert(['institution_id' => $src['institution_id'], 'name' => 'Later term', 'academic_year' => '2026-2027', 'start_date' => $termStart, 'end_date' => '2027-01-20']);
        $term = (int) $db->insertID();

        $db->table('courses')->insert(['program_id' => $src['program_id'], 'course_code' => 'SIB' . random_int(1000, 9999), 'course_title' => 'Sibling course', 'units' => 3]);
        $course = (int) $db->insertID();

        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'B']);

        return (int) $db->insertID();
    }

    private function row(string $table, int $id): array
    {
        return db_connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function rows(string $table, array $where): int
    {
        return db_connect()->table($table)->where($where)->countAllResults();
    }

    /** @return array<string, mixed> */
    private function copyModule(array $target, ?array $units = null, ?array $items = null, ?int $offering = null): array
    {
        return $this->service->copy($this->instructor, $offering ?? $this->a, 'module', $this->module, $target, $units, $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Where it can go
    |--------------------------------------------------------------------------
    */

    public function testTheOfferingsListedAreTheOnesThePersonTeachesInTheSameInstitution(): void
    {
        $foreign = CourseFactory::offering('Z');
        CourseFactory::assignInstructor($foreign, $this->instructor);
        $other = $this->siblingOffering($this->a, '2026-09-01'); // not taught by this person

        $ids = array_column($this->service->offeringsFor($this->instructor, $this->a), 'id');

        $this->assertEqualsCanonicalizing([$this->a, $this->b], $ids);
        $this->assertNotContains($foreign, $ids);
        $this->assertNotContains($other, $ids);
    }

    public function testTheCurrentOfferingIsMarked(): void
    {
        $list = array_column($this->service->offeringsFor($this->instructor, $this->a), null, 'id');

        $this->assertTrue($list[$this->a]['is_current']);
        $this->assertFalse($list[$this->b]['is_current']);
        $this->assertStringContainsString('·', $list[$this->b]['label']);
    }

    public function testAnArchivedOfferingIsNotOffered(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->b)->update(['status' => 'archived']);

        $this->assertNotContains($this->b, array_column($this->service->offeringsFor($this->instructor, $this->a), 'id'));
    }

    public function testModulesAndUnitsAreListedOnlyForWhatThePersonTeaches(): void
    {
        $this->assertSame([$this->module], array_column($this->service->modulesOf($this->instructor, $this->a), 'id'));
        $this->assertSame([$this->unit1, $this->unit2], array_column($this->service->unitsOf($this->instructor, $this->module), 'id'));

        $stranger = CourseFactory::user('instructor');
        $this->assertNull($this->service->modulesOf($stranger, $this->a));
        $this->assertNull($this->service->unitsOf($stranger, $this->module));
    }

    public function testTheTreeListsTheUnitsAndItemsOfAModule(): void
    {
        $tree = $this->service->treeOf($this->instructor, $this->a, 'module', $this->module);

        $this->assertSame([$this->unit1, $this->unit2], array_column($tree['units'], 'id'));
        $this->assertSame(['Read me', 'Quiz 1', 'Essay', 'Talk'], array_column($tree['units'][0]['items'], 'title'));
    }

    public function testTheTreeOfAUnitIsThatUnitAlone(): void
    {
        $tree = $this->service->treeOf($this->instructor, $this->a, 'unit', $this->unit2);

        $this->assertCount(1, $tree['units']);
        $this->assertSame([$this->reading2], array_column($tree['units'][0]['items'], 'id'));
    }

    public function testATreeOfSomethingInAnotherOfferingIsNotGiven(): void
    {
        $this->assertNull($this->service->treeOf($this->instructor, $this->b, 'module', $this->module));
    }

    /*
    |--------------------------------------------------------------------------
    | Copy a module
    |--------------------------------------------------------------------------
    */

    public function testAModuleIsCopiedWithItsUnitsAndItemsAsADraftAtTheEnd(): void
    {
        $existing = CourseFactory::module($this->b, 'published', 5);

        $result = $this->copyModule(['offering_id' => $this->b]);

        $this->assertTrue($result['success'], (string) $result['message']);
        $this->assertSame(2, $result['units']);
        $this->assertSame(5, $result['items']);

        $copy = $this->row('course_modules', $result['module_id']);
        $this->assertSame($this->b, (int) $copy['offering_id']);
        $this->assertSame('draft', $copy['status']);
        $this->assertNull($copy['scheduled_publish_at']);
        $this->assertSame(6, (int) $copy['order_index']);
        $this->assertSame($this->row('course_modules', $this->module)['title'], $copy['title'], 'another course: the title stays');
        $this->assertNotSame($existing, $result['module_id']);

        $units = db_connect()->table('learning_units')->where('module_id', $result['module_id'])->orderBy('order_index')->get()->getResultArray();
        $this->assertCount(2, $units);
        $this->assertSame(['draft', 'draft'], array_column($units, 'status'));

        $items = db_connect()->table('learning_items')->where('learning_unit_id', (int) $units[0]['id'])->orderBy('order_index')->get()->getResultArray();
        $this->assertSame(['Read me', 'Quiz 1', 'Essay', 'Talk'], array_column($items, 'title'));
        $this->assertSame(['draft'], array_unique(array_column($items, 'status')));
    }

    public function testTheSourceIsLeftAsItWas(): void
    {
        $this->copyModule(['offering_id' => $this->b]);

        $this->assertSame('published', $this->row('course_modules', $this->module)['status']);
        $this->assertSame(1, $this->rows('course_modules', ['offering_id' => $this->a]));
        $this->assertSame(4, $this->rows('learning_items', ['learning_unit_id' => $this->unit1]));
    }

    public function testCopiedIntoTheSameOfferingTheModuleSaysCopy(): void
    {
        $result = $this->copyModule(['offering_id' => $this->a]);

        $this->assertTrue($result['success']);
        $title = $this->row('course_modules', $this->module)['title'];
        $this->assertSame($title . ' (copy)', $this->row('course_modules', $result['module_id'])['title']);
        $this->assertSame(2, $this->rows('course_modules', ['offering_id' => $this->a]));
    }

    public function testOnlyTheChosenUnitsAndItemsAreCopied(): void
    {
        $result = $this->copyModule(['offering_id' => $this->b], [$this->unit1], [$this->reading, $this->quiz, $this->reading2]);

        $this->assertSame(1, $result['units']);
        $this->assertSame(2, $result['items'], 'the second reading is in an unchosen unit');

        $units = db_connect()->table('learning_units')->where('module_id', $result['module_id'])->get()->getResultArray();
        $this->assertCount(1, $units);
        $titles = array_column(db_connect()->table('learning_items')->where('learning_unit_id', (int) $units[0]['id'])->orderBy('order_index')->get()->getResultArray(), 'title');
        $this->assertSame(['Read me', 'Quiz 1'], $titles);
    }

    public function testAUnitCanBeCopiedWithNoItemsAtAll(): void
    {
        $result = $this->copyModule(['offering_id' => $this->b], [$this->unit2], []);

        $this->assertSame(1, $result['units']);
        $this->assertSame(0, $result['items']);
    }

    public function testAQuizCopiesWithItsQuestionsRubricAndSettingsAsADraftWithShiftedDates(): void
    {
        $result = $this->copyModule(['offering_id' => $this->b]);

        $new = db_connect()->table('assessments')->where('module_id', $result['module_id'])->where('title', 'Quiz 1')->get()->getRowArray();

        $this->assertSame('Draft', $new['status']);
        $this->assertSame((int) $this->row('course_offerings', $this->b)['course_id'], (int) $new['course_id']);
        $this->assertSame((int) $this->instructor->id, (int) $new['created_by']);
        $this->assertSame(2, $this->rows('assessment_questions', ['assessment_id' => $new['id']]));
        $this->assertSame(5, db_connect()->table('assessment_question_choices')->join('assessment_questions', 'assessment_questions.id = assessment_question_choices.question_id')->where('assessment_questions.assessment_id', $new['id'])->countAllResults());
        $this->assertSame(1, $this->rows('assessment_rubrics', ['assessment_id' => $new['id']]));
        $this->assertSame(1, db_connect()->table('assessment_rubric_criteria')->join('assessment_rubrics', 'assessment_rubrics.id = assessment_rubric_criteria.rubric_id')->where('assessment_rubrics.assessment_id', $new['id'])->countAllResults());

        // The terms start 31 days apart (Aug 1 and Sept 1).
        $this->assertSame('2026-09-10 08:00:00', $new['available_from']);
        $this->assertSame('2026-09-20 17:00:00', $new['due_date']);

        // The old one is untouched.
        $this->assertSame('Published', $this->row('assessments', $this->quizAssessment)['status']);
    }

    public function testSpecificStudentsIsSwitchedOffOnACopiedAssessment(): void
    {
        $result = $this->copyModule(['offering_id' => $this->b]);

        $new = db_connect()->table('assessments')->where('module_id', $result['module_id'])->where('title', 'Quiz 1')->get()->getRowArray();

        $this->assertSame(0, (int) db_connect()->table('assessment_settings')->where('assessment_id', $new['id'])->get()->getRowArray()['assign_to_specific_students']);
    }

    public function testAFileIsReferencedNotDuplicated(): void
    {
        $file = CourseFactory::file($this->instructor);
        CourseFactory::resource($this->reading, 'pdf', null, (int) $file['id']);
        $before = $this->rows('files', []);

        $result = $this->copyModule(['offering_id' => $this->b]);

        $newReading = db_connect()->table('learning_items')->where('title', 'Read me')->where('id !=', $this->reading)->get()->getRowArray();
        $this->assertSame((int) $file['id'], (int) db_connect()->table('learning_resources')->where('learning_item_id', $newReading['id'])->get()->getRowArray()['file_id']);
        $this->assertSame($before, $this->rows('files', []));
        $this->assertTrue($result['success']);
    }

    public function testADiscussionItemGetsItsOwnEmptyThreadInTheNewOffering(): void
    {
        $result = $this->copyModule(['offering_id' => $this->b]);

        $newItem = db_connect()->table('learning_items')->where('title', 'Talk')->where('id !=', $this->discussion)->get()->getRowArray();
        $thread  = db_connect()->table('discussion_threads')->where('learning_item_id', $newItem['id'])->get()->getRowArray();

        $this->assertSame($this->b, (int) $thread['offering_id']);
        $this->assertSame('What do you think?', $thread['body']);
        $this->assertSame(0, (int) $thread['reply_count']);
        $this->assertTrue($result['success']);
    }

    public function testNothingOfTheStudentsIsCopied(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->a, $student);
        CourseFactory::progress($this->reading, $student);
        AssessmentFactory::attempt($this->quizAssessment, $student);

        $this->copyModule(['offering_id' => $this->b]);

        $this->assertSame(1, $this->rows('learning_completions', []));
        $this->assertSame(1, $this->rows('assessment_submissions', []));
    }

    public function testAScheduledPublishIsNotCopied(): void
    {
        db_connect()->table('course_modules')->where('id', $this->module)->update(['status' => 'draft', 'scheduled_publish_at' => '2030-01-01 09:00:00']);

        $result = $this->copyModule(['offering_id' => $this->b]);

        $this->assertNull($this->row('course_modules', $result['module_id'])['scheduled_publish_at']);
    }

    /*
    |--------------------------------------------------------------------------
    | Dependencies
    |--------------------------------------------------------------------------
    */

    public function testItemsCopiedTogetherKeepWhatTheyWaitedOn(): void
    {
        db_connect()->table('learning_items')->where('id', $this->quiz)->update(['prerequisite_mode' => 'previous']);
        db_connect()->table('learning_items')->where('id', $this->assignment)->update(['prerequisite_mode' => 'custom', 'dependency_min_score_mode' => 'custom', 'dependency_min_score_percent' => 70]);
        CourseFactory::requires($this->assignment, $this->quiz);

        $result = $this->copyModule(['offering_id' => $this->b]);

        $new = static fn (string $title) => db_connect()->table('learning_items')->where('title', $title)->where('learning_unit_id !=', 0)->orderBy('id', 'DESC')->get()->getRowArray();

        $this->assertSame('previous', $new('Quiz 1')['prerequisite_mode']);
        $essay = $new('Essay');
        $this->assertSame('custom', $essay['prerequisite_mode']);
        $this->assertSame('custom', $essay['dependency_min_score_mode']);
        $this->assertSame(70.0, (float) $essay['dependency_min_score_percent']);
        $link = db_connect()->table('learning_item_prerequisites')->where('learning_item_id', $essay['id'])->get()->getRowArray();
        $this->assertSame((int) $new('Quiz 1')['id'], (int) $link['prerequisite_item_id'], 'it points at the copy of the quiz, not the old one');
        $this->assertTrue($result['success']);
    }

    public function testADependencyOnSomethingNotCopiedIsDropped(): void
    {
        db_connect()->table('learning_items')->where('id', $this->quiz)->update(['prerequisite_mode' => 'previous']);
        db_connect()->table('learning_items')->where('id', $this->assignment)->update(['prerequisite_mode' => 'custom', 'dependency_min_score_mode' => 'passing']);
        CourseFactory::requires($this->assignment, $this->quiz);

        // The reading before the quiz, and the quiz itself, are left out.
        $this->copyModule(['offering_id' => $this->b], [$this->unit1], [$this->assignment, $this->discussion]);

        $essay = db_connect()->table('learning_items')->where('title', 'Essay')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('none', $essay['prerequisite_mode']);
        $this->assertSame('none', $essay['dependency_min_score_mode']);
        $this->assertSame(0, $this->rows('learning_item_prerequisites', ['learning_item_id' => $essay['id']]));
    }

    public function testAPreviousDependencyIsDroppedWhenTheItemBeforeItIsLeftOut(): void
    {
        db_connect()->table('learning_items')->where('id', $this->quiz)->update(['prerequisite_mode' => 'previous']);

        $this->copyModule(['offering_id' => $this->b], [$this->unit1], [$this->quiz, $this->assignment]);

        $this->assertSame('none', db_connect()->table('learning_items')->where('title', 'Quiz 1')->orderBy('id', 'DESC')->get()->getRowArray()['prerequisite_mode']);
    }

    public function testUnitsCopiedTogetherKeepTheirDependencies(): void
    {
        db_connect()->table('learning_units')->where('id', $this->unit2)->update(['prerequisite_mode' => 'custom']);
        db_connect()->table('learning_unit_prerequisites')->insert(['learning_unit_id' => $this->unit2, 'prerequisite_unit_id' => $this->unit1, 'created_at' => date('Y-m-d H:i:s')]);

        $result = $this->copyModule(['offering_id' => $this->b]);

        $units = db_connect()->table('learning_units')->where('module_id', $result['module_id'])->orderBy('order_index')->get()->getResultArray();
        $this->assertSame('custom', $units[1]['prerequisite_mode']);
        $this->assertSame((int) $units[0]['id'], (int) db_connect()->table('learning_unit_prerequisites')->where('learning_unit_id', $units[1]['id'])->get()->getRowArray()['prerequisite_unit_id']);
    }

    public function testTheModulesOwnDependencyIsNotCopied(): void
    {
        $first = CourseFactory::module($this->a, 'published', 0);
        db_connect()->table('course_modules')->where('id', $this->module)->update(['prerequisite_mode' => 'previous']);

        $result = $this->copyModule(['offering_id' => $this->b]);

        $this->assertSame('none', $this->row('course_modules', $result['module_id'])['prerequisite_mode']);
        $this->assertNotSame($first, $result['module_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Copy a unit and an item
    |--------------------------------------------------------------------------
    */

    public function testAUnitIsCopiedIntoTheChosenModuleWithTheChosenItems(): void
    {
        $target = CourseFactory::module($this->b, 'published', 1);

        $result = $this->service->copy($this->instructor, $this->a, 'unit', $this->unit1, ['offering_id' => $this->b, 'module_id' => $target], null, [$this->reading, $this->assignment]);

        $this->assertTrue($result['success'], (string) $result['message']);
        $unit = $this->row('learning_units', $result['unit_id']);
        $this->assertSame($target, (int) $unit['module_id']);
        $this->assertSame('draft', $unit['status']);
        $this->assertSame(2, $result['items']);
        $this->assertSame(1, $result['assessments']);
        $this->assertSame(2, $this->rows('learning_items', ['learning_unit_id' => $result['unit_id']]));
    }

    public function testAUnitCopiedIntoItsOwnModuleSaysCopy(): void
    {
        $result = $this->service->copy($this->instructor, $this->a, 'unit', $this->unit2, ['offering_id' => $this->a, 'module_id' => $this->module]);

        $this->assertSame($this->row('learning_units', $this->unit2)['title'] . ' (copy)', $this->row('learning_units', $result['unit_id'])['title']);
        $this->assertSame(3, $this->rows('learning_units', ['module_id' => $this->module]));
    }

    public function testAnItemIsCopiedIntoTheChosenUnit(): void
    {
        $module = CourseFactory::module($this->b);
        $unit   = CourseFactory::unit($module);

        $result = $this->service->copy($this->instructor, $this->a, 'item', $this->quiz, ['offering_id' => $this->b, 'module_id' => $module, 'unit_id' => $unit]);

        $this->assertTrue($result['success'], (string) $result['message']);
        $item = $this->row('learning_items', $result['item_id']);
        $this->assertSame($unit, (int) $item['learning_unit_id']);
        $this->assertSame('draft', $item['status']);
        $this->assertSame('none', $item['prerequisite_mode']);
        $this->assertSame(1, $result['assessments']);
        $assessment = db_connect()->table('assessments')->where('learning_item_id', $result['item_id'])->get()->getRowArray();
        $this->assertSame($module, (int) $assessment['module_id']);
        $this->assertSame('Draft', $assessment['status']);
    }

    public function testAnItemCopiedIntoItsOwnUnitSaysCopyAndGoesLast(): void
    {
        $result = $this->service->copy($this->instructor, $this->a, 'item', $this->reading, ['offering_id' => $this->a, 'module_id' => $this->module, 'unit_id' => $this->unit1]);

        $item = $this->row('learning_items', $result['item_id']);
        $this->assertSame('Read me (copy)', $item['title']);
        $this->assertSame(5, (int) $item['order_index']);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    public function testTheTargetMustBeAnOfferingThePersonTeaches(): void
    {
        $theirs = $this->siblingOffering($this->a, '2026-09-01');

        $result = $this->copyModule(['offering_id' => $theirs]);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->rows('course_modules', ['offering_id' => $this->a]));
        $this->assertSame(0, $this->rows('course_modules', ['offering_id' => $theirs]));
    }

    public function testAnotherInstitutionIsRefused(): void
    {
        $foreign = CourseFactory::offering('Z');
        CourseFactory::assignInstructor($foreign, $this->instructor);

        $this->assertFalse($this->copyModule(['offering_id' => $foreign])['success']);
        $this->assertSame(0, $this->rows('course_modules', ['offering_id' => $foreign]));
    }

    public function testAnArchivedTargetIsRefused(): void
    {
        db_connect()->table('course_offerings')->where('id', $this->b)->update(['status' => 'archived']);

        $this->assertFalse($this->copyModule(['offering_id' => $this->b])['success']);
    }

    public function testSomeoneWhoDoesNotTeachTheSourceIsRefused(): void
    {
        $stranger = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->b, $stranger);

        $result = $this->service->copy($stranger, $this->a, 'module', $this->module, ['offering_id' => $this->b]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->rows('course_modules', ['offering_id' => $this->b]));
    }

    public function testASourceFromAnotherOfferingIsNotFound(): void
    {
        $result = $this->service->copy($this->instructor, $this->b, 'module', $this->module, ['offering_id' => $this->a]);

        $this->assertFalse($result['success']);
        $this->assertSame('Module not found.', $result['message']);
    }

    public function testAUnitNeedsAModuleOfTheTargetOffering(): void
    {
        $wrong = CourseFactory::module($this->a, 'published', 9);

        $this->assertFalse($this->service->copy($this->instructor, $this->a, 'unit', $this->unit1, ['offering_id' => $this->b])['success']);
        $this->assertFalse($this->service->copy($this->instructor, $this->a, 'unit', $this->unit1, ['offering_id' => $this->b, 'module_id' => $wrong])['success']);
    }

    public function testAnItemNeedsAUnitOfTheChosenModule(): void
    {
        $module = CourseFactory::module($this->b);
        $other  = CourseFactory::unit(CourseFactory::module($this->b, 'published', 2));

        $result = $this->service->copy($this->instructor, $this->a, 'item', $this->reading, ['offering_id' => $this->b, 'module_id' => $module, 'unit_id' => $other]);

        $this->assertFalse($result['success']);
        $this->assertSame('Choose a learning unit.', $result['message']);
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->assertFalse($this->service->copy($this->instructor, $this->a, 'course', 1, ['offering_id' => $this->b])['success']);
        $this->assertFalse($this->service->move($this->instructor, $this->a, 'course', 1, ['offering_id' => $this->b])['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | Move
    |--------------------------------------------------------------------------
    */

    public function testAModuleMovesToAnotherOfferingAsADraftWithEverythingInIt(): void
    {
        $result = $this->service->move($this->instructor, $this->a, 'module', $this->module, ['offering_id' => $this->b]);

        $this->assertTrue($result['success'], (string) $result['message']);

        $module = $this->row('course_modules', $this->module);
        $this->assertSame($this->b, (int) $module['offering_id']);
        $this->assertSame('draft', $module['status']);
        $this->assertSame(0, $this->rows('course_modules', ['offering_id' => $this->a]));
        $this->assertSame(4, $this->rows('learning_items', ['learning_unit_id' => $this->unit1]), 'its items went with it');

        $bCourse = (int) $this->row('course_offerings', $this->b)['course_id'];
        $this->assertSame($bCourse, (int) $this->row('assessments', $this->quizAssessment)['course_id']);
        $this->assertSame($this->module, (int) $this->row('assessments', $this->quizAssessment)['module_id']);
        $this->assertSame($this->b, (int) db_connect()->table('discussion_threads')->where('learning_item_id', $this->discussion)->get()->getRowArray()['offering_id']);
    }

    public function testAMoveIntoTheSameOfferingIsRefused(): void
    {
        $result = $this->service->move($this->instructor, $this->a, 'module', $this->module, ['offering_id' => $this->a]);

        $this->assertFalse($result['success']);
        $this->assertSame($this->a, (int) $this->row('course_modules', $this->module)['offering_id']);
    }

    public function testStudentWorkStopsAMoveAndNothingChanges(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->a, $student);
        CourseFactory::progress($this->reading, $student);
        AssessmentFactory::attempt($this->quizAssessment, $student);

        $result = $this->service->move($this->instructor, $this->a, 'module', $this->module, ['offering_id' => $this->b]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2 items', $result['message']);
        $this->assertStringContainsString('Copy it instead', $result['message']);
        $this->assertSame($this->a, (int) $this->row('course_modules', $this->module)['offering_id']);
        $this->assertSame('published', $this->row('course_modules', $this->module)['status']);
    }

    public function testADiscussionReplyCountsAsStudentWork(): void
    {
        $student = CourseFactory::user('student');
        $thread  = (int) db_connect()->table('discussion_threads')->where('learning_item_id', $this->discussion)->get()->getRowArray()['id'];
        db_connect()->table('discussion_replies')->insert(['thread_id' => $thread, 'author_id' => $student->id, 'body' => 'hi', 'created_at' => date('Y-m-d H:i:s')]);

        $module = CourseFactory::module($this->b);

        $result = $this->service->move($this->instructor, $this->a, 'item', $this->discussion, ['offering_id' => $this->b, 'module_id' => $module, 'unit_id' => CourseFactory::unit($module)]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('1 item', $result['message']);
        $this->assertSame($this->unit1, (int) $this->row('learning_items', $this->discussion)['learning_unit_id']);
    }

    public function testWorkOnOtherItemsDoesNotStopAnItemMove(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->a, $student);
        CourseFactory::progress($this->reading, $student);
        $module = CourseFactory::module($this->b);
        $unit   = CourseFactory::unit($module);

        $result = $this->service->move($this->instructor, $this->a, 'item', $this->reading2, ['offering_id' => $this->b, 'module_id' => $module, 'unit_id' => $unit]);

        $this->assertTrue($result['success'], (string) $result['message']);
    }

    public function testModulesThatWaitedOnTheMovedOneAreFreed(): void
    {
        $after = CourseFactory::module($this->a, 'published', 2);
        db_connect()->table('course_modules')->where('id', $after)->update(['prerequisite_mode' => 'custom']);
        db_connect()->table('course_module_prerequisites')->insert(['module_id' => $after, 'prerequisite_module_id' => $this->module, 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('course_modules')->where('id', $this->module)->update(['prerequisite_mode' => 'none']);

        $this->service->move($this->instructor, $this->a, 'module', $this->module, ['offering_id' => $this->b]);

        $this->assertSame('none', $this->row('course_modules', $after)['prerequisite_mode']);
        $this->assertSame(0, $this->rows('course_module_prerequisites', ['module_id' => $after]));
    }

    public function testAUnitMovesToAModuleOfAnotherOffering(): void
    {
        $target = CourseFactory::module($this->b);
        db_connect()->table('learning_units')->where('id', $this->unit2)->update(['prerequisite_mode' => 'previous']);

        $result = $this->service->move($this->instructor, $this->a, 'unit', $this->unit2, ['offering_id' => $this->b, 'module_id' => $target]);

        $this->assertTrue($result['success'], (string) $result['message']);
        $unit = $this->row('learning_units', $this->unit2);
        $this->assertSame($target, (int) $unit['module_id']);
        $this->assertSame('draft', $unit['status']);
        $this->assertSame('none', $unit['prerequisite_mode'], 'what ties it to the old module is gone');
    }

    public function testAUnitsAssessmentsMoveToTheNewModuleAndCourse(): void
    {
        $target = CourseFactory::module($this->b);

        $this->service->move($this->instructor, $this->a, 'unit', $this->unit1, ['offering_id' => $this->b, 'module_id' => $target]);

        $assessment = $this->row('assessments', $this->quizAssessment);
        $this->assertSame($target, (int) $assessment['module_id']);
        $this->assertSame((int) $this->row('course_offerings', $this->b)['course_id'], (int) $assessment['course_id']);
    }

    public function testAnItemMovesAndItsAssessmentBecomesADraft(): void
    {
        $module = CourseFactory::module($this->b);
        $unit   = CourseFactory::unit($module);

        $result = $this->service->move($this->instructor, $this->a, 'item', $this->quiz, ['offering_id' => $this->b, 'module_id' => $module, 'unit_id' => $unit]);

        $this->assertTrue($result['success'], (string) $result['message']);
        $this->assertSame($unit, (int) $this->row('learning_items', $this->quiz)['learning_unit_id']);
        $this->assertSame('draft', $this->row('learning_items', $this->quiz)['status']);
        $this->assertSame('Draft', $this->row('assessments', $this->quizAssessment)['status']);
        $this->assertSame($module, (int) $this->row('assessments', $this->quizAssessment)['module_id']);
    }

    public function testAnItemsDependenciesAreCutOnBothSidesWhenItMoves(): void
    {
        db_connect()->table('learning_items')->where('id', $this->assignment)->update(['prerequisite_mode' => 'custom']);
        CourseFactory::requires($this->assignment, $this->quiz);
        $module = CourseFactory::module($this->b);
        $unit   = CourseFactory::unit($module);

        $this->service->move($this->instructor, $this->a, 'item', $this->quiz, ['offering_id' => $this->b, 'module_id' => $module, 'unit_id' => $unit]);

        $this->assertSame(0, $this->rows('learning_item_prerequisites', []));
        $this->assertSame('none', $this->row('learning_items', $this->assignment)['prerequisite_mode'], 'the item left behind no longer waits on it');
    }
}

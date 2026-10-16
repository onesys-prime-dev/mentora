<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuestionGenerationService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Who may act on a generated batch, whether AI generation is on at all, what
 * an edit of a staged question may contain, and what is audited.
 *
 * @internal
 */
final class QuestionGenerationSafetyTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionGenerationService $service;
    private int $offering;
    private int $quiz;
    private User $owner;
    private User $coTeacher;
    private int $batch;
    private int $generated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QuestionGenerationService(null, false);
        $this->offering = CourseFactory::offering();
        $this->owner = CourseFactory::user('instructor');
        $this->coTeacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->owner);
        CourseFactory::assignInstructor($this->offering, $this->coTeacher);
        $this->quiz = CourseFactory::assessment($this->offering, $this->owner, CourseFactory::module($this->offering));

        $db = db_connect();
        $db->table('ai_generation_batches')->insert([
            'assessment_id' => $this->quiz,
            'course_id'     => (int) $db->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'],
            'source_type'   => 'learning_unit', 'status' => 'completed', 'requested_count' => 1, 'generated_count' => 1,
            'created_by'    => $this->owner->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->batch = (int) $db->insertID();
        $db->table('ai_generated_questions')->insert([
            'batch_id' => $this->batch, 'question_text' => 'Which planet is largest?', 'question_type' => 'Short Answer',
            'points' => 1, 'accepted_answers' => json_encode(['Jupiter']), 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->generated = (int) $db->insertID();
    }

    public function testOnlyTheCreatorOwnsABatchOfTheOfferingInTheUrl(): void
    {
        $this->assertTrue($this->service->ownsBatch($this->owner, $this->batch, $this->offering));
        $this->assertFalse($this->service->ownsBatch($this->coTeacher, $this->batch, $this->offering), 'a co-teacher of the same section does not own it');
        $this->assertFalse($this->service->ownsBatch($this->owner, $this->batch, CourseFactory::offering('B')), 'not through another course');
        $this->assertFalse($this->service->ownsBatch($this->owner, 999999, $this->offering));
    }

    public function testAGeneratedQuestionFollowsItsBatch(): void
    {
        $this->assertTrue($this->service->ownsGeneratedQuestion($this->owner, $this->generated, $this->offering));
        $this->assertFalse($this->service->ownsGeneratedQuestion($this->coTeacher, $this->generated, $this->offering));
        $this->assertFalse($this->service->ownsGeneratedQuestion($this->owner, 999999, $this->offering));
    }

    public function testGenerationIsRefusedWhenTheInstitutionSwitchedItOff(): void
    {
        $institution = (int) db_connect()->table('course_offerings co')
            ->select('d.institution_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('co.id', $this->offering)->get()->getRowArray()['institution_id'];

        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $this->owner->id]);

        db_connect()->table('feature_flag_overrides')->insert([
            'flag_key' => 'ai.question_generation', 'institution_id' => $institution, 'enabled' => 0, 'note' => 'test',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->service->startBatch($this->owner, [
            'assessment_id' => $this->quiz, 'course_id' => 1, 'offering_id' => $this->offering, 'count' => 3,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('disabled', $result['code']);
        $this->assertSame(1, db_connect()->table('ai_generation_batches')->countAllResults(), 'no batch was created');
    }

    public function testAnEditThatBreaksTheLimitsIsRefusedAndNothingChanges(): void
    {
        $bad = [
            ['question_type' => 'Discussion'],
            ['question_type' => 'Robot'],
            ['points' => -1],
            ['points' => 'lots'],
            ['points' => 5000],
            ['question_text' => '   '],
            ['question_text' => str_repeat('x', 5001)],
            ['choices' => array_fill(0, 9, ['text' => 'a', 'is_correct' => false])],
            ['choices' => ['not an object']],
            ['choices' => [['text' => ['nested']]]],
        ];

        foreach ($bad as $edit) {
            $result = $this->service->updateGeneratedQuestion($this->generated, $edit);
            $this->assertFalse($result['success'], json_encode($edit));
        }

        $row = db_connect()->table('ai_generated_questions')->where('id', $this->generated)->get()->getRowArray();

        $this->assertSame('Which planet is largest?', $row['question_text']);
        $this->assertSame('Short Answer', $row['question_type']);
    }

    public function testAGoodEditStillWorks(): void
    {
        $result = $this->service->updateGeneratedQuestion($this->generated, ['question_text' => 'Which is the biggest planet?', 'points' => '2.5']);

        $this->assertTrue($result['success']);
        $this->assertSame('Which is the biggest planet?', $result['question']['question_text']);
    }

    public function testAcceptingAndCancellingAreAudited(): void
    {
        $this->service->acceptAll($this->owner, $this->batch);
        $this->service->cancelBatch($this->owner, $this->batch);

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->get()->getResultArray(), 'action');

        $this->assertContains('ai.batch.accepted', $actions);
        $this->assertContains('ai.batch.cancelled', $actions);
    }

    private function stage(array $row): int
    {
        db_connect()->table('ai_generated_questions')->insert($row + [
            'batch_id' => $this->batch, 'question_type' => 'Essay', 'points' => 1, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    public function testTheTrayMarksAQuestionThatRepeatsOneAlreadyInTheQuiz(): void
    {
        \Tests\Support\AssessmentFactory::question($this->quiz, $this->owner, 'Essay', 1, ['question_text' => 'Explain how a packet crosses a router.']);
        $this->stage(['question_text' => 'Explain how a packet crosses a router, please.']);

        $questions = $this->service->getBatch($this->batch)['questions'];
        $twin = array_values(array_filter($questions, static fn (array $q): bool => $q['duplicate_of'] !== null));

        $this->assertCount(1, $twin);
        $this->assertTrue($twin[0]['needs_look']);
    }

    public function testEvidenceAndVerificationAreKeptAndDecoded(): void
    {
        $id = $this->stage([
            'question_text' => 'Name the largest planet.', 'question_type' => 'Short Answer',
            'evidence_quote' => 'Jupiter is the largest planet.', 'source_title' => 'Planets', 'source_page' => 4,
            'verification' => json_encode(['solver_agrees' => true, 'grounded' => true]),
            'flags' => json_encode(['recall']),
        ]);

        $row = (new \App\Models\Assessments\AiGeneratedQuestionModel())->getDecoded($id);

        $this->assertSame(4, (int) $row['source_page']);
        $this->assertTrue($row['verification']['solver_agrees']);
        $this->assertSame(['recall'], $row['flags']);
    }

    public function testAcceptAllVerifiedLeavesOutQuestionsThatNeedALook(): void
    {
        $clear = $this->stage(['question_text' => 'Describe the water cycle in your own words.', 'verification' => json_encode(['solver_agrees' => true])]);
        $wrong = $this->stage(['question_text' => 'Compute twelve times eleven today.', 'math_check' => 'mismatch']);
        $disagreed = $this->stage(['question_text' => 'Argue for a different sorting method.', 'verification' => json_encode(['solver_agrees' => false])]);

        $result = $this->service->acceptAll($this->owner, $this->batch, true);

        $this->assertSame(2, $result['accepted_count'], 'the original short answer and the clear one');
        $this->assertSame(2, $result['skipped_count']);

        $status = static fn (int $id): string => (string) db_connect()->table('ai_generated_questions')->where('id', $id)->get()->getRowArray()['status'];

        $this->assertSame('accepted', $status($clear));
        $this->assertSame('pending', $status($wrong));
        $this->assertSame('pending', $status($disagreed));
    }

    public function testAcceptAllWithoutTheGuardStillAddsEverything(): void
    {
        $this->stage(['question_text' => 'Compute twelve times eleven today.', 'math_check' => 'mismatch']);

        $result = $this->service->acceptAll($this->owner, $this->batch);

        $this->assertSame(2, $result['accepted_count']);
        $this->assertSame(0, $result['skipped_count']);
    }

    public function testDiscardAllThrowsAwayWhatIsWaitingAndKeepsWhatWasAccepted(): void
    {
        $accepted = $this->stage(['question_text' => 'Already added to the quiz earlier.', 'status' => 'accepted']);
        $waitingA = $this->stage(['question_text' => 'First waiting question about routers.']);
        $waitingB = $this->stage(['question_text' => 'Second waiting question about switches.']);

        $result = $this->service->discardAll($this->owner, $this->batch);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['discarded'], 'the one from setUp plus the two staged');

        $status = static fn (int $id): string => (string) db_connect()->table('ai_generated_questions')->where('id', $id)->get()->getRowArray()['status'];

        $this->assertSame('accepted', $status($accepted));
        $this->assertSame('discarded', $status($waitingA));
        $this->assertSame('discarded', $status($waitingB));
        $this->assertSame([], $this->service->getBatch($this->batch)['questions']);
    }

    public function testDiscardAllIsAuditedAndRefusesAnUnknownBatch(): void
    {
        $this->service->discardAll($this->owner, $this->batch);

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->get()->getResultArray(), 'action');

        $this->assertContains('ai.batch.discarded', $actions);
        $this->assertFalse($this->service->discardAll($this->owner, 999999)['success']);
    }
}

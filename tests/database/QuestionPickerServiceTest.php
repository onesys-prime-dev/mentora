<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\QuestionPickerService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Adding questions from another quiz: only quizzes of sections the person
 * teaches or chairs are offered, what is copied is the question with its
 * choices, key and settings, and a quiz that is not theirs is simply not found.
 *
 * @internal
 */
final class QuestionPickerServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private QuestionPickerService $picker;
    private User $teacher;
    private int $offering;
    private int $source;
    private int $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->picker = new QuestionPickerService();
        $this->offering = CourseFactory::offering();
        $this->teacher = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->teacher);

        $module = CourseFactory::module($this->offering);
        $this->source = CourseFactory::assessment($this->offering, $this->teacher, $module);
        $this->target = CourseFactory::assessment($this->offering, $this->teacher, $module);

        db_connect()->table('assessments')->where('id', $this->source)->update(['title' => 'Midterm 2025']);
    }

    private function sourceQuestions(): array
    {
        $mc = AssessmentFactory::multipleChoice($this->source, $this->teacher, [true, true, false], 4);
        db_connect()->table('assessment_questions')->where('id', $mc['question'])->update([
            'question_text' => 'Pick the even numbers', 'answers_count' => 2,
        ]);
        $short = AssessmentFactory::shortAnswer($this->source, $this->teacher, ['CPU', 'Central Processing Unit'], 2, true);
        db_connect()->table('assessment_questions')->where('id', $short)->update(['question_text' => 'What does CPU stand for?']);
        $essay = AssessmentFactory::question($this->source, $this->teacher, 'Essay', 6, ['question_text' => 'Discuss routing']);

        return ['mc' => $mc['question'], 'short' => $short, 'essay' => $essay];
    }

    private function targetQuestions(): array
    {
        return db_connect()->table('assessment_questions')->where('assessment_id', $this->target)->orderBy('order_index')->get()->getResultArray();
    }

    public function testOnlyQuizzesWithQuestionsOfMySectionsAreOffered(): void
    {
        $this->sourceQuestions();

        $stranger = CourseFactory::user('instructor');
        $otherOffering = CourseFactory::offering();
        CourseFactory::assignInstructor($otherOffering, $stranger);
        $foreign = CourseFactory::assessment($otherOffering, $stranger, CourseFactory::module($otherOffering));
        AssessmentFactory::question($foreign, $stranger, 'Essay', 1);

        $sources = $this->picker->sources($this->teacher, $this->target);

        $this->assertSame([$this->source], array_column($sources, 'id'), 'not the target itself, not an empty quiz, not another teacher\'s');
        $this->assertSame('Midterm 2025', $sources[0]['title']);
        $this->assertSame(3, $sources[0]['questions']);
        $this->assertNotSame('', $sources[0]['course']);
    }

    public function testQuestionsOfASourceAreListedWithTheirAnswersAndAFlagForOnesAlreadyInTheQuiz(): void
    {
        $this->sourceQuestions();
        AssessmentFactory::question($this->target, $this->teacher, 'Essay', 1, ['question_text' => '  discuss   ROUTING ']);

        $questions = $this->picker->questionsOf($this->teacher, $this->source, $this->target);

        $this->assertCount(3, $questions);
        $this->assertSame('Pick the even numbers', $questions[0]['question_text']);
        $this->assertTrue($questions[0]['allow_multiple_answers']);
        $this->assertSame([true, true, false], array_column($questions[0]['choices'], 'is_correct'));
        $this->assertSame(['CPU', 'Central Processing Unit'], $questions[1]['accepted_answers']);
        $this->assertSame([false, false, true], array_column($questions, 'already_in_quiz'));
    }

    public function testAQuizThatIsNotMineHasNoQuestionsToShow(): void
    {
        $stranger = CourseFactory::user('instructor');
        $this->sourceQuestions();

        $this->assertNull($this->picker->questionsOf($stranger, $this->source, $this->target));
    }

    public function testChosenQuestionsAreCopiedWithTheirChoicesKeysAndSettings(): void
    {
        $ids = $this->sourceQuestions();
        AssessmentFactory::question($this->target, $this->teacher, 'Essay', 1, ['question_text' => 'Already here']);

        $result = $this->picker->copy($this->teacher, $this->target, $this->source, [$ids['short'], $ids['mc']]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['added']);
        $this->assertSame('2 questions were added.', $result['message']);

        $questions = $this->targetQuestions();
        $this->assertSame(['Already here', 'Pick the even numbers', 'What does CPU stand for?'], array_column($questions, 'question_text'), 'in the source\'s order, after what is there');

        $mc = $questions[1];
        $this->assertSame(1, (int) $mc['allow_multiple_answers']);
        $this->assertSame(2, (int) $mc['answers_count']);
        $this->assertEquals(4, $mc['points']);

        $choices = db_connect()->table('assessment_question_choices')->where('question_id', (int) $mc['id'])->orderBy('order_index')->get()->getResultArray();
        $this->assertSame([1, 1, 0], array_map(static fn (array $c): int => (int) $c['is_correct'], $choices));

        $short = (new \App\Models\Assessments\AssessmentQuestionModel())->getWithChoices((int) $questions[2]['id']);
        $this->assertSame(['CPU', 'Central Processing Unit'], $short['accepted_answers']);
        $this->assertSame(1, (int) $short['case_sensitive']);

        // The source is untouched.
        $this->assertSame(3, db_connect()->table('assessment_questions')->where('assessment_id', $this->source)->countAllResults());
    }

    public function testTheQuizTotalFollowsTheCopiedQuestions(): void
    {
        $ids = $this->sourceQuestions();

        $this->picker->copy($this->teacher, $this->target, $this->source, [$ids['mc'], $ids['essay']]);

        $this->assertEquals(10, db_connect()->table('assessments')->where('id', $this->target)->get()->getRow('total_points'));
    }

    public function testQuestionsOfAnotherQuizOrAnUnknownIdAreNeverCopied(): void
    {
        $ids = $this->sourceQuestions();
        $otherQuiz = CourseFactory::assessment($this->offering, $this->teacher, CourseFactory::module($this->offering));
        $elsewhere = AssessmentFactory::question($otherQuiz, $this->teacher, 'Essay', 1, ['question_text' => 'Belongs to a third quiz']);

        $result = $this->picker->copy($this->teacher, $this->target, $this->source, [$elsewhere, 999999, $ids['essay']]);

        $this->assertSame(1, $result['added']);
        $this->assertSame(['Discuss routing'], array_column($this->targetQuestions(), 'question_text'));

        $none = $this->picker->copy($this->teacher, $this->target, $this->source, [$elsewhere]);

        $this->assertFalse($none['success']);
    }

    public function testAQuizOfAnotherTeacherCannotBeCopiedFromAndNothingChanges(): void
    {
        $ids = $this->sourceQuestions();
        $stranger = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $stranger);
        $mine = CourseFactory::offering();
        CourseFactory::assignInstructor($mine, $stranger);
        $strangersQuiz = CourseFactory::assessment($mine, $stranger, CourseFactory::module($mine));

        // The stranger teaches the same section here, so may copy; a person with no
        // link to the section may not.
        $outsider = CourseFactory::user('instructor');
        $result = $this->picker->copy($outsider, $strangersQuiz, $this->source, [$ids['mc']]);

        $this->assertFalse($result['success']);
        $this->assertSame([], db_connect()->table('assessment_questions')->where('assessment_id', $strangersQuiz)->get()->getResultArray());
    }

    public function testCopyingFromTheQuizItselfOrWithNothingChosenIsRefused(): void
    {
        $ids = $this->sourceQuestions();

        $this->assertFalse($this->picker->copy($this->teacher, $this->source, $this->source, [$ids['mc']])['success']);
        $this->assertFalse($this->picker->copy($this->teacher, $this->target, $this->source, [])['success']);
        $this->assertFalse($this->picker->copy($this->teacher, 999999, $this->source, [$ids['mc']])['success']);
    }

    public function testAChairpersonMayTakeFromQuizzesOfTheSectionsTheyChair(): void
    {
        $ids = $this->sourceQuestions();

        $chair = CourseFactory::user('chairperson');
        $sub = (int) db_connect()->table('course_offerings co')
            ->select('p.sub_department_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->where('co.id', $this->offering)->get()->getRow('sub_department_id');
        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $sub, 'chairperson_user_id' => $chair->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertContains($this->source, array_column($this->picker->sources($chair, $this->target), 'id'));

        $result = $this->picker->copy($chair, $this->target, $this->source, [$ids['short']]);

        $this->assertTrue($result['success']);
    }

    public function testCopyingIsAudited(): void
    {
        $ids = $this->sourceQuestions();

        $this->picker->copy($this->teacher, $this->target, $this->source, [$ids['essay']]);

        $actions = array_column(db_connect()->table('audit_logs')->select('action')->get()->getResultArray(), 'action');

        $this->assertContains('quiz.questions.copied', $actions);
    }
}

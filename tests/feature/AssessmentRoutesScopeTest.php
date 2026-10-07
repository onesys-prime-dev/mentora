<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * Every route that takes an assessment, submission, question, rubric,
 * criterion or question-generation id must refuse ids that belong to another
 * section than the course in the URL, and anyone who is not assigned to (or
 * enrolled in) that course. Refusing means a 404 and not one row changed.
 *
 * Section A is "mine": an instructor and a student belong to it. Section B is
 * somebody else's, with its own quiz, assignment, questions, rubric and
 * generated questions.
 *
 * @internal
 */
final class AssessmentRoutesScopeTest extends RouteTestCase
{
    private int $mine;
    private int $theirs;
    private User $me;
    private User $outsider;
    private User $theirStudent;

    /** @var array<string, int> */
    private array $b = [];

    /** @var array<string, int> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = CourseFactory::offering('A');
        $this->theirs = CourseFactory::offering('B');
        $this->me = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->mine, $this->me);
        $theirInstructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->theirs, $theirInstructor);
        $this->outsider = CourseFactory::user('instructor');
        $this->theirStudent = CourseFactory::user('student');
        CourseFactory::enroll($this->theirs, $this->theirStudent);

        $this->b = $this->content($this->theirs, $theirInstructor, $this->theirStudent);

        $myStudent = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $myStudent);
        $this->a = $this->content($this->mine, $this->me, $myStudent);
    }

    /** @return array<string, int> */
    private function content(int $offering, User $instructor, User $student): array
    {
        $db = db_connect();
        $module = CourseFactory::module($offering);
        $unit = CourseFactory::unit($module);
        $quiz = CourseFactory::assessment($offering, $instructor, $module);
        AssessmentFactory::open($quiz, ['total_points' => 10]);
        $assignment = CourseFactory::assessment($offering, $instructor, $module, null, 'Assignment');
        AssessmentFactory::open($assignment, ['total_points' => 10]);
        $mc = AssessmentFactory::multipleChoice($quiz, $instructor, [true, false], 5);
        $essay = AssessmentFactory::question($quiz, $instructor, 'Essay', 5);
        $quizAttempt = AssessmentFactory::attempt($quiz, $student, 'Submitted');
        AssessmentFactory::response($quizAttempt, $essay, ['response_text' => 'My essay']);
        $submission = AssessmentFactory::attempt($assignment, $student, 'Submitted');
        $rubric = AssessmentFactory::rubric($assignment, $instructor, [10]);
        $quizRubric = AssessmentFactory::rubric($quiz, $instructor, [10]);

        $db->table('ai_generation_batches')->insert([
            'assessment_id' => $quiz, 'course_id' => (int) $db->table('course_offerings')->where('id', $offering)->get()->getRowArray()['course_id'],
            'source_type' => 'topic', 'topic' => 'Topic', 'status' => 'completed', 'created_by' => $instructor->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $batch = (int) $db->insertID();
        $db->table('ai_generated_questions')->insert([
            'batch_id' => $batch, 'question_text' => 'Generated', 'question_type' => 'Essay', 'points' => 2, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'offering' => $offering, 'module' => $module, 'unit' => $unit, 'quiz' => $quiz, 'assignment' => $assignment,
            'mc' => $mc['question'], 'essay' => $essay, 'quizAttempt' => $quizAttempt, 'submission' => $submission,
            'rubric' => $rubric['rubric'], 'criterion' => $rubric['criteria'][0], 'quizRubric' => $quizRubric['rubric'],
            'batch' => $batch, 'generated' => (int) $db->insertID(),
        ];
    }

    /** Fills a path template: {off} is the course in the URL, the rest are section B's ids. */
    private function path(string $template, int $offering, array $ids): string
    {
        $map = [
            '{off}' => $offering, '{quiz}' => $ids['quiz'], '{asg}' => $ids['assignment'], '{q}' => $ids['mc'], '{essay}' => $ids['essay'],
            '{attempt}' => $ids['quizAttempt'], '{sub}' => $ids['submission'], '{rubric}' => $ids['rubric'], '{crit}' => $ids['criterion'],
            '{quizrubric}' => $ids['quizRubric'], '{batch}' => $ids['batch'], '{gen}' => $ids['generated'], '{module}' => $ids['module'], '{unit}' => $ids['unit'],
        ];

        return 'instructor/api/courses/' . strtr($template, array_map('strval', $map));
    }

    private function send(User $user, string $verb, string $path, array $body = [])
    {
        $this->loggedInAs($user);

        return $this->withBody(json_encode($body))->withHeaders(['Content-Type' => 'application/json'])->call($verb, $path);
    }

    private function snapshot(): array
    {
        $db = db_connect();
        $tables = ['assessments', 'assessment_questions', 'assessment_question_choices', 'assessment_question_responses', 'assessment_submissions',
            'assessment_feedbacks', 'assessment_rubrics', 'assessment_rubric_criteria', 'ai_generation_batches', 'ai_generated_questions', 'gradebook_item_scores'];

        return array_map(static fn (string $t) => $db->table($t)->orderBy('id')->get()->getResultArray(), array_combine($tables, $tables));
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: array}> */
    public static function routes(): iterable
    {
        yield 'open the editor' => ['get', '{off}/assessments/{asg}/editor'];
        yield 'open the quiz builder' => ['get', '{off}/assessments/{quiz}/quiz-builder'];
        yield 'view' => ['get', '{off}/assessments/{asg}/view'];
        yield 'edit' => ['patch', '{off}/assessments/{asg}', ['title' => 'HACKED']];
        yield 'delete' => ['delete', '{off}/assessments/{asg}'];
        yield 'instructions' => ['patch', '{off}/assessments/{asg}/instructions', ['instructions' => ['summary' => 'HACKED']]];
        yield 'submission settings' => ['patch', '{off}/assessments/{asg}/submission-settings', ['allow_text_entry' => 1]];
        yield 'export' => ['get', '{off}/assessments/{quiz}/export'];
        yield 'import preview' => ['post', '{off}/assessments/{quiz}/import/preview'];
        yield 'import apply' => ['post', '{off}/assessments/{quiz}/import/apply', ['questions' => []]];

        yield 'list rubrics' => ['get', '{off}/assessments/{asg}/rubrics'];
        yield 'create a rubric' => ['post', '{off}/assessments/{asg}/rubrics', ['title' => 'HACKED']];
        yield 'show a rubric' => ['get', '{off}/rubrics/{rubric}'];
        yield 'edit a rubric' => ['patch', '{off}/rubrics/{rubric}', ['title' => 'HACKED']];
        yield 'delete a rubric' => ['delete', '{off}/rubrics/{rubric}'];
        yield 'add a criterion' => ['post', '{off}/rubrics/{rubric}/criteria', ['criteria_title' => 'HACKED', 'max_points' => 5]];
        yield 'edit a criterion' => ['patch', '{off}/rubric-criteria/{crit}', ['criteria_title' => 'HACKED']];
        yield 'delete a criterion' => ['delete', '{off}/rubric-criteria/{crit}'];

        yield 'list questions' => ['get', '{off}/assessments/{quiz}/questions'];
        yield 'add a question' => ['post', '{off}/assessments/{quiz}/questions', ['question_text' => 'HACKED', 'question_type' => 'Essay', 'points' => 1]];
        yield 'reorder questions' => ['patch', '{off}/assessments/{quiz}/questions/reorder', ['order' => []]];
        yield 'clear all questions' => ['delete', '{off}/assessments/{quiz}/questions'];
        yield 'show a question' => ['get', '{off}/questions/{q}'];
        yield 'edit a question' => ['patch', '{off}/questions/{q}', ['question_text' => 'HACKED']];
        yield 'delete a question' => ['delete', '{off}/questions/{q}'];
        yield 'duplicate a question' => ['post', '{off}/questions/{q}/duplicate'];

        yield 'open grade work' => ['get', '{off}/assessments/{asg}/grade-work'];
        yield 'open grade work for a student' => ['get', '{off}/assessments/{asg}/grade-work/1'];
        yield 'start grading' => ['patch', '{off}/submissions/{sub}/start-grading'];
        yield 'reopen grading' => ['patch', '{off}/submissions/{sub}/reopen-grading'];
        yield 'grade' => ['patch', '{off}/submissions/{sub}/grade', ['score' => 20, 'feedback' => 'HACKED']];
        yield 'grade a question' => ['patch', '{off}/submissions/{attempt}/questions/{essay}/grade', ['mode' => 'points', 'points' => 5]];
        yield 'post feedback' => ['post', '{off}/submissions/{sub}/feedback', ['message' => 'HACKED']];

        yield 'generation: next chunk' => ['post', '{off}/generation-batches/{batch}/next-chunk'];
        yield 'generation: show a batch' => ['get', '{off}/generation-batches/{batch}'];
        yield 'generation: edit a question' => ['patch', '{off}/generated-questions/{gen}', ['question_text' => 'HACKED']];
        yield 'generation: accept a question' => ['post', '{off}/generated-questions/{gen}/accept'];
        yield 'generation: discard a question' => ['post', '{off}/generated-questions/{gen}/discard'];
        yield 'generation: accept all' => ['post', '{off}/generation-batches/{batch}/accept-all'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAnInstructorCannotReachAnotherSectionsAssessmentWorkThroughTheirOwnCourseUrl(string $verb, string $template, array $body = []): void
    {
        $before = $this->snapshot();

        try {
            $this->send($this->me, $verb, $this->path($template, $this->mine, $this->b), $body);
            $this->fail("{$verb} {$template}: another section's content was reachable");
        } catch (PageNotFoundException) {
            $this->assertSame($before, $this->snapshot(), "{$verb} {$template} changed something");
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAnInstructorNotAssignedToTheCourseCannotReachItAtAll(string $verb, string $template, array $body = []): void
    {
        $before = $this->snapshot();

        try {
            $this->send($this->outsider, $verb, $this->path($template, $this->theirs, $this->b), $body);
            $this->fail("{$verb} {$template}: an unassigned instructor got in");
        } catch (PageNotFoundException) {
            $this->assertSame($before, $this->snapshot(), "{$verb} {$template} changed something");
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function missingIds(): iterable
    {
        yield 'assessment' => ['get', '{off}/assessments/999999/editor'];
        yield 'submission' => ['patch', '{off}/submissions/999999/grade'];
        yield 'question' => ['get', '{off}/questions/999999'];
        yield 'rubric' => ['get', '{off}/rubrics/999999'];
        yield 'criterion' => ['delete', '{off}/rubric-criteria/999999'];
        yield 'batch' => ['get', '{off}/generation-batches/999999'];
        yield 'generated question' => ['post', '{off}/generated-questions/999999/accept'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('missingIds')]
    public function testAnIdThatDoesNotExistIsNotFoundInsteadOfReachingTheController(string $verb, string $template): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->send($this->me, $verb, 'instructor/api/courses/' . str_replace('{off}', (string) $this->mine, $template));
    }

    public function testAnotherSectionsSubmissionAnswersAndQuizzesAreOutOfReachForItsOwnStudentsTooWhenTheUrlNamesAnotherCourse(): void
    {
        $student = CourseFactory::user('student');
        CourseFactory::enroll($this->mine, $student);
        $attempt = AssessmentFactory::attempt($this->b['quiz'], $student, 'In Progress');

        $this->expectException(PageNotFoundException::class);

        $this->send($student, 'post', "student/api/courses/{$this->mine}/submissions/{$attempt}/questions/{$this->b['essay']}/answer", ['response_text' => 'x']);
    }

    /*
    |--------------------------------------------------------------------------
    | Own section still works
    |--------------------------------------------------------------------------
    */

    public function testTheAssignedInstructorStillWorksOnTheirOwnSection(): void
    {
        $edit = $this->send($this->me, 'patch', $this->path('{off}/assessments/{asg}', $this->mine, $this->a), ['title' => 'Renamed'])->response();
        $this->assertSame(200, $edit->getStatusCode());
        $this->assertSame('Renamed', db_connect()->table('assessments')->where('id', $this->a['assignment'])->get()->getRowArray()['title']);

        $grade = $this->send($this->me, 'patch', $this->path('{off}/submissions/{sub}/grade', $this->mine, $this->a), ['score' => 8]);
        $this->assertTrue(json_decode((string) $grade->getJSON(), true)['success']);
        $this->assertEquals(8, db_connect()->table('assessment_submissions')->where('id', $this->a['submission'])->get()->getRowArray()['score']);

        $questions = $this->send($this->me, 'get', $this->path('{off}/assessments/{quiz}/questions', $this->mine, $this->a));
        $this->assertSame(200, $questions->response()->getStatusCode());

        $this->assertSame(200, $this->send($this->me, 'get', $this->path('{off}/assessments/{asg}/grade-work', $this->mine, $this->a))->response()->getStatusCode());
        $this->assertSame(200, $this->send($this->me, 'get', $this->path('{off}/generation-batches/{batch}', $this->mine, $this->a))->response()->getStatusCode());
        $this->assertSame(200, $this->send($this->me, 'get', $this->path('{off}/rubrics/{rubric}', $this->mine, $this->a))->response()->getStatusCode());
    }

    public function testAnAssessmentCanStillBeCreatedInOwnModule(): void
    {
        $typeId = (int) db_connect()->table('assessment_types')->where('name', 'Quiz')->get()->getRowArray()['id'];

        $this->loggedInAs($this->me);
        $response = $this->call('post', $this->path('{off}/modules/{module}/learning-units/{unit}/assessments', $this->mine, $this->a), ['title' => 'New quiz', 'assessment_type_id' => $typeId]);

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertSame(1, db_connect()->table('assessments')->where('title', 'New quiz')->countAllResults());
    }

    public function testAnAssessmentCannotBeCreatedInAnotherSectionsModule(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->send($this->me, 'post', $this->path('{off}/modules/{module}/learning-units/{unit}/assessments', $this->mine, $this->b), ['title' => 'Planted', 'assessment_type_id' => (int) db_connect()->table('assessment_types')->where('name', 'Quiz')->get()->getRowArray()['id']]);
    }

    public function testQuestionGenerationCannotBePointedAtAnotherSectionsAssessment(): void
    {
        $before = db_connect()->table('ai_generation_batches')->countAllResults();
        $this->loggedInAs($this->me);

        try {
            $this->call('post', "instructor/api/courses/{$this->mine}/generate-questions", ['assessment_id' => $this->b['quiz'], 'source_type' => 'topic', 'topic' => 'Planets', 'count' => 3]);
            $this->fail('a batch was started against another section\'s quiz');
        } catch (PageNotFoundException) {
            $this->assertSame($before, db_connect()->table('ai_generation_batches')->countAllResults());
        }
    }

    public function testQuestionGenerationStillStartsForOwnAssessmentOrNone(): void
    {
        $this->loggedInAs($this->me);

        foreach ([$this->a['quiz'], null] as $assessment) {
            $response = $this->call('post', "instructor/api/courses/{$this->mine}/generate-questions", ['assessment_id' => $assessment, 'source_type' => 'topic', 'topic' => 'Planets', 'count' => 3]);

            $this->assertSame(200, $response->response()->getStatusCode());
        }
    }

    public function testARubricOfAnotherSectionOfTheSameCourseCanStillBeCopied(): void
    {
        // Sections of one course share a rubric library: picking an existing
        // rubric to reuse is a feature, not a way into another section.
        $sibling = CourseFactory::offering('C');
        $this->assertNotSame($this->mine, $sibling);
        $sameCourse = (int) db_connect()->table('course_offerings')->where('id', $this->mine)->get()->getRowArray()['course_id'];
        db_connect()->table('course_offerings')->where('id', $sibling)->update(['course_id' => $sameCourse]);
        $siblingInstructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($sibling, $siblingInstructor);
        $other = $this->content($sibling, $siblingInstructor, CourseFactory::user('student'));

        $response = $this->send($this->me, 'post', "instructor/api/courses/{$this->mine}/assessments/{$this->a['assignment']}/rubrics/{$other['rubric']}/select");

        $this->assertSame(200, $response->response()->getStatusCode());
    }

    public function testARubricOfAnotherCourseCannotBeCopied(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->send($this->me, 'post', "instructor/api/courses/{$this->mine}/assessments/{$this->a['assignment']}/rubrics/{$this->b['rubric']}/select");
    }
}

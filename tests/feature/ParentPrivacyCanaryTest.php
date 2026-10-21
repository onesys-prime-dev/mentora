<?php

declare(strict_types=1);

use App\Services\AI\PdfTextExtractor;
use App\Services\Courses\Grades\GradingEngineService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Private things are planted in a child's course (per-item scores and names,
 * a submission with feedback, a discussion, a module, a resource) and every
 * page and API a parent can reach is read: none of it may come back.
 *
 * @internal
 */
final class ParentPrivacyCanaryTest extends RouteTestCase
{
    /** Words planted in private places. */
    private const WORDS = ['CANARYMODULE', 'CANARYRESOURCE', 'CANARYTHREAD', 'CANARYREPLY', 'CANARYFEEDBACK', 'CANARYSUBMISSION', 'CANARYQUIZITEM', 'CANARYEXAMA', 'CANARYEXAMB'];

    /** Per-item scores planted in the gradebook; the category totals built from them are allowed. */
    private const SCORES = ['8.37', '6.91', '61.23', '77.41'];

    private int $institution;

    private User $parent;

    private User $child;

    private int $offering;

    private int $dropped;

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        helper('url');
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = PeopleFactory::institution('PCA');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $teacher           = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);
        $classmate         = PeopleFactory::person('student', 'Cory', 'Classmate', null, $this->institution);
        $db                = db_connect();
        $now               = date('Y-m-d H:i:s');

        $db->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $this->child->id, 'created_at' => $now]);

        $this->offering = CourseFactory::offering('A');
        $this->dropped  = CourseFactory::offering('B');

        foreach ([$this->offering, $this->dropped] as $offering) {
            CourseFactory::assignInstructor($offering, $teacher);
        }

        CourseFactory::enroll($this->offering, $this->child);
        CourseFactory::enroll($this->offering, $classmate);
        CourseFactory::enroll($this->dropped, $this->child, 'dropped');

        // Learning material: a module with a unit, a reading and a resource.
        $module = CourseFactory::module($this->offering);
        $db->table('modules')->where('id', $module)->update(['title' => 'CANARYMODULE']);
        $unit   = CourseFactory::unit($module);
        [$item] = CourseFactory::items($unit, [['title' => 'CANARYRESOURCE', 'type' => 'reading']]);
        $db->table('learning_items')->where('id', $item)->update(['title' => 'CANARYRESOURCE']);

        // A discussion with a reply.
        $thread = CourseFactory::thread($this->offering, $classmate);
        $db->table('discussion_threads')->where('id', $thread)->update(['title' => 'CANARYTHREAD', 'body' => 'CANARYTHREAD body']);
        $db->table('discussion_replies')->insert(['thread_id' => $thread, 'author_id' => $classmate->id, 'body' => 'CANARYREPLY', 'created_at' => $now]);

        // A graded assignment with feedback (the assignment itself, its title, is allowed to show).
        $assignment = CourseFactory::assessment($this->offering, $teacher, $module, null, 'Assignment');
        $db->table('assessments')->where('id', $assignment)->update(['status' => 'Published', 'title' => 'Essay Assignment', 'total_points' => 10]);
        $db->table('assessment_submissions')->insert([
            'assessment_id' => $assignment, 'student_id' => $this->child->id, 'attempt_number' => 1, 'submitted_at' => $now,
            'score' => 7.0, 'percentage' => 70.0, 'status' => 'Graded', 'submission_text' => 'CANARYSUBMISSION',
        ]);
        $submission = (int) $db->insertID();
        $db->table('assessment_feedbacks')->insert(['submission_id' => $submission, 'author_id' => $teacher->id, 'author_role' => 'instructor', 'message' => 'CANARYFEEDBACK', 'created_at' => $now]);

        // A published gradebook: two items in a quiz category and two manual exams, all with distinctive scores.
        $scheme  = GradeFactory::scheme($this->offering, 75.0);
        $quizzes = GradeFactory::category($scheme, 'Quizzes', 40);
        $exams   = GradeFactory::category($scheme, 'Exams', 60, null, 2);
        $quiz    = CourseFactory::assessment($this->offering, $teacher, $module);
        $db->table('assessments')->where('id', $quiz)->update(['status' => 'Published', 'title' => 'Gradebook Quiz', 'total_points' => 10]);
        $quizItem = GradeFactory::item($scheme, $quizzes, 'CANARYQUIZITEM', 10, 1, $quiz);
        $quizTwo  = GradeFactory::item($scheme, $quizzes, 'CANARYQUIZITEM two', 10, 2);
        $examOne  = GradeFactory::item($scheme, $exams, 'CANARYEXAMA', 100, 3);
        $examTwo  = GradeFactory::item($scheme, $exams, 'CANARYEXAMB', 100, 4);
        GradeFactory::score($this->offering, $this->child, $quizItem, 8.37);
        GradeFactory::score($this->offering, $this->child, $quizTwo, 6.91);
        GradeFactory::score($this->offering, $this->child, $examOne, 61.23);
        GradeFactory::score($this->offering, $this->child, $examTwo, 77.41);
        $db->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        (new GradingEngineService())->recalculateStudent($this->offering, (int) $this->child->id);
        $db->table('gradebook_records')->where(['course_offering_id' => $this->offering, 'student_id' => $this->child->id])
            ->set('final_percentage', 'calculated_percentage', false)
            ->update(['remarks' => 'Passed', 'finalized_at' => $now]);

        $this->slug = url_title((string) $db->table('course_offerings o')->select('c.course_title')->join('courses c', 'c.id = o.course_id')->where('o.id', $this->offering)->get()->getRowArray()['course_title'], '-', true);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    /** @return list<string> every address a parent can reach for this child and course */
    private function addresses(): array
    {
        $c = $this->child->id;
        $o = $this->offering;
        $s = $this->slug;

        $list = [
            '/parent', '/parent/dashboard', "/parent/children/{$c}", '/parent/profile', '/parent/calendar', "/parent/children/{$c}/calendar",
            "/parent/children/{$c}/api/calendar/events?from=2020-01-01&to=2030-12-31", '/notifications',
            "/parent/children/{$c}/courses/{$o}/{$s}",
        ];

        foreach (['overview', 'announcements', 'assessments', 'grades'] as $tab) {
            $list[] = "/parent/children/{$c}/courses/{$o}/{$s}/{$tab}";
            $list[] = "/parent/children/{$c}/api/courses/{$o}/{$tab}";
        }

        $list[] = "/parent/children/{$c}/api/courses/{$o}/grades/report/mine?format=json";
        $list[] = "/parent/children/{$c}/courses/{$this->dropped}/{$s}/grades";

        return $list;
    }

    /** @return array{int, string} */
    private function fetch(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    public function testThePlantedThingsReallyReachTheStudentsOwnGradebook(): void
    {
        // Without this the sweep below could pass because the fixture never worked.
        $this->loggedInAs($this->child);

        [$status, $body] = $this->fetch("/student/api/courses/{$this->offering}/grades");

        $this->assertSame(200, $status);
        $this->assertStringContainsString('CANARYEXAMA', $body);
        $this->assertStringContainsString('61.23', $body);
    }

    public function testNoPrivateWordOrScoreAppearsOnAnyPageOrApiAParentCanReach(): void
    {
        $this->loggedInAs($this->parent);

        $reached = 0;

        foreach ($this->addresses() as $uri) {
            [$status, $body] = $this->fetch($uri);

            if ($status === 200) {
                $reached++;
            }

            if (str_ends_with($uri, "/api/courses/{$this->offering}/grades")) {
                $this->assertStringContainsString('Quizzes', $body, 'the grades the parent may see are really there');
            }

            foreach (self::WORDS as $word) {
                $this->assertStringNotContainsString($word, $body, "{$word} on {$uri}");
            }

            foreach (self::SCORES as $score) {
                $this->assertStringNotContainsString($score, $body, "{$score} on {$uri}");
            }
        }

        $this->assertGreaterThan(8, $reached, 'the sweep must really reach the pages');
    }

    public function testTheCourseOffersOnlyTheFourTabs(): void
    {
        $this->loggedInAs($this->parent);

        [, $body] = $this->fetch("/parent/children/{$this->child->id}/courses/{$this->offering}/{$this->slug}/overview");

        foreach (['modules', 'discussions', 'resources', 'people', 'attendance'] as $tab) {
            $this->assertStringNotContainsString('data-course-feature="' . $tab . '"', $body, $tab);
        }

        foreach (['overview', 'announcements', 'assessments', 'grades'] as $tab) {
            $this->assertStringContainsString('data-course-feature="' . $tab . '"', $body, $tab);
        }
    }

    public function testTheGradeReportPdfCarriesNoItemNamesOrScores(): void
    {
        $this->loggedInAs($this->parent);

        $response = $this->call('GET', "/parent/children/{$this->child->id}/api/courses/{$this->offering}/grades/report/mine?format=pdf")->response();

        $this->assertSame(200, $response->getStatusCode());

        $path = tempnam(sys_get_temp_dir(), 'can') . '.pdf';
        file_put_contents($path, (string) $response->getBody());

        try {
            $text = (new PdfTextExtractor())->extract($path)['text'];
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('Quizzes', $text);

        foreach ([...self::WORDS, ...self::SCORES] as $private) {
            $this->assertStringNotContainsString($private, $text, $private);
        }
    }
}

<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The parent's dashboard: which child it shows, what each part says, who gets
 * a 404, and that no score of a single item ever reaches the page.
 *
 * @internal
 */
final class ParentDashboardPageTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings service remembers values in memory; a switch a test sets must not outlive it.
        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PDP');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->teacher     = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function child(string $first, ?User $parent = null): User
    {
        $student = PeopleFactory::person('student', $first, 'Lee', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => ($parent ?? $this->parent)->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $student;
    }

    /** A course with a published scheme, a gradebook quiz scored $score, and a past-due assessment nobody handed in. */
    private function course(User $student, string $section = 'A', float $score = 10.0, bool $withMissing = false): int
    {
        $offering = CourseFactory::offering($section);
        $module   = CourseFactory::module($offering);
        CourseFactory::assignInstructor($offering, $this->teacher);
        CourseFactory::enroll($offering, $student);

        $scheme   = GradeFactory::scheme($offering, 75.0);
        $category = GradeFactory::category($scheme, 'Quizzes', 100);
        $quiz     = $this->assessment($offering, $module, 'Gradebook quiz', time() + 30 * 86400);
        $item     = GradeFactory::item($scheme, $category, 'Quiz', 10, 1, $quiz);
        GradeFactory::score($offering, $student, $item, $score);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        // A graded submission with a score that must never be printed.
        db_connect()->table('assessment_submissions')->insert([
            'assessment_id' => $quiz, 'student_id' => $student->id, 'attempt_number' => 1, 'score' => 7.37,
            'is_graded' => 1, 'status' => 'Graded', 'graded_by' => $this->teacher->id, 'graded_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assessment($offering, $module, 'Quiz Three', time() + 2 * 86400);

        if ($withMissing) {
            $this->assessment($offering, $module, 'Forgotten Essay', time() - 3 * 86400);
        }

        return $offering;
    }

    private function assessment(int $offering, int $module, string $title, int $due): int
    {
        $id = CourseFactory::assessment($offering, $this->teacher, $module);

        db_connect()->table('assessments')->where('id', $id)->update([
            'status' => 'Published', 'total_points' => 10, 'title' => $title, 'due_date' => date('Y-m-d H:i:s', $due),
            'available_from' => date('Y-m-d H:i:s', time() - 60 * 86400), 'available_until' => null,
        ]);

        return $id;
    }

    /** @return array{int, string} the status code and the body, a missing page counting as its 404 */
    private function open(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    public function testTheFirstChildByNameIsShownByDefault(): void
    {
        $this->child('Ben');
        $this->course($this->child('Ann'));
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open('/parent/dashboard');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Ann Lee', $body);
    }

    public function testTheChosenChildIsShownAndBothAreInTheSwitcher(): void
    {
        $this->child('Ann');
        $ben = $this->child('Ben');
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open('/parent/children/' . $ben->id);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-child-switcher', $body);
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertMatchesRegularExpression('/<option[^>]*value="[^"]*parent\/children\/' . $ben->id . '"[^>]*selected/s', $body);
    }

    public function testOneChildHasNoSwitcher(): void
    {
        $this->child('Ann');
        $this->loggedInAs($this->parent);

        [, $body] = $this->open('/parent/dashboard');

        $this->assertStringNotContainsString('data-child-switcher', $body);
    }

    public function testEachPartOfTheDashboardIsShown(): void
    {
        $ann = $this->child('Ann');
        $this->course($ann, 'A', 5.0, true);
        $this->loggedInAs($this->parent);

        [, $body] = $this->open('/parent/dashboard');

        $this->assertStringContainsString('Needs attention', $body);
        $this->assertStringContainsString('At risk in', $body);
        $this->assertStringContainsString('Not handed in: Forgotten Essay', $body);
        $this->assertStringContainsString('50%', $body, 'the grade so far');
        $this->assertStringContainsString('So far', $body);
        $this->assertStringContainsString('Tina Tan', $body);
        $this->assertStringContainsString('Quiz Three', $body, 'a deadline in the next 14 days');
        $this->assertStringContainsString('View calendar', $body);
    }

    public function testNoScoreOfASingleItemEverReachesThePage(): void
    {
        $this->course($this->child('Ann'));
        $this->loggedInAs($this->parent);

        [, $body] = $this->open('/parent/dashboard');

        $this->assertStringNotContainsString('7.37', $body);
        $this->assertStringNotContainsString('Gradebook quiz', $body);
    }

    public function testEmptyStatesSaySoPlainly(): void
    {
        $this->child('Ann');
        $this->loggedInAs($this->parent);

        [, $body] = $this->open('/parent/dashboard');

        $this->assertStringContainsString('Nothing needs attention right now', $body);
        $this->assertStringContainsString('Ann is not enrolled in any course this term', $body);
        $this->assertStringContainsString('No deadlines in the next 14 days', $body);
    }

    public function testPastTermsAreListedWhenThereAreSome(): void
    {
        $ann = $this->child('Ann');
        $old = CourseFactory::offering('Z');
        db_connect()->table('course_offerings')->where('id', $old)->update(['status' => 'completed']);
        CourseFactory::enroll($old, $ann, 'completed');
        $this->loggedInAs($this->parent);

        [, $body] = $this->open('/parent/dashboard');

        $this->assertStringContainsString('Past terms (1)', $body);
        $this->assertStringContainsString('Completed', $body);
    }

    public function testAChildThatIsNotTheirsAnswers404WithNoData(): void
    {
        $this->child('Ann');
        $other    = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $theirs   = $this->child('Tom', $other);
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $this->loggedInAs($this->parent);

        foreach ([(string) $theirs->id, (string) $stranger->id, '0', '99999999'] as $id) {
            [$status, $body] = $this->open('/parent/children/' . $id);

            $this->assertSame(404, $status, $id);
            $this->assertStringNotContainsString('Tom Lee', $body, $id);
        }

        [$status] = $this->open('/parent/children/abc');
        $this->assertSame(404, $status, 'a non-numeric id has no route at all');
    }

    public function testASwitchedOffPortalShowsNoChildOnTheChildRoute(): void
    {
        $ann = $this->child('Ann');
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->parent);

        [$status, $body] = $this->open('/parent/children/' . $ann->id);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString('Ann Lee', $body);
    }

    public function testEveryOtherRoleIsTurnedAwayFromAChildPage(): void
    {
        $ann = $this->child('Ann');

        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            [$status, $body] = $this->open('/parent/children/' . $ann->id);

            $this->assertSame(302, $status, $role);
            $this->assertStringNotContainsString('Ann Lee', $body, $role);
        }
    }

    public function testAParentCannotChangeAnythingOnAChildPage(): void
    {
        $ann = $this->child('Ann');
        $this->loggedInAs($this->parent);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            try {
                $status = $this->call($verb, '/parent/children/' . $ann->id)->response()->getStatusCode();
            } catch (PageNotFoundException) {
                $status = 404;
            }

            $this->assertContains($status, [403, 404, 405], strtoupper($verb));
        }
    }
}

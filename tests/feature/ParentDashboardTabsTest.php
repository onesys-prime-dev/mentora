<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Below lg the parent's dashboard is three tabs (Attention, Courses, Deadlines); each part sits in its panel.
 *
 * @internal
 */
final class ParentDashboardTabsTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PDT');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function child(): User
    {
        $student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);

        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $this->parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $student;
    }

    private function dashboard(): DOMXPath
    {
        $this->loggedInAs($this->parent);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . (string) $this->call('GET', '/parent/dashboard')->response()->getBody());

        return new DOMXPath($doc);
    }

    public function testTheTabsAndTheirPanels(): void
    {
        $this->child();
        $x = $this->dashboard();

        $this->assertSame(1, $x->query('//*[@data-phone-tabs]')->length);
        $labels = array_map(static fn ($el) => trim($el->textContent), iterator_to_array($x->query('//*[@data-phone-tab]')));
        $this->assertSame(['Attention', 'Courses', 'Deadlines'], $labels);

        $this->assertSame(1, $x->query('//*[@data-phone-panel="attention"][@data-phone-active]//*[@data-parent-attention]')->length);
        $this->assertSame(1, $x->query('//*[@data-phone-panel="courses"]//*[@aria-labelledby="parent-courses"]')->length);
        $this->assertSame(1, $x->query('//*[@data-phone-panel="deadlines"]//*[@data-parent-deadlines]')->length);
        $this->assertSame(0, $x->query('//*[@data-phone-panel="courses"][@data-phone-active]')->length, 'only the first tab shows without script');
    }

    public function testNoDotWhenNothingNeedsAttention(): void
    {
        $this->child();

        $this->assertSame(0, $this->dashboard()->query('//*[@data-phone-tab-dot]')->length);
    }

    public function testDotWhenSomethingNeedsAttention(): void
    {
        $student  = $this->child();
        $teacher  = PeopleFactory::person('instructor', 'Tina', 'Tan', null, $this->institution);
        $offering = CourseFactory::offering('A');
        $module   = CourseFactory::module($offering);
        CourseFactory::assignInstructor($offering, $teacher);
        CourseFactory::enroll($offering, $student);

        $id = CourseFactory::assessment($offering, $teacher, $module);
        db_connect()->table('assessments')->where('id', $id)->update([
            'status' => 'Published', 'total_points' => 10, 'title' => 'Forgotten Essay', 'due_date' => date('Y-m-d H:i:s', time() - 3 * 86400),
            'available_from' => date('Y-m-d H:i:s', time() - 60 * 86400), 'available_until' => null,
        ]);

        // The page's own rule for missing work needs a published scheme.
        $scheme   = GradeFactory::scheme($offering, 75.0);
        $category = GradeFactory::category($scheme, 'Quizzes', 100);
        $item     = GradeFactory::item($scheme, $category, 'Quiz', 10, 1, $id);
        GradeFactory::score($offering, $student, $item, 5.0);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);

        $this->assertSame(1, $this->dashboard()->query('//*[@data-phone-tab="attention"]//*[@data-phone-tab-dot]')->length);
    }

    public function testAParentWithNoChildrenStillSeesNotLinked(): void
    {
        $x = $this->dashboard();

        $this->assertStringContainsString('You are not linked to a student yet', $x->document->textContent);
        $this->assertSame(0, $x->query('//*[@data-phone-tabs]')->length);
    }
}

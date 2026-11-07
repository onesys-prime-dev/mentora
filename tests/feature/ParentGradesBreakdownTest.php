<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeAdjustmentService;
use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The breakdown a parent's course row loads for their child: the child's own numbers, only for the parent's own
 * child and a course the child is in, nothing while the portal is off, nothing created for a missing scheme.
 *
 * @internal
 */
final class ParentGradesBreakdownTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $child;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $this->institution = PeopleFactory::institution('PGB');
        $this->parent      = PeopleFactory::person('parent', 'Pam', 'Parent', null, $this->institution);
        $this->child       = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->link($this->parent, $this->child);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function link(User $parent, User $child): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $child->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0: int, 1: int} offering, scheme */
    private function course(User $student, string $status = 'Published'): array
    {
        $offering = CourseFactory::offering();
        CourseFactory::enroll($offering, $student);

        $scheme = GradeFactory::scheme($offering, 75.0, true);
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $status]);

        return [$offering, $scheme];
    }

    private function graded(int $offering, int $scheme, User $student, float $score): int
    {
        $period   = GradeFactory::period($scheme, 'Prelim', 100, 1);
        $category = GradeFactory::category($scheme, 'Quizzes', 100, $period);
        $item     = GradeFactory::item($scheme, $category, 'Quiz 1', 100);
        GradeFactory::score($offering, $student, $item, $score);

        return $item;
    }

    /** The instructor curves the item, so the course reads as adjusted. */
    private function curve(int $offering, int $item): void
    {
        $instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $instructor);

        $result = (new GradeAdjustmentService())->apply($instructor, $offering, 'item', $item, 'add_points', ['points' => 5], '');
        $this->assertTrue($result['success']);
    }

    private function path(int $offering, ?int $child = null): string
    {
        return '/parent/children/' . ($child ?? $this->child->id) . '/api/grades/courses/' . $offering;
    }

    /** @return array{int, string} */
    private function open(string $uri): array
    {
        try {
            $response = $this->call('GET', $uri)->response();

            return [$response->getStatusCode(), (string) $response->getBody()];
        } catch (PageNotFoundException) {
            return [404, ''];
        }
    }

    public function testAParentSeesTheirChildsBreakdown(): void
    {
        [$offering, $scheme] = $this->course($this->child);
        $item = $this->graded($offering, $scheme, $this->child, 88);
        $this->curve($offering, $item);

        $this->loggedInAs($this->parent);
        [$status, $body] = $this->open($this->path($offering));
        $parentHtml      = (string) json_decode($body, true)['html'];

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Prelim 100%', $parentHtml);
        $this->assertStringContainsString('Adjusted by the instructor', $parentHtml);
        $this->assertStringNotContainsString('your instructor', $parentHtml);

        $this->loggedInAs($this->child);
        $own = json_decode($this->get("student/api/grades/courses/{$offering}")->getJSON(), true)['html'];

        // Word for word, except the one line that speaks to its reader: the student's "your instructor" is the parent's "the instructor".
        $this->assertStringContainsString('Adjusted by your instructor', $own);

        $strip = static fn (string $h): string => trim((string) preg_replace(['/<!-- DEBUG-VIEW[^>]*-->/', '/href="[^"]*"/', '/Adjusted by (?:your|the) instructor/', '/\s+/'], ['', '', 'Adjusted by READER instructor', ' '], $h));
        $this->assertSame($strip($own), $strip($parentHtml));
    }

    public function testAnotherChildIs404(): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        [$offering] = $this->course($stranger);

        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->path($offering, (int) $stranger->id))[0]);
    }

    public function testACourseTheChildIsNotInIs404(): void
    {
        $stranger   = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        [$offering] = $this->course($stranger);

        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->path($offering))[0]);
        $this->assertSame(404, $this->open($this->path(99999999))[0]);
    }

    public function testPortalOffIs403Json(): void
    {
        [$offering, $scheme] = $this->course($this->child);
        $this->graded($offering, $scheme, $this->child, 88);
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $this->loggedInAs($this->parent);
        [$status, $body] = $this->open($this->path($offering));

        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('88.0%', $body);
    }

    public function testAnUnpublishedSchemeIs404AndCreatesNothing(): void
    {
        [$draft] = $this->course($this->child, 'Draft');

        $none = CourseFactory::offering('B');
        CourseFactory::enroll($none, $this->child);

        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->path($draft))[0]);
        $this->assertSame(404, $this->open($this->path($none))[0]);

        $this->assertSame(0, db_connect()->table('grading_schemes')->where('course_offering_id', $none)->countAllResults());
        $this->assertSame(0, db_connect()->table('gradebook_records')->whereIn('course_offering_id', [$draft, $none])->countAllResults());
    }

    public function testAChildWhoIsAlsoStaffIs404(): void
    {
        [$offering, $scheme] = $this->course($this->child);
        $this->graded($offering, $scheme, $this->child, 88);
        $this->child->addGroup('instructor');

        $this->loggedInAs($this->parent);

        $this->assertSame(404, $this->open($this->path($offering))[0]);
    }

    public function testTheCourseLinkIsTheParentsView(): void
    {
        [$offering, $scheme] = $this->course($this->child);
        $this->graded($offering, $scheme, $this->child, 88);

        $this->loggedInAs($this->parent);
        $html = (string) json_decode($this->open($this->path($offering))[1], true)['html'];

        $title = (string) db_connect()->query('SELECT c.course_title FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE o.id = ?', [$offering])->getRow()->course_title;
        $href  = site_url("parent/children/{$this->child->id}/courses/{$offering}/" . url_title($title, '-', true) . '/grades');

        $this->assertStringContainsString('href="' . esc($href, 'attr') . '"', $html);
        $this->assertStringNotContainsString('student/courses', $html);
    }
}

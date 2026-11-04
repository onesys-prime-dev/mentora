<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The parent's Grades page: the student's page for a linked child, behind the parent boundary. The menu entry
 * opens the first child; another child is a 404; the switcher opens another child's grades; links go to the
 * parent's views; finalized rows offer the report; each parent has their own "Changed" memory; a child who is
 * also staff shows no grades; viewing as a parent writes nothing; and the student's own page is unchanged.
 *
 * @internal
 */
final class ParentGradesPageTest extends RouteTestCase
{
    private int $institution;

    private User $parent;

    private User $child;

    private ?int $term = null;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = PeopleFactory::institution('PGP');
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

    /** A course the student is in, in the one active term the test's courses share. */
    private function course(User $student, string $section = 'A', string $enrolment = 'enrolled'): int
    {
        $offering = CourseFactory::offering($section);
        $db       = db_connect();

        CourseFactory::enroll($offering, $student, $enrolment);

        if ($this->term === null) {
            $this->term = (int) $db->table('course_offerings')->where('id', $offering)->get()->getRow()->term_id;
            $db->table('academic_terms')->where('id', $this->term)->update(['status' => 'active']);
        } else {
            $db->table('course_offerings')->where('id', $offering)->update(['term_id' => $this->term]);
        }

        return $offering;
    }

    private function scheme(int $offering, string $status = 'Published'): int
    {
        $scheme = GradeFactory::scheme($offering, 75.0, false, GradeFactory::scale());
        db_connect()->table('grading_schemes')->where('id', $scheme)->update(['status' => $status]);

        return $scheme;
    }

    /** @param array<string, mixed> $fields */
    private function record(int $offering, int $scheme, User $student, array $fields): void
    {
        db_connect()->table('gradebook_records')->insert($fields + [
            'course_offering_id' => $offering,
            'student_id'         => $student->id,
            'grading_scheme_id'  => $scheme,
            'is_override'        => 0,
            'remarks'            => 'Passed',
            'last_calculated_at' => date('Y-m-d H:i:s', time() - 300),
        ]);
    }

    private function slugOf(int $offering): string
    {
        $title = db_connect()->table('course_offerings o')->select('c.course_title')->join('courses c', 'c.id = o.course_id')->where('o.id', $offering)->get()->getRow()->course_title;

        return url_title((string) $title, '-', true);
    }

    private function open(?User $as = null, ?int $child = null, string $query = ''): TestResponse
    {
        $this->loggedInAs($as ?? $this->parent);

        $result = $this->get('parent/children/' . ($child ?? $this->child->id) . '/grades' . ($query !== '' ? '?' . $query : ''));
        $result->assertStatus(200);

        return $result;
    }

    private function xpath(TestResponse|string $result): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . (is_string($result) ? $result : $result->getBody()));
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private function badge(int $offering): string
    {
        return "//*[@data-grades-row][@data-offering-id='{$offering}']//*[@data-changed]";
    }

    public function testParentGradesOpensTheFirstChild(): void
    {
        $this->loggedInAs($this->parent);

        $result = $this->get('parent/grades');

        $result->assertRedirectTo(site_url('parent/children/' . $this->child->id . '/grades'));
    }

    public function testPortalOffAndNotLinkedUseTheExistingPages(): void
    {
        $alone = PeopleFactory::person('parent', 'Lou', 'Alone', null, $this->institution);
        $this->loggedInAs($alone);
        $result = $this->get('parent/grades');
        $result->assertStatus(200);
        $result->assertSee('You are not linked to a student yet');

        $offering = $this->course($this->child);
        $this->record($offering, $this->scheme($offering), $this->child, ['calculated_percentage' => 88]);
        (new ParentPortal())->setInstitutionOn($this->institution, false);

        $this->loggedInAs($this->parent);

        foreach (['parent/grades', 'parent/children/' . $this->child->id . '/grades'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(200);
            $result->assertSee('The parent portal is switched off');
            $result->assertDontSee('data-grades-row');
        }
    }

    public function testThePageShowsTheChildsTermCoursesAndSummary(): void
    {
        $graded = $this->course($this->child, 'A');
        $this->record($graded, $this->scheme($graded), $this->child, ['calculated_percentage' => 88]);
        $draft = $this->course($this->child, 'B');
        $this->scheme($draft, 'Draft');

        $parentPage = $this->open();
        $x          = $this->xpath($parentPage);

        $this->assertSame('Grades', $this->text($x->query('//h1')->item(0)));
        $this->assertSame(1, $x->query("//p[normalize-space()=\"Your child's grades in every course.\"]")->length);
        $this->assertSame(2, $x->query('//*[@data-grades-row]')->length);
        $this->assertSame(1, $x->query('//*[@data-grades-summary]')->length);
        $this->assertSame(1, $x->query('//*[@data-term-line]')->length);

        $this->loggedInAs($this->child);
        $own = $this->xpath($this->get('student/grades'));

        // The same rows and summary, word for word, apart from the parent's report link.
        $strip = fn (DOMXPath $p, string $q): string => str_replace('Download report', '', $this->text($p->query($q)->item(0)));

        foreach (['//*[@data-grades-rows]', '//*[@data-grades-summary]', '//*[@data-term-line]'] as $query) {
            $this->assertSame($strip($own, $query), $strip($x, $query), $query);
        }
    }

    public function testTheSwitcherListsChildrenAndOpensTheirGrades(): void
    {
        $this->course($this->child);

        // One child: no switcher.
        $this->assertSame(0, $this->xpath($this->open())->query('//*[@data-child-switcher]')->length);

        $second = PeopleFactory::person('student', 'Bea', 'Lee', null, $this->institution);
        $this->link($this->parent, $second);

        $x       = $this->xpath($this->open(null, (int) $second->id));
        $options = $x->query('//select[@data-child-switcher]/option');

        $this->assertSame(2, $options->length);
        $values = [];

        foreach ($options as $option) {
            $values[] = $option->getAttribute('value');

            if ($option->getAttribute('value') === site_url('parent/children/' . $second->id . '/grades')) {
                $this->assertTrue($option->hasAttribute('selected'), 'the child shown is selected');
            }
        }

        sort($values);
        $expected = [site_url('parent/children/' . $this->child->id . '/grades'), site_url('parent/children/' . $second->id . '/grades')];
        sort($expected);
        $this->assertSame($expected, $values);
        $this->assertSame('Showing', $this->text($x->query("//label[@for='child-switcher']")->item(0)));

        // The dashboard's switcher still opens the dashboard.
        $this->loggedInAs($this->parent);
        $dashboard = $this->xpath($this->get('parent/children/' . $this->child->id));
        $values    = [];

        foreach ($dashboard->query('//select[@data-child-switcher]/option') as $option) {
            $values[] = $option->getAttribute('value');
        }

        sort($values);
        $expected = [site_url('parent/children/' . $this->child->id), site_url('parent/children/' . $second->id)];
        sort($expected);
        $this->assertSame($expected, $values);
    }

    public function testAnotherChildIs404(): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $this->course($stranger);

        $this->loggedInAs($this->parent);

        $this->expectException(PageNotFoundException::class);
        $this->get('parent/children/' . $stranger->id . '/grades');
    }

    public function testFinalizedRowsOfferTheReport(): void
    {
        $final = $this->course($this->child, 'A');
        $this->record($final, $this->scheme($final), $this->child, [
            'calculated_percentage' => 90, 'final_percentage' => 90, 'finalized_at' => '2026-12-01 10:00:00',
        ]);
        $running = $this->course($this->child, 'B');
        $this->record($running, $this->scheme($running), $this->child, ['calculated_percentage' => 80]);

        $x = $this->xpath($this->open());

        $link = $x->query("//*[@data-grades-row][@data-offering-id='{$final}']//a[@data-report-link]")->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame('Download report', $this->text($link));
        $this->assertSame(site_url("parent/children/{$this->child->id}/api/courses/{$final}/grades/report/mine"), $link->getAttribute('href'));

        $this->assertSame(0, $x->query("//*[@data-grades-row][@data-offering-id='{$running}']//a[@data-report-link]")->length, 'not final: no report');
    }

    public function testLinksPointAtTheParentsViews(): void
    {
        $offering = $this->course($this->child);
        $this->record($offering, $this->scheme($offering), $this->child, ['calculated_percentage' => 88]);

        $result = $this->open();
        $x      = $this->xpath($result);

        $this->assertSame(site_url("parent/children/{$this->child->id}/api/grades/courses"), $x->query('//*[@data-grades-rows]')->item(0)->getAttribute('data-breakdown-base'));

        $link = $x->query("//*[@data-grades-row][@data-offering-id='{$offering}']//h3//a")->item(0);
        $this->assertSame(site_url("parent/children/{$this->child->id}/courses/{$offering}/" . $this->slugOf($offering)), $link->getAttribute('href'));

        $this->assertStringNotContainsString('student/courses', (string) $result->getBody());
        $this->assertStringNotContainsString('student/api', (string) $result->getBody());

        // The term picker stays on this child's page.
        $option = $x->query('//select[@data-term-select]/option[@value!=""]')->item(0);
        $this->assertSame(site_url("parent/children/{$this->child->id}/grades") . '?term=' . $this->term, $option->getAttribute('value'));

        // The Grades tile is lit, and the way back is the child's dashboard.
        $this->assertSame('page', $x->query("//*[@data-app-tile='grades']")->item(0)?->getAttribute('aria-current'));
        $this->assertSame(site_url('parent/children/' . $this->child->id), $x->query('//a[contains(., "Back to dashboard")]')->item(0)?->getAttribute('href'));
        $this->assertSame(0, $x->query('//*[@data-page-panel]//a[normalize-space()="Back"]')->length, 'one way back, above the panel');
    }

    public function testEachParentHasTheirOwnChangedMemory(): void
    {
        $other = PeopleFactory::person('parent', 'Pat', 'Parent', null, $this->institution);
        $this->link($other, $this->child);

        $offering = $this->course($this->child);
        $this->record($offering, $this->scheme($offering), $this->child, ['calculated_percentage' => 80]);

        $this->open($this->parent);
        $this->open($other);

        db_connect()->table('gradebook_records')->where('course_offering_id', $offering)->update(['calculated_percentage' => 85]);

        $this->assertSame(1, $this->xpath($this->open($this->parent))->query($this->badge($offering))->length);
        $this->assertSame(0, $this->xpath($this->open($this->parent))->query($this->badge($offering))->length, 'shown once to this parent');
        $this->assertSame(1, $this->xpath($this->open($other))->query($this->badge($offering))->length, 'the other parent still sees it');

        $this->assertSame(1, db_connect()->table('grades_seen')->where(['viewer_user_id' => $this->parent->id, 'student_user_id' => $this->child->id])->countAllResults());
        $this->assertSame(0, db_connect()->table('grades_seen')->where('viewer_user_id', $this->child->id)->countAllResults(), "the child's own memory is untouched");
    }

    public function testAChildWhoIsAlsoStaffShowsNoGrades(): void
    {
        $offering = $this->course($this->child);
        $this->record($offering, $this->scheme($offering), $this->child, ['calculated_percentage' => 88]);
        $this->child->addGroup('instructor');

        $result = $this->open();
        $x      = $this->xpath($result);

        $this->assertSame(0, $x->query('//*[@data-grades-row]')->length);
        $this->assertSame(0, $x->query('//*[@data-load-error]')->length);
        $result->assertDontSee('88.00%');
        $result->assertSee('No courses to show');
        $result->assertSee('When your child is enrolled in a course, its grade shows here.');
        $result->assertDontSee('No courses yet');
        $result->assertDontSee('When you are enrolled');
        $this->assertSame(0, db_connect()->table('grades_seen')->countAllResults());
    }

    public function testViewingAsAParentWritesNothing(): void
    {
        $offering = $this->course($this->child);
        $this->record($offering, $this->scheme($offering), $this->child, ['calculated_percentage' => 80]);

        $this->loggedInAs($this->parent);
        session()->set('impersonator_id', 1);
        session()->set('impersonation', ['target_id' => (int) $this->parent->id, 'reason' => 'Checking the grades', 'started_at' => time(), 'expires_at' => time() + 600]);
        $this->withSession();

        $this->get('parent/children/' . $this->child->id . '/grades')->assertStatus(200);
        $this->assertSame(0, db_connect()->table('grades_seen')->countAllResults());

        session()->remove(['impersonator_id', 'impersonation']);
        $this->withSession();

        $this->get('parent/children/' . $this->child->id . '/grades')->assertStatus(200);
        $this->assertSame(1, db_connect()->table('grades_seen')->countAllResults(), "the parent's own look is remembered");
    }

    public function testTheStudentPageIsUnchanged(): void
    {
        $offering = $this->course($this->child);
        $this->record($offering, $this->scheme($offering), $this->child, [
            'calculated_percentage' => 90, 'final_percentage' => 90, 'finalized_at' => '2026-12-01 10:00:00',
        ]);

        $this->loggedInAs($this->child);
        $result = $this->get('student/grades');
        $x      = $this->xpath($result);

        $this->assertSame(site_url('student/api/grades/courses'), $x->query('//*[@data-grades-rows]')->item(0)->getAttribute('data-breakdown-base'));
        $this->assertSame(site_url("student/courses/{$offering}/" . $this->slugOf($offering)), $x->query('//*[@data-grades-row]//h3//a')->item(0)->getAttribute('href'));
        $this->assertSame(0, $x->query('//a[@data-report-link]')->length, 'the student page has no report link');
        $this->assertSame(0, $x->query('//*[@data-child-switcher]')->length);
        $this->assertSame(site_url('student/dashboard'), $x->query('//a[contains(., "Back to dashboard")]')->item(0)?->getAttribute('href'));
        $this->assertSame(0, $x->query('//*[@data-page-panel]//a[normalize-space()="Back"]')->length);
        $result->assertSee('Your grades in every course, as they stand now.');
        $this->assertStringNotContainsString('parent/children', (string) $result->getBody());
    }
}

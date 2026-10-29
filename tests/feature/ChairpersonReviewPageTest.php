<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The dean's Chairperson review page: what chairpersons changed in the department's courses, its tabs and
 * filters, the "reviewed" marks, that the course opens read-only from a row, and that nothing of another
 * department, role or read-only institution gets through.
 *
 * @internal
 */
final class ChairpersonReviewPageTest extends RouteTestCase
{
    private int $institution;
    private int $department;
    private int $offering;
    private User $dean;
    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('R');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $sub               = DeanFactory::subDepartment($this->department, 'A');
        $this->offering    = DeanFactory::offering(DeanFactory::program($sub, 'A'), DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');
        $this->dean        = DeanFactory::dean($this->institution, $this->department);
        $this->chair       = DeanFactory::chair($sub, 'Ana');
    }

    private function action(int $offering, string $at = '2026-10-10 09:00:00'): int
    {
        Services::resetSingle('auditLogger');

        $id = service('auditLogger')->log('course.chairperson_action', $this->chair, [
            'target_type' => 'course_offering', 'target_id' => $offering, 'acting_role' => 'chairperson',
            'new'         => ['method' => 'POST', 'path' => "/chairperson/api/courses/{$offering}/announcements"],
        ]);

        db_connect()->table('audit_logs')->where('id', $id)->update(['created_at' => $at]);

        return $id;
    }

    private function page(string $query = '', ?User $as = null): string
    {
        $this->loggedInAs($as ?? $this->dean);
        $response = $this->call('get', 'dean/chairperson-review' . $query)->response();

        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody());
    }

    /** @param array<string, mixed> $data */
    private function send(array $data, ?User $as = null): \CodeIgniter\Test\TestResponse
    {
        $this->loggedInAs($as ?? $this->dean);

        return $this->withHeaders(['Accept' => 'application/json'])->call('post', 'dean/chairperson-review/reviewed', $data);
    }

    public function testTheDeanSeesWhatTheChairpersonChangedAndCanOpenTheCourse(): void
    {
        $this->action($this->offering);

        $body = $this->page();

        $this->assertStringContainsString('Created an announcement', $body);
        $this->assertStringContainsString('IT101 A', $body);
        $this->assertStringContainsString('Sub A', $body);
        $this->assertStringContainsString('ana.chair', $body);
        $this->assertStringContainsString('dean/courses/' . $this->offering . '/intro-to-computing', $body);
        $this->assertStringContainsString('data-mark-reviewed', $body);
        $this->assertStringContainsString('To review', $body);
    }

    public function testItSaysSoWhenNothingNeedsReviewAndWhenFiltersFindNothing(): void
    {
        $this->assertStringContainsString('Nothing to review', $this->page());

        $this->action($this->offering);

        $this->assertStringContainsString('No changes match these filters', $this->page('?from=2030-01-01'));
        $this->assertStringContainsString('Nothing reviewed yet', $this->page('?view=reviewed'));
    }

    public function testMarkingReviewedMovesAnEntryToTheReviewedTab(): void
    {
        $id = $this->action($this->offering);

        $response = $this->send(['id' => $id])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Marked as reviewed', $response->getBody());
        $this->assertStringContainsString('Nothing to review', $this->page());
        $this->assertStringContainsString('Created an announcement', $this->page('?view=reviewed'));
        $this->assertStringNotContainsString('data-mark-reviewed', $this->page('?view=reviewed'));
    }

    public function testTheWholePageCanBeMarkedAtOnce(): void
    {
        $a = $this->action($this->offering, '2026-10-10 09:00:00');
        $b = $this->action($this->offering, '2026-10-11 09:00:00');

        $this->assertStringContainsString('Mark these 2 reviewed', $this->page());

        $response = $this->send(['ids' => [$a, $b]])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('2 marked as reviewed', $response->getBody());
    }

    public function testAnotherDepartmentsEntryCannotBeMarkedAndIsNeverListed(): void
    {
        $elsewhere = DeanFactory::department('X', $this->institution);
        $foreign   = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($elsewhere['department'], 'X'), 'X'), DeanFactory::term($this->institution, 'Other'), 'LAW900', 'A', 'active', 'Foreign Law');
        $id        = $this->action($foreign);

        $this->assertStringNotContainsString('LAW900', $this->page());
        $this->assertSame(422, $this->send(['id' => $id])->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    public function testMarkingWithoutChoosingAnythingSaysSo(): void
    {
        $response = $this->send([])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Choose what you have reviewed', $response->getBody());
    }

    public function testNobodyButADeanReachesIt(): void
    {
        $id = $this->action($this->offering);

        foreach ([$this->chair, PeopleFactory::person('student', 'Sam', 'Student', null, $this->institution)] as $user) {
            $this->loggedInAs($user);
            $this->assertNotSame(200, $this->call('get', 'dean/chairperson-review')->response()->getStatusCode());
            $this->assertNotSame(200, $this->send(['id' => $id], $user)->response()->getStatusCode());
        }

        $this->assertSame(0, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    public function testADeanWithoutADepartmentSeesTheExplanation(): void
    {
        $this->assertStringContainsString('not assigned to a department yet', $this->page('', PeopleFactory::person('dean', 'No', 'Department', null, $this->institution)));
    }

    public function testAReadOnlyInstitutionLetsTheDeanLookButNotMark(): void
    {
        $id = $this->action($this->offering);
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertStringContainsString('Created an announcement', $this->page());
        $this->assertSame(403, $this->send(['id' => $id])->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('chairperson_action_reviews')->countAllResults());
    }

    public function testTheAppsMenuHasTheTile(): void
    {
        $this->loggedInAs($this->dean);

        $this->assertStringContainsString('dean/chairperson-review', html_entity_decode($this->call('get', 'dean/dashboard')->response()->getBody()));
    }
}

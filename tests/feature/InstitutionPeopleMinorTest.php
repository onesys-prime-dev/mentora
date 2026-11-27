<?php

declare(strict_types=1);

use App\Services\Institutions\MinorFlag;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The minor flag on the People page: the badge, the Actions entries and the two routes behind them.
 *
 * @internal
 */
final class InstitutionPeopleMinorTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $this->institution = SisFixture::institution('PMNA');
        $this->other       = SisFixture::institution('PMNB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@pmn.example', $this->institution);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($this->admin);
        $this->withSession();
    }

    private function person(string $role, string $first, ?int $institution = null): User
    {
        return PeopleFactory::person($role, $first, 'Person', strtolower($first) . '@pmn.example', $institution ?? $this->institution);
    }

    private function page(): string
    {
        return $this->step('GET', 'institution/people')->response()->getBody();
    }

    public function testPeopleRowShowsMinorBadgeToAdmin(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->person('student', 'Ben');

        $this->assertStringNotContainsString('data-minor-badge', $this->page());

        (new MinorFlag())->set($this->admin, $this->institution, (int) $ann->id, true);
        $body = $this->page();

        $this->assertSame(1, substr_count($body, 'data-minor-badge'));
        $this->assertStringContainsString('Mark Ben Person as a minor?', html_entity_decode($body));
        $this->assertStringContainsString('Remove the minor mark from Ann Person?', html_entity_decode($body));
        $this->assertStringContainsString('Conversations that no longer qualify become read-only.', $body);
    }

    public function testNoMinorEntryForSomeoneWhoIsNotAStudent(): void
    {
        $this->person('instructor', 'Ivy');

        $this->assertStringNotContainsString('minor', strtolower(html_entity_decode($this->page())));
    }

    public function testRemoveEntryShowsForAFlaggedPersonWhoIsNoLongerAStudent(): void
    {
        $ann = $this->person('student', 'Ann');
        (new MinorFlag())->set($this->admin, $this->institution, (int) $ann->id, true);
        db_connect()->table('auth_groups_users')->where('user_id', (int) $ann->id)->where('group', 'student')->update(['group' => 'instructor']);

        $this->assertStringContainsString('Remove the minor mark from Ann Person?', html_entity_decode($this->page()));
    }

    public function testAdminMarksAndClears(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->step('POST', "institution/people/{$ann->id}/minor");
        $this->assertTrue((new MinorFlag())->isMinor($this->institution, (int) $ann->id));

        $this->step('POST', "institution/people/{$ann->id}/minor/remove");
        $this->assertFalse((new MinorFlag())->isMinor($this->institution, (int) $ann->id));
    }

    public function testRefusesSomeoneWhoIsNotAStudent(): void
    {
        $ivy = $this->person('instructor', 'Ivy');
        $this->withHeaders(['Accept' => 'application/json']);

        $this->assertSame(422, $this->step('POST', "institution/people/{$ivy->id}/minor")->response()->getStatusCode());
        $this->assertFalse((new MinorFlag())->isMinor($this->institution, (int) $ivy->id));
    }

    public function testAnotherInstitutionsStudentIsNotFound(): void
    {
        $zed = $this->person('student', 'Zed', $this->other);

        foreach (['minor', 'minor/remove'] as $tail) {
            try {
                $this->call('POST', "institution/people/{$zed->id}/{$tail}");
                $this->fail("{$tail} must be a 404");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFalse((new MinorFlag())->isMinor($this->other, (int) $zed->id));
    }

    public function testTheMinorBadgeNeverReachesAStudent(): void
    {
        $ann = $this->person('student', 'Ann');
        (new MinorFlag())->set($this->admin, $this->institution, (int) $ann->id, true);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($this->person('student', 'Cy'));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'institution/people')->response()->getStatusCode());
    }
}

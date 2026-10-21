<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The "Parents" tab of the institution's settings: the administrator can
 * switch the parent portal off and on again, but never on past Mentora.
 *
 * @internal
 */
final class InstitutionParentPortalSettingsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('PPSA');
        $this->other       = SisFixture::institution('PPSB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@pps.example', $this->institution);

        $this->signIn($this->admin);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
    }

    private function save(bool $on): \CodeIgniter\Test\TestResponse
    {
        $this->withHeaders(['Accept' => 'application/json']);

        return $this->step('POST', 'institution/settings/parents', ['enabled' => $on ? '1' : '0']);
    }

    private function mentoraSwitchesItOff(): void
    {
        db_connect()->table('feature_flag_overrides')->insert(['flag_key' => 'parent.portal', 'institution_id' => null, 'is_global' => 1, 'enabled' => 0]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@pps.example", $role === 'admin' ? null : $this->institution));

        $get  = $this->step('GET', 'institution/settings/parents')->response();
        $post = $this->save(false)->response();

        $this->assertSame(302, $get->getStatusCode(), $role);
        $this->assertSame(302, $post->getStatusCode(), $role);
        $this->assertFalse((new ParentPortal())->institutionSwitchedOff($this->institution), $role . ' changed nothing');
    }

    public function testTheTabShowsThePortalOnAndTheTabIsInTheList(): void
    {
        $body = $this->step('GET', 'institution/settings/parents')->response()->getBody();

        $this->assertStringContainsString('Parent portal', $body);
        $this->assertStringContainsString('institution/settings/parents', $body);
        $this->assertMatchesRegularExpression('/name="enabled"[^>]*value="1"[^>]*checked/s', $body);
        $this->assertStringNotContainsString('Switched off by Mentora', $body);
    }

    public function testSwitchingItOffAndOnAgain(): void
    {
        $this->assertSame(200, $this->save(false)->response()->getStatusCode());
        $this->assertTrue((new ParentPortal())->institutionSwitchedOff($this->institution));
        $this->assertStringContainsString('switched off', json_decode($this->save(false)->getJSON(), true)['message']);

        $this->assertSame(200, $this->save(true)->response()->getStatusCode());
        $this->assertTrue((new ParentPortal())->enabled($this->institution));
    }

    public function testSwitchingItOffReachesTheInstitutionsParents(): void
    {
        $parent  = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@pps.example', $this->institution);
        $student = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@pps.example', $this->institution);
        db_connect()->table('parent_student_links')->insert(['institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->save(false);
        $this->withHeaders(['Accept' => 'text/html']);
        $this->signIn($parent);

        $body = $this->step('GET', 'parent/dashboard')->response()->getBody();

        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString('Ann Lee', $body);
    }

    public function testItDoesNotTouchAnotherInstitution(): void
    {
        $this->save(false);

        $this->assertTrue((new ParentPortal())->enabled($this->other));
    }

    public function testAnInstitutionCannotTurnOnWhatMentoraHasOff(): void
    {
        $this->mentoraSwitchesItOff();
        $this->save(false);

        $result = $this->save(true);

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertFalse((new ParentPortal())->enabled($this->institution), 'still off: Mentora has it off');
        $this->assertStringContainsString('Mentora', json_decode($result->getJSON(), true)['message']);
    }

    public function testTheTabSaysSoWhenMentoraHasItOff(): void
    {
        $this->mentoraSwitchesItOff();

        $body = $this->step('GET', 'institution/settings/parents')->response()->getBody();

        $this->assertStringContainsString('Switched off by Mentora', $body);
    }

    public function testAnUnknownChoiceIsRefused(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings/parents', ['enabled' => 'maybe']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertFalse((new ParentPortal())->institutionSwitchedOff($this->institution));
    }

    public function testTheChangeIsAudited(): void
    {
        $this->save(false);

        $entry = db_connect()->table('audit_logs')->where('action', 'settings.changed')->where('institution_id', $this->institution)->get()->getRowArray();

        $this->assertNotNull($entry);
    }

    public function testAReadOnlyInstitutionCannotChangeItAndSeesTheLock(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);

        $body   = $this->step('GET', 'institution/settings/parents')->response()->getBody();
        $result = $this->save(false)->response();

        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertFalse((new ParentPortal())->institutionSwitchedOff($this->institution));
    }
}

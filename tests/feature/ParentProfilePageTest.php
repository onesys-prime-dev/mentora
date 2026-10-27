<?php

declare(strict_types=1);

use App\Services\Parents\ParentPortal;
use App\Services\Parents\ParentPreferences;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The parent's own page: their account, a way to change the password, the
 * email choice for each child, and nothing else that a parent can change.
 *
 * @internal
 */
final class ParentProfilePageTest extends RouteTestCase
{
    private FakeEmail $mailer;

    private int $institution;

    private User $pam;

    private User $ann;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('PPP');
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@example.test', $this->institution);
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->ben         = PeopleFactory::person('student', 'Ben', 'Lee', null, $this->institution);

        $this->link($this->pam, $this->ann);
        $this->link($this->pam, $this->ben);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function link(User $parent, User $student): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{int, string, string} */
    private function visit(string $verb, string $uri, array $data = []): array
    {
        try {
            $response = $this->call($verb, $uri, $data)->response();

            return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Location')];
        } catch (PageNotFoundException) {
            return [404, '', ''];
        }
    }

    private function checked(string $body, User $child): bool
    {
        return preg_match('/<input[^>]*name="email\[\]"[^>]*value="' . $child->id . '"[^>]*checked/s', $body) === 1;
    }

    public function testThePageShowsTheAccountAndEachChildWithTheEmailSwitchOn(): void
    {
        $this->loggedInAs($this->pam);

        [$status, $body] = $this->visit('GET', '/parent/profile');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Pam Parent', $body);
        $this->assertStringContainsString('pam@example.test', $body);
        $this->assertStringContainsString('Email me a link to change my password', $body);
        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertStringContainsString('Ben Lee', $body);
        $this->assertTrue($this->checked($body, $this->ann));
        $this->assertTrue($this->checked($body, $this->ben));
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*value="pam@example.test"/', $body, 'the email is shown, not editable');
    }

    public function testSavingKeepsExactlyTheTickedChildren(): void
    {
        $this->loggedInAs($this->pam);

        [$status, , $location] = $this->visit('POST', '/parent/profile/notifications', ['email' => [(string) $this->ben->id]]);

        $this->assertSame(302, $status);
        $this->assertStringEndsWith('parent/profile', $location);
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertTrue((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ben->id));

        [, $body] = $this->visit('GET', '/parent/profile');
        $this->assertFalse($this->checked($body, $this->ann));
        $this->assertTrue($this->checked($body, $this->ben));
    }

    public function testSavingNothingTurnsEveryChildOff(): void
    {
        $this->loggedInAs($this->pam);

        $this->visit('POST', '/parent/profile/notifications', []);

        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ben->id));
    }

    public function testAStudentTheParentIsNotLinkedToIsIgnoredAndOtherParentsAreUntouched(): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $olga     = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $this->link($olga, $this->ann);
        $this->loggedInAs($this->pam);

        $this->visit('POST', '/parent/profile/notifications', ['email' => [(string) $stranger->id, 'abc', '0', (string) $this->ann->id]]);

        $this->assertSame(0, db_connect()->table('parent_child_preferences')->where('student_user_id', $stranger->id)->countAllResults());
        $this->assertTrue((new ParentPreferences())->emailEnabled((int) $olga->id, (int) $this->ann->id), 'another parent\'s choice is theirs');
        $this->assertTrue((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ben->id));
    }

    public function testThePasswordButtonEmailsTheParentOwnLinkOnly(): void
    {
        $olga = PeopleFactory::person('parent', 'Olga', 'Other', 'olga@example.test', $this->institution);
        $this->loggedInAs($this->pam);

        [$status, , $location] = $this->visit('POST', '/parent/profile/password-link');

        $this->assertSame(302, $status);
        $this->assertStringEndsWith('parent/profile', $location);
        $this->assertSame(['pam@example.test'], array_column($this->mailer->sent, 'to'));
        $this->assertSame(1, db_connect()->table('password_resets')->where('user_id', $this->pam->id)->countAllResults());
        $this->assertSame(0, db_connect()->table('password_resets')->where('user_id', $olga->id)->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'user.password_reset_sent')->where('actor_user_id', $this->pam->id)->countAllResults());
    }

    public function testAnotherPressReplacesTheLinkInsteadOfPilingUp(): void
    {
        $this->loggedInAs($this->pam);

        $this->visit('POST', '/parent/profile/password-link');
        $this->visit('POST', '/parent/profile/password-link');

        $this->assertSame(2, db_connect()->table('password_resets')->where('user_id', $this->pam->id)->countAllResults());
        $this->assertSame(1, db_connect()->table('password_resets')->where('user_id', $this->pam->id)->where('revoked_at IS NULL', null, false)->where('used_at IS NULL', null, false)->countAllResults(), 'only the latest link is open');
    }

    public function testAParentWithNoChildrenStillHasTheirAccountAndASimpleMessage(): void
    {
        $this->loggedInAs(PeopleFactory::person('parent', 'Lone', 'Parent', 'lone@example.test', $this->institution));

        [$status, $body] = $this->visit('GET', '/parent/profile');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('lone@example.test', $body);
        $this->assertStringContainsString('You are not linked to a student yet', $body);
        $this->assertStringNotContainsString('name="email[]"', $body);
    }

    public function testWhilePortalIsOffTheAccountStaysAndTheChildrenGoAway(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $this->loggedInAs($this->pam);

        [$status, $body] = $this->visit('GET', '/parent/profile');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('pam@example.test', $body);
        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString('Ann Lee', $body);
        $this->assertStringNotContainsString('name="email[]"', $body);

        $this->visit('POST', '/parent/profile/notifications', ['email' => [(string) $this->ben->id]]);
        $this->assertSame(0, db_connect()->table('parent_child_preferences')->countAllResults(), 'nothing is saved while the portal is off');

        $this->visit('POST', '/parent/profile/password-link');
        $this->assertSame(['pam@example.test'], array_column($this->mailer->sent, 'to'), 'the password link is the parent\'s own account, so it still works');
    }

    public function testAReadOnlyInstitutionRefusesBothChanges(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);
        $this->loggedInAs($this->pam);

        $this->assertSame(200, $this->visit('GET', '/parent/profile')[0]);

        $this->visit('POST', '/parent/profile/notifications', ['email' => [(string) $this->ben->id]]);
        $this->visit('POST', '/parent/profile/password-link');

        $this->assertSame(0, db_connect()->table('parent_child_preferences')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testEveryOtherRoleIsTurnedAway(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'admin'] as $role) {
            $this->loggedInAs(CourseFactory::user($role));

            foreach ([['GET', '/parent/profile'], ['POST', '/parent/profile/notifications'], ['POST', '/parent/profile/password-link']] as [$verb, $uri]) {
                $this->assertSame(302, $this->visit($verb, $uri)[0], "{$role} {$verb} {$uri}");
            }
        }

        $this->assertSame([], $this->mailer->sent);
    }

    public function testTheOnlyThingsAParentCanPostAreTheirOwnAccount(): void
    {
        $posts = array_keys(service('routes')->loadRoutes()->getRoutes('POST'));
        $mine  = array_values(array_filter($posts, static fn (string $route): bool => str_starts_with($route, 'parent/') || $route === 'parent'));

        sort($mine);

        $this->assertSame(['parent/profile/notifications', 'parent/profile/password-link'], $mine);

        foreach (['PUT', 'PATCH', 'DELETE'] as $verb) {
            $others = array_filter(array_keys(service('routes')->getRoutes($verb)), static fn (string $route): bool => str_starts_with($route, 'parent/') || $route === 'parent');

            $this->assertSame([], array_values($others), $verb);
        }
    }
}

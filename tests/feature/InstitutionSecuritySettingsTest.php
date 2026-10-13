<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionAnnouncement;
use App\Services\Institutions\InstitutionSecurity;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The Announcement and Security tabs of the institution settings: access, the
 * banner reaching only that institution's people (under the platform's) and
 * ending, security needing the password, a looser value refused beside its
 * field, and a read-only institution.
 *
 * @internal
 */
final class InstitutionSecuritySettingsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        InstitutionSecurity::forget();
        InstitutionAnnouncement::forget();

        $this->institution = SisFixture::institution('SSFA');
        $this->other       = SisFixture::institution('SSFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@ssf.example', $this->institution);

        $this->signIn($this->admin);
    }

    private function signIn(User $user, bool $confirmed = true): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $user->id, 'at' => time()];
        } else {
            unset($_SESSION['reauth']);
        }

        $this->withSession();
    }

    private function own(string $key): ?int
    {
        return (new InstitutionSecurity())->forInstitution($this->institution)['own'][$key];
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseThem(string $role): void
    {
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@ssf.example", $role === 'admin' ? null : $this->institution));

        foreach (['announcement', 'security'] as $tab) {
            $this->assertSame(302, $this->step('GET', "institution/settings/{$tab}")->response()->getStatusCode(), $tab);
        }

        $this->step('POST', 'institution/settings/announcement', ['text' => 'Hijacked']);
        $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '40']);

        $this->assertSame('', (new InstitutionAnnouncement())->get($this->institution)['text']);
        $this->assertNull($this->own('passwordMinLength'));
    }

    public function testTheAnnouncementIsSavedAndShownToThatInstitutionsPeopleOnly(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/settings/announcement', ['text' => 'Enrolment closes Friday.', 'until' => '']);

        $this->assertTrue($result->isOK());
        $this->assertSame('The announcement was saved.', json_decode($result->getJSON(), true)['message']);

        $this->withHeaders(['Accept' => 'text/html']);

        $page = $this->step('GET', 'institution/settings/announcement')->response()->getBody();
        $this->assertStringContainsString('data-announcement-preview', $page);
        $this->assertStringContainsString('It is showing now', $page);

        $own = PeopleFactory::person('student', 'Ann', 'Own', 'own@ssf.example', $this->institution);
        $this->signIn($own);
        $mine = $this->step('GET', 'student/courses')->response()->getBody();
        $this->assertStringContainsString('data-institution-announcement', $mine);
        $this->assertStringContainsString('Enrolment closes Friday.', $mine);

        $foreign = PeopleFactory::person('student', 'Zed', 'Other', 'zed@ssf.example', $this->other);
        $this->signIn($foreign);
        $this->assertStringNotContainsString('data-institution-announcement', $this->step('GET', 'student/courses')->response()->getBody());
    }

    public function testItShowsUnderThePlatformsAnnouncement(): void
    {
        (new \App\Services\Admin\PlatformSettings())->set('announcement', 'Maintenance tonight.');
        (new InstitutionAnnouncement())->save($this->institution, 'Enrolment closes Friday.', '');

        $body = $this->step('GET', 'institution/dashboard')->response()->getBody();

        $platform = strpos($body, 'Maintenance tonight.');
        $school   = strpos($body, 'Enrolment closes Friday.');

        $this->assertNotFalse($platform);
        $this->assertNotFalse($school);
        $this->assertLessThan($school, $platform, 'the platform line comes first');
    }

    public function testAnEndedAnnouncementIsNoLongerShown(): void
    {
        db_connect()->table('settings')->insert(['class' => \Config\Platform::class, 'key' => 'institutionAnnouncement', 'value' => 'Open day.', 'type' => 'string', 'context' => 'institution:' . $this->institution, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('settings')->insert(['class' => \Config\Platform::class, 'key' => 'institutionAnnouncementUntil', 'value' => '2001-01-01', 'type' => 'string', 'context' => 'institution:' . $this->institution, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        Services::resetSingle('settings');

        $this->assertStringNotContainsString('data-institution-announcement', $this->step('GET', 'institution/dashboard')->response()->getBody());
        $this->assertStringContainsString('is no longer shown', $this->step('GET', 'institution/settings/announcement')->response()->getBody());
    }

    public function testMarkupIsRefusedBesideTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings/announcement', ['text' => '<b>Hi</b>']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('text', json_decode($result->getJSON(), true)['errors']);
    }

    public function testSavingItEmptyTakesItDown(): void
    {
        (new InstitutionAnnouncement())->save($this->institution, 'Open day.', '');

        $this->step('POST', 'institution/settings/announcement', ['text' => '', 'until' => '']);

        $this->assertSame('', (new InstitutionAnnouncement())->get($this->institution)['text']);
        $this->assertStringContainsString('No announcement is set', $this->step('GET', 'institution/settings/announcement')->response()->getBody());
    }

    public function testTheSecurityTabShowsThePlatformsValuesAndWhatIsInForce(): void
    {
        $body = $this->step('GET', 'institution/settings/security')->response()->getBody();

        $this->assertStringContainsString('data-security-form', $body);
        $this->assertStringContainsString('stricter</strong> than the platform', $body);
        $this->assertStringContainsString('data-platform-value', $body);
        $this->assertStringContainsString('data-in-force', $body);
        $this->assertStringContainsString('name="twoFactorRoles[]"', $body);
        $this->assertStringNotContainsString('Go back to the platform values', $body);
    }

    public function testSavingSecurityNeedsThePasswordAndThenHoldsThePeopleToIt(): void
    {
        $this->signIn($this->admin, false);

        $this->withHeaders(['Accept' => 'application/json']);
        $refused = $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '14']);

        $this->assertSame(403, $refused->response()->getStatusCode());
        $this->assertSame('reauth_required', json_decode($refused->getJSON(), true)['error']);
        $this->assertNull($this->own('passwordMinLength'));

        $this->signIn($this->admin, true);
        $this->withHeaders(['Accept' => 'application/json']);
        $done = $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '14', 'sessionTimeoutMinutes' => '30', 'twoFactorRoles' => ['instructor']]);

        $this->assertTrue($done->isOK());
        $this->assertSame(14, $this->own('passwordMinLength'));
        $this->assertSame(30, $this->own('sessionTimeoutMinutes'));

        $this->withHeaders(['Accept' => 'text/html']);
        $page = $this->step('GET', 'institution/settings/security')->response()->getBody();
        $this->assertStringContainsString('value="14"', $page);
        $this->assertStringContainsString('Go back to the platform values', $page);
    }

    public function testALooserValueAnswers422BesideItsFieldAndNothingIsSaved(): void
    {
        // The platform asks for 12; 10 is a valid length but looser.
        (new \App\Services\Admin\PlatformSettings())->set('passwordMinLength', 12);

        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '10', 'sessionTimeoutMinutes' => '30']);

        $this->assertSame(422, $result->response()->getStatusCode());

        $errors = json_decode($result->getJSON(), true)['errors'];
        $this->assertArrayHasKey('passwordMinLength', $errors);
        $this->assertStringContainsString('only ask for more', $errors['passwordMinLength']);
        $this->assertNull($this->own('sessionTimeoutMinutes'));
    }

    public function testResettingGoesBackToThePlatformValues(): void
    {
        $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '14']);
        $this->assertSame(14, $this->own('passwordMinLength'));

        $this->step('POST', 'institution/settings/security/reset');

        $this->assertNull($this->own('passwordMinLength'));
    }

    public function testAnotherInstitutionIsNeverTouched(): void
    {
        $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '14']);
        (new InstitutionAnnouncement())->save($this->institution, 'Open day.', '');

        $this->assertNull((new InstitutionSecurity())->forInstitution($this->other)['own']['passwordMinLength']);
        $this->assertSame('', (new InstitutionAnnouncement())->get($this->other)['text']);
    }

    public function testAReadOnlyInstitutionCanLookButNotChange(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $page = $this->step('GET', 'institution/settings/security')->response()->getBody();
        $this->assertStringContainsString('data-lock="read_only"', $page);
        $this->assertStringNotContainsString('Save security settings', $page);

        $this->step('POST', 'institution/settings/security', ['passwordMinLength' => '14']);
        $this->step('POST', 'institution/settings/announcement', ['text' => 'Hijacked']);

        $this->assertNull($this->own('passwordMinLength'));
        $this->assertSame('', (new InstitutionAnnouncement())->get($this->institution)['text']);
    }

    public function testTheSettingsTabsLinkToThem(): void
    {
        $body = html_entity_decode($this->step('GET', 'institution/settings')->response()->getBody());

        $this->assertStringContainsString('settings/announcement', $body);
        $this->assertStringContainsString('settings/security', $body);
    }
}

<?php

declare(strict_types=1);

use App\Auth\Actions\AdminEmail2FA;
use App\Filters\AccountStateFilter;
use App\Services\Admin\PlatformSettings;
use App\Services\Institutions\InstitutionAnnouncement;
use App\Services\Institutions\InstitutionSecurity;
use App\Services\Institutions\StructureException;
use App\Services\Security\LoginLockout;
use App\Services\Security\PasswordPolicy;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * An institution's security settings and banner: stricter-only saving, what
 * is in force for its people (and not for another institution's), each of the
 * four enforcement points, the announcement's rules, and the audit trail.
 *
 * @internal
 */
final class InstitutionSecurityTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private InstitutionSecurity $security;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('SECA');
        $this->other       = SisFixture::institution('SECB');
        $this->security    = new InstitutionSecurity();

        InstitutionSecurity::forget();
        InstitutionAnnouncement::forget();
    }

    protected function tearDown(): void
    {
        InstitutionSecurity::forget();
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the change to be refused.');
    }

    /*
    |--------------------------------------------------------------------------
    | Saving: stricter only
    |--------------------------------------------------------------------------
    */

    public function testStricterValuesAreSavedAndTheInstitutionIsHeldToThem(): void
    {
        $this->security->save($this->institution, ['passwordMinLength' => '12', 'sessionTimeoutMinutes' => '30', 'lockoutAttempts' => '3', 'lockoutMinutes' => '60', 'twoFactorRoles' => ['student']]);

        $state = $this->security->forInstitution($this->institution);

        $this->assertSame(12, $state['effective']['passwordMinLength']);
        $this->assertSame(30, $state['effective']['sessionTimeoutMinutes']);
        $this->assertSame(3, $state['effective']['lockoutAttempts']);
        $this->assertSame(60, $state['effective']['lockoutMinutes']);
        $this->assertSame(['admin', 'student'], $state['effective']['twoFactorRoles']);
        $this->assertSame(12, $state['own']['passwordMinLength']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideLooser(): iterable
    {
        yield 'a shorter password than the platform' => [['passwordMinLength' => '6'], 'passwordMinLength'];
        yield 'a longer idle time than the platform' => [['sessionTimeoutMinutes' => '121'], 'sessionTimeoutMinutes'];
        yield 'more failed attempts than the platform' => [['lockoutAttempts' => '9'], 'lockoutAttempts'];
        yield 'never lock, when the platform locks' => [['lockoutAttempts' => '0'], 'lockoutAttempts'];
        yield 'a shorter lockout than the platform' => [['lockoutMinutes' => '5'], 'lockoutMinutes'];
        yield 'not a number' => [['passwordMinLength' => 'long'], 'passwordMinLength'];
        yield 'a role that cannot be asked for a code' => [['twoFactorRoles' => ['admin2']], 'twoFactorRoles'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideLooser')]
    public function testALooserValueIsRefusedNamingTheFieldAndNothingIsSaved(array $input, string $field): void
    {
        // Something stricter alongside it must not be saved either.
        $e = $this->refused(fn () => $this->security->save($this->institution, $input + ['sessionTimeoutMinutes' => '30']));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertNull($this->security->forInstitution($this->institution)['own']['sessionTimeoutMinutes']);
    }

    public function testAValueEqualToThePlatformsIsNotStoredSoTheInstitutionFollowsIt(): void
    {
        $platform = (int) (new PlatformSettings())->get('passwordMinLength');

        $this->security->save($this->institution, ['passwordMinLength' => (string) $platform]);

        $this->assertNull($this->security->forInstitution($this->institution)['own']['passwordMinLength']);

        // If the platform tightens, the institution follows.
        (new PlatformSettings())->set('passwordMinLength', 16);
        $this->assertSame(16, $this->security->forInstitution($this->institution)['effective']['passwordMinLength']);
    }

    public function testWhenThePlatformTightensPastTheInstitutionThePlatformWins(): void
    {
        $this->security->save($this->institution, ['passwordMinLength' => '10']);
        (new PlatformSettings())->set('passwordMinLength', 14);

        $this->assertSame(14, $this->security->forInstitution($this->institution)['effective']['passwordMinLength']);
    }

    public function testTheRolesTheInstitutionAddsStayWhenOnlyTheirsAreChosenAndThePlatformsCannotBeRemoved(): void
    {
        (new PlatformSettings())->set('twoFactorRoles', 'student');

        // The page sends the platform's roles ticked as well; only the others are the institution's.
        $this->security->save($this->institution, ['twoFactorRoles' => ['instructor', 'student']]);

        $this->assertSame(['instructor'], $this->security->forInstitution($this->institution)['ownRoles'], 'student is the platform\'s, not an extra');
        $this->assertSame(['admin', 'student', 'instructor'], $this->security->forInstitution($this->institution)['effective']['twoFactorRoles']);

        $this->security->save($this->institution, ['twoFactorRoles' => []]);

        $this->assertSame([], $this->security->forInstitution($this->institution)['ownRoles']);
    }

    public function testResetGoesBackToThePlatformsValues(): void
    {
        $this->security->save($this->institution, ['passwordMinLength' => '12', 'twoFactorRoles' => ['student']]);
        $this->security->reset($this->institution);

        $state = $this->security->forInstitution($this->institution);

        $this->assertNull($state['own']['passwordMinLength']);
        $this->assertSame([], $state['ownRoles']);
    }

    public function testEachChangeIsAudited(): void
    {
        db_connect()->table('audit_logs')->emptyTable();

        $this->security->save($this->institution, ['passwordMinLength' => '12']);

        $row = db_connect()->table('audit_logs')->where('action', 'settings.changed')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame($this->institution, (int) $row['institution_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Enforcement: only that institution's people
    |--------------------------------------------------------------------------
    */

    public function testThePasswordRuleHoldsTheInstitutionsPeopleOnly(): void
    {
        $this->security->save($this->institution, ['passwordMinLength' => '30']);

        $mine    = PeopleFactory::person('student', 'Stu', 'Mine', null, $this->institution);
        $theirs  = PeopleFactory::person('student', 'Zed', 'Theirs', null, $this->other);
        $policy  = new PasswordPolicy();
        $twelve  = 'Tr4ins-Lantern-Moss-92';

        $this->assertNotNull($policy->problem($twelve, $mine));
        $this->assertStringContainsString('at least 30 characters', (string) $policy->problem($twelve, $mine));
        $this->assertNull($policy->problem($twelve, $theirs));
        $this->assertNull($policy->problem('Tr4ins-Lantern-Moss-92-and-a-bit-more', $mine));
    }

    public function testSomeoneBeingInvitedIsHeldToTheInvitingInstitutionsRule(): void
    {
        $this->security->save($this->institution, ['passwordMinLength' => '30']);

        $nobody = new \CodeIgniter\Shield\Entities\User(['username' => 'new.person', 'email' => 'new.person@example.com']);

        $this->assertStringContainsString('at least 30 characters', (string) (new PasswordPolicy())->problem('Tr4ins-Lantern-Moss-92', $nobody, $this->institution));
        $this->assertNull((new PasswordPolicy())->problem('Tr4ins-Lantern-Moss-92', $nobody, $this->other));
    }

    public function testTheSessionTimeoutInForceIsTheStricter(): void
    {
        $this->security->save($this->institution, ['sessionTimeoutMinutes' => '10']);

        $mine   = PeopleFactory::person('student', 'Stu', 'Mine', null, $this->institution);
        $theirs = PeopleFactory::person('student', 'Zed', 'Theirs', null, $this->other);

        $this->assertSame(10, $this->security->forUser($mine)['sessionTimeoutMinutes']);
        $this->assertSame(120, $this->security->forUser($theirs)['sessionTimeoutMinutes']);
        $this->assertTrue(method_exists(AccountStateFilter::class, 'before'));
    }

    public function testTheLockoutFollowsTheAccountsInstitution(): void
    {
        $this->security->save($this->institution, ['lockoutAttempts' => '2', 'lockoutMinutes' => '60']);

        PeopleFactory::person('student', 'Stu', 'Mine', 'stu.mine@example.com', $this->institution);
        PeopleFactory::person('student', 'Zed', 'Theirs', 'zed.theirs@example.com', $this->other);

        $now = new DateTimeImmutable('2026-10-10 12:00:00');

        foreach (['stu.mine@example.com', 'zed.theirs@example.com'] as $email) {
            foreach (['2026-10-10 11:30:00', '2026-10-10 11:40:00'] as $when) {
                db_connect()->table('auth_logins')->insert(['ip_address' => '127.0.0.1', 'user_agent' => 't', 'id_type' => 'email_password', 'identifier' => $email, 'user_id' => null, 'date' => $when, 'success' => 0]);
            }
        }

        $until = (new LoginLockout())->lockedUntil('stu.mine@example.com', $now);

        $this->assertSame('2026-10-10 12:40:00', $until?->format('Y-m-d H:i:s'), 'two failures lock it, for the institution\'s 60 minutes');
        $this->assertNull((new LoginLockout())->lockedUntil('zed.theirs@example.com', $now), 'the platform needs five');
    }

    public function testTheEmailedCodeAppliesToTheRolesTheInstitutionAddedForItsOwnPeople(): void
    {
        $this->security->save($this->institution, ['twoFactorRoles' => ['instructor']]);

        $action = new AdminEmail2FA();

        $this->assertTrue($action->appliesTo(PeopleFactory::person('instructor', 'Ivy', 'Mine', null, $this->institution)));
        $this->assertFalse($action->appliesTo(PeopleFactory::person('instructor', 'Ian', 'Theirs', null, $this->other)));
        $this->assertFalse($action->appliesTo(PeopleFactory::person('student', 'Stu', 'Mine', null, $this->institution)));
        $this->assertTrue($action->appliesTo(PeopleFactory::person('admin')));
    }

    /*
    |--------------------------------------------------------------------------
    | The announcement
    |--------------------------------------------------------------------------
    */

    public function testAnAnnouncementIsSavedShownToItsPeopleAndRemoved(): void
    {
        $announcement = new InstitutionAnnouncement();
        $mine         = PeopleFactory::person('student', 'Stu', 'Mine', null, $this->institution);
        $theirs       = PeopleFactory::person('student', 'Zed', 'Theirs', null, $this->other);
        $sysadmin     = PeopleFactory::person('admin');

        $announcement->save($this->institution, "  Enrolment   closes\nFriday.  ", '');

        $this->assertSame('Enrolment closes Friday.', $announcement->forUser($mine));
        $this->assertNull($announcement->forUser($theirs));
        $this->assertNull($announcement->forUser($sysadmin));

        $announcement->remove($this->institution);
        InstitutionAnnouncement::forget();

        $this->assertNull($announcement->forUser($mine));
    }

    public function testAnAnnouncementStopsShowingAfterItsLastDay(): void
    {
        $announcement = new InstitutionAnnouncement();
        $tomorrow     = date('Y-m-d', strtotime('+1 day'));

        $announcement->save($this->institution, 'Open day.', $tomorrow);

        $this->assertTrue($announcement->get($this->institution)['showing']);
        $this->assertTrue($announcement->get($this->institution, new DateTimeImmutable($tomorrow . ' 23:00:00'))['showing'], 'the whole of the last day');
        $this->assertFalse($announcement->get($this->institution, new DateTimeImmutable($tomorrow . ' 23:00:00 +1 day'))['showing']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideBadAnnouncements(): iterable
    {
        yield 'too long' => [str_repeat('x', 301), '', 'text'];
        yield 'markup' => ['<script>alert(1)</script>', '', 'text'];
        yield 'a date that is not one' => ['Open day.', '31-12-2030', 'until'];
        yield 'an impossible date' => ['Open day.', '2030-02-31', 'until'];
        yield 'a day that has passed' => ['Open day.', '2001-01-01', 'until'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadAnnouncements')]
    public function testABadAnnouncementIsRefusedBesideTheField(string $text, string $until, string $field): void
    {
        $e = $this->refused(fn () => (new InstitutionAnnouncement())->save($this->institution, $text, $until));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertSame('', (new InstitutionAnnouncement())->get($this->institution)['text']);
    }

    public function testSavingItEmptyTakesItDown(): void
    {
        $announcement = new InstitutionAnnouncement();
        $announcement->save($this->institution, 'Open day.', '');
        $announcement->save($this->institution, '   ', '');

        $this->assertSame('', $announcement->get($this->institution)['text']);
    }
}

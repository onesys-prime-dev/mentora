<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Admin\PermissionMatrix;
use App\Services\Parents\ParentPortal;
use App\Services\Parents\ParentPreferences;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Settings: the Notifications card (D15) — the Messages email switch and quiet hours, and a parent's email choice
 * for each child, moved here unchanged (AC8) — and the old addresses that now lead to it (D23).
 *
 * @internal
 */
final class SettingsNotificationsTest extends RouteTestCase
{
    private FakeEmail $mailer;

    private int $institution;

    private User $ivo;

    private User $pam;

    private User $ann;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        PermissionMatrix::flush();
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'messages', 'institution_id' => null, 'enabled' => 1]);

        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('SNT');
        $this->ivo         = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->institution);
        $this->pam         = PeopleFactory::person('parent', 'Pam', 'Parent', 'pam@example.test', $this->institution);
        $this->ann         = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->ben         = PeopleFactory::person('student', 'Ben', 'Lee', null, $this->institution);

        $this->link($this->pam, $this->ann);
        $this->link($this->pam, $this->ben);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function link(User $parent, User $student): void
    {
        db_connect()->table('parent_student_links')->insert([
            'institution_id' => $this->institution, 'parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function signIn(User $user): void
    {
        auth()->logout();
        $this->withSession([]);
        Services::resetSingle('auth');
        $this->loggedInAs($user);
        $this->withSession([AccountStateFilter::SIGNED_IN_AT => time() - 60]);
    }

    private function page(User $user): string
    {
        $this->signIn($user);

        return (string) $this->step('GET', 'settings')->response()->getBody();
    }

    private function quietHoursShown(string $body): bool
    {
        return str_contains($body, 'name="quiet_start"');
    }

    private function childChecked(string $body, User $child): bool
    {
        return preg_match('/<input[^>]*name="email\[\]"[^>]*value="' . $child->id . '"[^>]*\schecked[\s>]/s', $body) === 1;
    }

    /** @return list<array<string, mixed>> every stored choice, timestamps and all */
    private function rows(): array
    {
        $db = db_connect();

        return [
            $db->table('message_preferences')->orderBy('user_id')->get()->getResultArray(),
            $db->table('parent_child_preferences')->orderBy('parent_user_id')->orderBy('student_user_id')->get()->getResultArray(),
        ];
    }

    public function testTheCardComesAfterSignedInDevicesWithTheEmailSwitchAndQuietHoursForAnInstructor(): void
    {
        $body = $this->page($this->ivo);

        $this->assertStringContainsString('href="#notifications"', $body);
        $this->assertLessThan(strpos($body, 'id="notifications"'), strpos($body, 'id="devices"'));
        $this->assertStringContainsString('Email me about new messages', $body);
        $this->assertMatchesRegularExpression('#type="radio" name="email_scope" value="everyone"[^>]*\schecked[\s>]#', $body, 'everyone is the default');
        $this->assertStringContainsString('People I&#039;ve talked to', $body);
        $this->assertTrue($this->quietHoursShown($body));
        $this->assertStringContainsString('Save notifications', $body);
    }

    public function testQuietHoursAreOfferedOnlyToInstructorsChairpersonsAndDeansByTheirRoles(): void
    {
        foreach (['chairperson', 'dean'] as $role) {
            $this->assertTrue($this->quietHoursShown($this->page(PeopleFactory::person($role, 'Q', ucfirst($role), null, $this->institution))), $role);
        }

        foreach ([$this->ann, $this->pam] as $other) {
            $body = $this->page($other);
            $this->assertStringContainsString('Email me about new messages', $body, 'the email switch is for everyone');
            $this->assertFalse($this->quietHoursShown($body), (string) $other->username);
        }

        // A parent who also teaches is offered them whichever role they are using (Shield groups, not the active role).
        $both = PeopleFactory::person('parent', 'Dua', 'Role', null, $this->institution);
        $both->addGroup('instructor');
        $both = model(UserModel::class)->findById($both->id);
        $this->signIn($both);
        session()->set('active_role', 'parent');
        $this->assertTrue($this->quietHoursShown((string) $this->step('GET', 'settings')->response()->getBody()));

        $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone', 'quiet_on' => '1', 'quiet_start' => '21:00', 'quiet_end' => '06:00']);
        $this->assertSame('Notifications saved', session('message'));
        $this->assertSame('21:00:00', db_connect()->table('message_preferences')->where('user_id', $both->id)->get()->getRow('quiet_start'));
    }

    public function testAnInstructorSavesQuietHoursAndTheEmailSwitch(): void
    {
        $this->signIn($this->ivo);

        $this->step('POST', 'settings/notifications', ['email_scope' => 'off', 'quiet_on' => '1', 'quiet_start' => '22:00', 'quiet_end' => '07:00']);

        $this->assertSame('Notifications saved', session('message'));
        $row = db_connect()->table('message_preferences')->where('user_id', $this->ivo->id)->get()->getRowArray();
        $this->assertSame(['22:00:00', '07:00:00', 0], [$row['quiet_start'], $row['quiet_end'], (int) $row['email_alerts']]);

        $body = (string) $this->step('GET', 'settings')->response()->getBody();
        $this->assertMatchesRegularExpression('#name="quiet_start"[^>]*value="22:00"#', $body);
        $this->assertMatchesRegularExpression('#name="email_scope" value="off"[^>]*\schecked[\s>]#', $body);

        // Turning the quiet-hours switch off clears them.
        $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone', 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
        $this->assertNull(db_connect()->table('message_preferences')->where('user_id', $this->ivo->id)->get()->getRow('quiet_start'));
    }

    public function testEachScopeRoundTripsAndAnInvalidOneIsRefusedWithoutChangingTheStoredOne(): void
    {
        $this->signIn($this->ivo);

        foreach (['talked', 'off', 'everyone'] as $scope) {
            $this->step('POST', 'settings/notifications', ['email_scope' => $scope]);
            $this->assertSame('Notifications saved', session('message'), $scope);
            $this->assertSame($scope, (new \App\Services\Messaging\MessagePreferences())->get((int) $this->ivo->id)['email_scope']);
            $this->assertMatchesRegularExpression('#name="email_scope" value="' . $scope . '"[^>]*\schecked[\s>]#', (string) $this->step('GET', 'settings')->response()->getBody());
        }

        $this->step('POST', 'settings/notifications', ['email_scope' => 'talked']);
        $before = $this->rows();
        $this->step('POST', 'settings/notifications', ['email_scope' => 'bogus']);

        $this->assertNotEmpty(session('errors')['email_scope'] ?? null);
        $this->assertSame($before, $this->rows());
    }

    public function testBadTimesAreRefusedAndChangeNothing(): void
    {
        $this->signIn($this->ivo);
        $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone', 'quiet_on' => '1', 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
        $before = $this->rows();

        foreach ([['25:00', '07:00'], ['22:00', ''], ['', '07:00'], ['22:60', '07:00']] as [$start, $end]) {
            $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone', 'quiet_on' => '1', 'quiet_start' => $start, 'quiet_end' => $end]);
            $this->assertSame('Enter both times, as hours and minutes.', session('errors')['quiet_start'] ?? null, "{$start}-{$end}");
        }

        $this->assertSame($before, $this->rows());
    }

    public function testAStudentCannotSetQuietHoursEvenByPostingThem(): void
    {
        $this->signIn($this->ann);

        $this->step('POST', 'settings/notifications', ['email_scope' => 'off', 'quiet_on' => '1', 'quiet_start' => '22:00', 'quiet_end' => '07:00']);

        $row = db_connect()->table('message_preferences')->where('user_id', $this->ann->id)->get()->getRowArray();
        $this->assertNull($row['quiet_start'], 'the hours are not theirs to set; the email switch still saves');
        $this->assertSame(0, (int) $row['email_alerts']);
    }

    public function testSavingWithoutChangesLeavesEveryStoredChoiceByteIdentical(): void
    {
        // AC8: choices made on the old pages, then the new card saved untouched.
        $db = db_connect();
        $db->table('message_preferences')->insert(['user_id' => $this->ivo->id, 'quiet_start' => '21:30:00', 'quiet_end' => '06:15:00', 'email_alerts' => 0]);
        $db->table('message_preferences')->insert(['user_id' => $this->pam->id, 'quiet_start' => null, 'quiet_end' => null, 'email_alerts' => 1]);
        $db->table('parent_child_preferences')->insert(['parent_user_id' => $this->pam->id, 'student_user_id' => $this->ann->id, 'email_enabled' => 0, 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05']);
        $before = $this->rows();

        foreach ([$this->ivo, $this->pam] as $user) {
            $body = $this->page($user);
            $this->step('POST', 'settings/notifications', $this->formValues($body));
            $this->assertSame('Notifications saved', session('message'));
        }

        $this->assertSame($before, $this->rows());
    }

    /**
     * What a browser would post for the card as drawn: every checked checkbox and every time input.
     *
     * @return array<string, mixed>
     */
    private function formValues(string $body): array
    {
        preg_match('#<form[^>]*action="[^"]*settings/notifications"[^>]*>(.*?)</form>#s', $body, $form);
        $this->assertNotEmpty($form, 'the card has its form');
        $values = [];

        preg_match_all('#<input[^>]*>#', $form[1], $inputs);

        foreach ($inputs[0] as $input) {
            preg_match('#name="([^"]+)"#', $input, $name);
            preg_match('#value="([^"]*)"#', $input, $value);
            preg_match('#type="([^"]+)"#', $input, $type);

            if ($name === [] || $name[1] === 'csrf_test_name' || (in_array($type[1] ?? '', ['checkbox', 'radio'], true) && preg_match('#\schecked[\s>]#', $input) !== 1)) {
                continue;
            }

            str_ends_with($name[1], '[]') ? $values[substr($name[1], 0, -2)][] = $value[1] ?? '' : $values[$name[1]] = $value[1] ?? '';
        }

        return $values;
    }

    public function testAParentSeesEachChildAndSavesExactlyTheTickedOnes(): void
    {
        $body = $this->page($this->pam);

        $this->assertStringContainsString('Ann Lee', $body);
        $this->assertTrue($this->childChecked($body, $this->ann));
        $this->assertTrue($this->childChecked($body, $this->ben));

        $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone', 'email' => [(string) $this->ben->id]]);

        $this->assertSame('Notifications saved', session('message'));
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertTrue((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ben->id));

        $body = (string) $this->step('GET', 'settings')->response()->getBody();
        $this->assertFalse($this->childChecked($body, $this->ann));
        $this->assertTrue($this->childChecked($body, $this->ben));

        // Nothing ticked turns every child off.
        $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone']);
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ben->id));
    }

    public function testAStudentTheParentIsNotLinkedToIsIgnoredAndOtherParentsAreUntouched(): void
    {
        $stranger = PeopleFactory::person('student', 'Zed', 'Stranger', null, $this->institution);
        $olga     = PeopleFactory::person('parent', 'Olga', 'Other', null, $this->institution);
        $this->link($olga, $this->ann);
        $this->signIn($this->pam);

        $this->step('POST', 'settings/notifications', ['email' => [(string) $stranger->id, 'abc', '0', (string) $this->ann->id]]);

        $this->assertSame(0, db_connect()->table('parent_child_preferences')->where('student_user_id', $stranger->id)->countAllResults());
        $this->assertTrue((new ParentPreferences())->emailEnabled((int) $olga->id, (int) $this->ann->id), 'another parent\'s choice is theirs');
        $this->assertTrue((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ann->id));
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ben->id));
    }

    public function testAParentWithNoChildrenGetsASimpleLineAndNoSwitches(): void
    {
        $body = $this->page(PeopleFactory::person('parent', 'Lone', 'Parent', null, $this->institution));

        $this->assertStringContainsString('You are not linked to a student yet', $body);
        $this->assertStringNotContainsString('name="email[]"', $body);
    }

    public function testWhileThePortalIsOffTheChildrenGoAwayAndNothingIsSavedForThem(): void
    {
        (new ParentPortal())->setInstitutionOn($this->institution, false);
        $body = $this->page($this->pam);

        $this->assertStringContainsString('parent portal is switched off', $body);
        $this->assertStringNotContainsString('Ann Lee', $body);
        $this->assertStringNotContainsString('name="email[]"', $body);

        $this->step('POST', 'settings/notifications', ['email_scope' => 'everyone', 'email' => [(string) $this->ben->id]]);
        $this->assertSame(0, db_connect()->table('parent_child_preferences')->countAllResults());
    }

    public function testOnlyParentsSeeTheChildrenPart(): void
    {
        $this->assertStringNotContainsString('Your children', $this->page($this->ivo));
        $this->assertStringContainsString('Your children', $this->page($this->pam));
    }

    public function testAReadOnlyInstitutionStillSavesThem(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);

        $this->signIn($this->pam);
        $this->step('POST', 'settings/notifications', ['email' => [(string) $this->ben->id]]);
        $this->assertSame('Notifications saved', session('message'));
        $this->assertFalse((new ParentPreferences())->emailEnabled((int) $this->pam->id, (int) $this->ann->id));

        $this->signIn($this->ivo);
        $this->step('POST', 'settings/notifications', ['quiet_on' => '1', 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
        $this->assertSame('Notifications saved', session('message'));
    }

    public function testWithoutACsrfTokenNothingChanges(): void
    {
        $this->signIn($this->pam);
        $this->withoutCsrfToken();

        $this->step('POST', 'settings/notifications', ['email' => []]);

        $this->assertSame([[], []], $this->rows());
    }

    public function testTheOldAddressesLeadHereWithoutActing(): void
    {
        $this->signIn($this->ivo);
        $old = $this->step('GET', 'messages/api/preferences')->response();
        $this->assertSame(301, $old->getStatusCode());
        $this->assertStringEndsWith('settings#notifications', $old->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('messages/api/preferences', service('routes')->loadRoutes()->getRoutes('POST'), 'the old form is gone');

        $this->signIn($this->pam);
        $profile = $this->step('GET', 'parent/profile')->response();
        $this->assertSame(301, $profile->getStatusCode());
        $this->assertStringEndsWith('/profile', $profile->getHeaderLine('Location'));

        foreach (['notifications' => 'settings#notifications', 'password-link' => 'settings#password'] as $form => $to) {
            $moved = $this->step('POST', "parent/profile/{$form}", ['email' => []])->response();
            $this->assertSame(303, $moved->getStatusCode(), $form);
            $this->assertStringEndsWith($to, $moved->getHeaderLine('Location'), $form);
        }

        $this->assertSame([[], []], $this->rows(), 'nothing saved');
        $this->assertSame([], $this->mailer->sent, 'no link sent');
    }

    public function testMessagesLinksToTheseSettingsAndTheParentMenuToTheProfile(): void
    {
        $this->signIn($this->ivo);
        $messages = (string) $this->step('GET', 'messages')->response()->getBody();

        $this->assertStringContainsString('href="' . site_url('settings') . '#notifications"', $messages);
        $this->assertStringNotContainsString('data-role="prefs-email"', $messages, 'the old form is gone from Messages');

        helper('navigation');
        $profile = array_values(array_filter(global_apps('parent'), static fn (array $app): bool => $app['key'] === 'profile'));
        $this->assertSame(site_url('profile'), $profile[0]['url']);
    }
}

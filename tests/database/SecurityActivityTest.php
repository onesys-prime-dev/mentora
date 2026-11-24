<?php

declare(strict_types=1);

use App\Services\Profiles\SecurityActivity;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * Recent security activity (D16): the person's own security changes and what an administrator did to their
 * account, in the last 90 days, newest first, each with a plain label. Profile edits and other people's
 * entries are never in it.
 *
 * @internal
 */
final class SecurityActivityTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $me;

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = PeopleFactory::institution('SAC');
        $this->me    = (int) PeopleFactory::person('instructor', 'Mia', 'Owner', null, $institution)->id;
        $this->admin = (int) PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $institution)->id;
    }

    private function entry(string $action, int $actor, int $target, string $at): void
    {
        db_connect()->table('audit_logs')->insert([
            'action' => $action, 'actor_user_id' => $actor, 'target_type' => 'user', 'target_id' => $target, 'created_at' => $at,
        ]);
    }

    private function daysAgo(int $days, int $minutes = 0): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400 - $minutes * 60);
    }

    public function testOwnSecurityChangesAndAdminActionsOnTheAccountNewestFirstWithLabels(): void
    {
        $latest = $this->daysAgo(1);
        $this->entry('profile.password_changed', $this->me, $this->me, $latest);
        $this->entry('profile.two_factor_on', $this->me, $this->me, $this->daysAgo(2));
        $this->entry('profile.two_factor_off', $this->me, $this->me, $this->daysAgo(3));
        $this->entry('profile.signed_out_others', $this->me, $this->me, $this->daysAgo(4));
        $this->entry('profile.photo_removed_by_admin', $this->admin, $this->me, $this->daysAgo(5));
        $this->entry('profile.photo_uploaded_by_admin', $this->admin, $this->me, $this->daysAgo(6));
        $this->entry('user.deactivated', $this->admin, $this->me, $this->daysAgo(7));
        $this->entry('user.reactivated', $this->admin, $this->me, $this->daysAgo(8));
        $this->entry('user.signed_out', $this->admin, $this->me, $this->daysAgo(9));
        $this->entry('user.password_reset_sent', $this->admin, $this->me, $this->daysAgo(10));
        $this->entry('user.password_reset_sent', $this->me, $this->me, $this->daysAgo(11));
        $this->entry('user.password_reset', $this->me, $this->me, $this->daysAgo(12));
        $this->entry('user.roles_changed', $this->admin, $this->me, $this->daysAgo(13));
        $this->entry('user.updated', $this->admin, $this->me, $this->daysAgo(14));

        $this->assertSame([
            'Password changed',
            'Two-step sign-in turned on',
            'Two-step sign-in turned off',
            'Signed out of all other devices',
            'Your photo was removed by your school',
            'An administrator added a profile photo',
            'Your account was deactivated',
            'Your account was reactivated',
            'An administrator signed you out of every device',
            'An administrator sent you a password reset link',
            'You asked for a password reset link',
            'Password reset with an emailed link',
            'Your roles were changed',
            'An administrator updated your name or email',
        ], array_column((new SecurityActivity())->recent($this->me), 'label'));

        $first = (new SecurityActivity())->recent($this->me)[0];
        $this->assertSame(['action' => 'profile.password_changed', 'label' => 'Password changed', 'at' => $latest, 'by_admin' => false], $first);
    }

    public function testEachEntryKnowsWhetherAnAdministratorDidIt(): void
    {
        $this->entry('user.password_reset_sent', $this->admin, $this->me, $this->daysAgo(1));
        $this->entry('user.password_reset_sent', $this->me, $this->me, $this->daysAgo(2));
        $this->entry('user.password_reset', $this->me, $this->me, $this->daysAgo(3));
        $this->entry('user.welcome_link_sent', $this->admin, $this->me, $this->daysAgo(4));

        $rows = (new SecurityActivity())->recent($this->me);

        $this->assertSame([true, false, false, true], array_column($rows, 'by_admin'));
        $this->assertSame('An administrator sent you a welcome link', $rows[3]['label']);
    }

    public function testProfileEditsOwnPhotoChangesAndOtherPeoplesEntriesAreLeftOut(): void
    {
        $other = (int) PeopleFactory::person('student', 'Oli', 'Other')->id;

        $this->entry('profile.updated', $this->me, $this->me, $this->daysAgo(1));
        $this->entry('profile.photo_updated', $this->me, $this->me, $this->daysAgo(1));
        $this->entry('auth.login', $this->me, $this->me, $this->daysAgo(1));
        $this->entry('profile.password_changed', $other, $other, $this->daysAgo(1));
        $this->entry('user.deactivated', $this->me, $other, $this->daysAgo(1)); // something Mia did to someone else
        db_connect()->table('audit_logs')->insert(['action' => 'profile.password_changed', 'actor_user_id' => $this->me, 'target_type' => 'course', 'target_id' => $this->me, 'created_at' => $this->daysAgo(1)]);

        $this->assertSame([], (new SecurityActivity())->recent($this->me));
    }

    public function testOnlyTheLastNinetyDays(): void
    {
        $this->entry('profile.password_changed', $this->me, $this->me, $this->daysAgo(89, 1430));
        $this->entry('profile.two_factor_on', $this->me, $this->me, $this->daysAgo(90, 5));

        $this->assertSame(['profile.password_changed'], array_column((new SecurityActivity())->recent($this->me), 'action'));
        $this->assertSame([], (new SecurityActivity())->recent($this->me, 1));
    }
}

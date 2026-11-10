<?php

declare(strict_types=1);

use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Admin\PermissionMatrix;
use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Below `sm` the header is one line: logomark, title, bell and a ⋯ button that
 * opens the account panel, whose phone-only block carries Messages, dark mode
 * and Apps. The desktop controls stay in the markup, hidden below `sm`.
 *
 * @internal
 */
final class HeaderPhoneLayoutTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        PermissionMatrix::flush();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renderer');
        PermissionMatrix::flush();

        parent::tearDown();
    }

    private function page(string $path, $user): string
    {
        $this->loggedInAs($user);
        $this->withSession();

        return (string) $this->step('GET', $path)->response()->getBody();
    }

    /** The phone-only block at the top of the account panel. */
    private function phoneBlock(string $body): string
    {
        $this->assertSame(1, preg_match('/<div[^>]*data-profile-phone-block[^>]*>(.*?)<!-- \/phone block -->/s', $body, $m));

        return $m[1];
    }

    /** The opening tag of the first element carrying the given attribute. */
    private function tagWith(string $body, string $attribute): string
    {
        $this->assertSame(1, preg_match('/<[a-z]+\b[^>]*\s' . preg_quote($attribute, '/') . '[\s>=][^>]*>/', $body, $m), $attribute);

        return $m[0];
    }

    public function testAStudentGetsTheOverflowButtonAndThePhoneBlock(): void
    {
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'messages', 'institution_id' => null, 'enabled' => 1]);

        $body = $this->page('student/calendar', CourseFactory::user('student'));

        $overflow = $this->tagWith($body, 'aria-label="More: messages, apps, account"');
        $this->assertStringContainsString('data-profile-menu-trigger', $overflow);
        $this->assertStringContainsString('aria-controls="profile-menu-panel"', $overflow);
        $this->assertStringContainsString('sm:hidden', $overflow);
        $this->assertMatchesRegularExpression('/<span data-messages-dot[^>]*>\s*<span class="sr-only">unread messages<\/span>/', $body);

        $this->assertStringContainsString('max-sm:hidden', $this->tagWith($body, 'aria-label="Messages"'));
        $this->assertStringContainsString('max-sm:hidden', $this->tagWith($body, 'data-theme-toggle'));
        $this->assertStringContainsString('max-sm:hidden', $this->tagWith($body, 'id="app-menu-button"'));
        $this->assertStringContainsString('max-sm:hidden', $this->tagWith($body, 'id="profile-menu-button"'));
        $this->assertStringContainsString('data-profile-menu-trigger', $this->tagWith($body, 'id="profile-menu-button"'));

        $block = $this->phoneBlock($body);
        $this->assertStringContainsString('data-messages-badge', $block);
        $this->assertStringContainsString('data-theme-toggle', $block);
        $this->assertStringContainsString('<details', $block);
    }

    public function testAnInstitutionAdminHasNoAppsInThePhoneBlock(): void
    {
        $institution = PeopleFactory::institution('HPL', 'active', 'Header Phone College');
        $admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@hpl.example', $institution);

        $body  = $this->page('institution/people', $admin);
        $block = $this->phoneBlock($body);

        $this->assertStringContainsString('data-theme-toggle', $block);
        $this->assertStringNotContainsString('<details', $block);
        $this->assertStringNotContainsString('aria-label="More: messages, apps, account"', $body);
        $this->assertStringNotContainsString('aria-label="More: apps, account"', $body);
    }

    public function testWithMessagesOffThereIsNoDotAndNoMessagesRow(): void
    {
        db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->delete();

        $body = $this->page('student/calendar', CourseFactory::user('student'));

        $this->assertStringNotContainsString('data-messages-dot', $body);
        $this->assertStringContainsString('data-profile-menu-trigger', $this->tagWith($body, 'aria-label="More: apps, account"'));
        $this->assertStringNotContainsString('data-messages-badge', $this->phoneBlock($body));
    }

    public function testTheThreeHeaderPanelsGoFixedOnPhones(): void
    {
        $body = $this->page('student/calendar', CourseFactory::user('student'));

        foreach (['notifications-panel', 'app-menu-panel', 'profile-menu-panel'] as $id) {
            $tag = $this->tagWith($body, 'id="' . $id . '"');

            $this->assertStringContainsString('data-header-panel', $tag, $id);
            $this->assertStringContainsString('max-sm:fixed', $tag, $id);
        }
    }
}

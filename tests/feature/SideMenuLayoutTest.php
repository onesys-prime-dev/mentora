<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The side menus in three tiers: the sidebar from lg up, and below lg a ☰ button in
 * the header that opens the same groups as a full-screen tile menu (#nav-menu).
 *
 * @internal
 */
final class SideMenuLayoutTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
    }

    private function signIn(object $user, string $uri): string
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();

        $response = $this->call('GET', $uri)->response();

        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody());
    }

    private function person(string $role, string $uri): string
    {
        return $this->signIn(PeopleFactory::person($role, 'Sam', 'Side', null, PeopleFactory::institution('SIDE')), $uri);
    }

    private function tag(string $html, string $pattern): string
    {
        $this->assertSame(1, preg_match($pattern, $html, $m), "No match for {$pattern}");

        return $m[0];
    }

    /** The phone menu: everything from the dialog's opening tag to the end of the page. */
    private function navMenu(string $html): string
    {
        $start = strpos($html, 'id="nav-menu"');

        $this->assertNotFalse($start, 'no #nav-menu dialog');

        return substr($html, $start);
    }

    private function assertTheThreeTiers(string $html, string $menuAttribute, string $currentHref): void
    {
        $this->assertStringContainsString('aria-label="Open the menu"', $this->tag($html, '/<button[^>]*data-dialog-open="nav-menu"[^>]*>/'));
        $this->assertStringContainsString('lg:hidden', $this->tag($html, '/<button[^>]*data-dialog-open="nav-menu"[^>]*>/'));
        $this->assertStringContainsString('aria-haspopup="dialog" aria-controls="nav-menu"', $this->tag($html, '/<button[^>]*data-dialog-open="nav-menu"[^>]*>/'));
        $this->assertStringContainsString('data-dialog-variant="menu"', $this->tag($html, '/<div id="nav-menu"[^>]*>/'));

        $this->assertMatchesRegularExpression('/<a href="' . preg_quote($currentHref, '/') . '"[^>]*aria-current="page"/', $this->navMenu($html));

        $this->assertStringContainsString('max-lg:hidden', $this->tag($html, '/<nav[^>]*' . $menuAttribute . '[^>]*>/'));
        $this->assertStringNotContainsString('-menu-toggle', $html);
        $this->assertStringNotContainsString('peer-checked', $html);
    }

    public function testTheInstitutionAdminGetsTheRailTheButtonAndTheTileMenu(): void
    {
        $html = $this->person('institutional_admin', '/institution/dashboard');

        $this->assertTheThreeTiers($html, 'data-institution-menu', site_url('institution/dashboard'));
    }

    public function testThePlatformAdminGetsTheRailTheButtonAndTheTileMenu(): void
    {
        $admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $html = $this->signIn($admin, 'admin/institutions');

        $this->assertTheThreeTiers($html, 'data-admin-menu', site_url('admin/institutions'));
    }

    /** Sticky under the header from xl too, and above the page's sticky bars (z-20) but under the header (z-30) on the rail. */
    private function assertTheNavStaysPut(string $html, string $menuAttribute): void
    {
        $nav = $this->tag($html, '/<nav[^>]*' . $menuAttribute . '[^>]*>/');

        $this->assertStringContainsString('xl:sticky xl:top-[var(--app-header-h,4.75rem)] xl:h-[calc(100dvh-var(--app-header-h,4.75rem))]', $nav);
        $this->assertStringContainsString('lg:max-xl:z-[25]', $nav);
        $this->assertStringNotContainsString('z-20', $nav);
    }

    public function testBothNavsStayPutAndTheMenuDialogNamesTheRole(): void
    {
        $institution = $this->person('institutional_admin', '/institution/dashboard');
        $this->assertTheNavStaysPut($institution, 'data-institution-menu');
        $this->assertMatchesRegularExpression('/id="nav-menu-title"[^>]*>Institution admin</', $institution);

        $admin = $this->signIn(model(UserModel::class)->findById(CourseFactory::user('admin')->id), 'admin/institutions');
        $this->assertTheNavStaysPut($admin, 'data-admin-menu');
        $this->assertMatchesRegularExpression('/id="nav-menu-title"[^>]*>System administrator</', $admin);
    }

    public function testThePlatformAdminRailHasAPeekButtonOnlyOnTheRail(): void
    {
        $html = $this->signIn(model(UserModel::class)->findById(CourseFactory::user('admin')->id), 'admin/institutions');

        $this->assertSame(1, preg_match('#<nav[^>]*data-admin-menu.*?</nav>#s', $html, $nav));
        $button = $this->tag($nav[0], '/<button[^>]*data-menu-collapse[^>]*>/');

        $this->assertStringContainsString('xl:hidden', $button);
        $this->assertStringContainsString('aria-pressed=', $button);
    }

    public function testAStudentHasNoMenuButtonAndNoTileMenu(): void
    {
        $html = $this->person('student', '/student/dashboard');

        $this->assertStringNotContainsString('id="nav-menu"', $html);
        $this->assertStringNotContainsString('data-dialog-open="nav-menu"', $html);
    }
}

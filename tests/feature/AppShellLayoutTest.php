<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The app shell's layout rules: it locks to the viewport only when wide and tall,
 * the gutter and the max width come from tokens, and the notch is cleared.
 *
 * @internal
 */
final class AppShellLayoutTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
    }

    private function page(string $role, string $uri): string
    {
        $institution = PeopleFactory::institution('SHELL');
        $user        = PeopleFactory::person($role, 'Sam', 'Shell', null, $institution);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();

        $response = $this->call('GET', $uri)->response();

        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody());
    }

    private function tag(string $html, string $pattern): string
    {
        $this->assertSame(1, preg_match($pattern, $html, $m), "No match for {$pattern}");

        return $m[0];
    }

    public function testTheLockedShellOnlyLocksWhenWideAndTall(): void
    {
        $body = $this->tag($this->page('student', '/student/dashboard'), '/<body[^>]*class="[^"]*"/');

        $this->assertStringContainsString('lock:h-dvh', $body);
        $this->assertStringContainsString('lock:overflow-hidden', $body);
        $this->assertStringNotContainsString('lg:h-dvh', $body);
        $this->assertStringNotContainsString('lg:overflow-hidden', $body);
    }

    public function testMainUsesTheGutterTokenAndShellWidth(): void
    {
        $html = $this->page('student', '/student/dashboard');
        $main = $this->tag($html, '/<main[^>]*>/');

        $this->assertStringContainsString('px-gutter', $main);
        $this->assertMatchesRegularExpression('/<main[^>]*>\s*<div class="mx-auto w-full max-w-shell[^"]*">/', $html);
        $this->assertDoesNotMatchRegularExpression('/xl:px-40[^"]*lg:px-16|lg:px-16[^"]*xl:px-40/', $html);
    }

    public function testTheHeaderClearsTheNotch(): void
    {
        $header = $this->tag($this->page('student', '/student/dashboard'), '/<[^>]*data-app-header[^>]*>/');

        $this->assertStringContainsString('pt-safe', $header);
    }

    public function testTheShellClearsLandscapeNotches(): void
    {
        $html = $this->page('student', '/student/dashboard');

        $this->assertMatchesRegularExpression('/<div class="flex flex-col[^"]*pl-safe pr-safe[^"]*">\s*<div[^>]*data-app-header/', $html);
    }

    public function testTheMenuShellUsesTheSameLockRule(): void
    {
        $html = $this->page('institutional_admin', '/institution/dashboard');

        $this->assertStringContainsString('lock:overflow-hidden', $this->tag($html, '/<body[^>]*class="[^"]*"/'));
        $this->assertStringContainsString('lock:overflow-y-auto', $this->tag($html, '/<main[^>]*>/'));
        $this->assertStringNotContainsString('lg:overflow-hidden', $html);
    }

    public function testTheShellWrapperKeepsTheLockedHeightChain(): void
    {
        $wrapper = $this->tag($this->page('student', '/student/dashboard'), '/<div class="mx-auto w-full max-w-shell[^"]*">/');

        $this->assertStringContainsString('lock:h-full lock:min-h-0', $wrapper);
    }

    public function testAPageLockedOnlyForTheMenuKeepsAnAutoHeightWrapper(): void
    {
        // A page that only locks for the menu keeps an auto-height wrapper, so main's bottom padding survives its overflow.
        $menuPage = $this->tag($this->page('institutional_admin', '/institution/people'), '/<div class="mx-auto w-full max-w-shell[^"]*">/');
        $this->assertStringNotContainsString('lock:h-full', $menuPage);
    }

    public function testTheAdminShellMeasuresItsHeader(): void
    {
        $admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($admin);
        $this->withSession();

        $html = html_entity_decode($this->call('GET', 'admin/institutions')->response()->getBody());

        $this->assertStringContainsString('sticky top-0', $this->tag($html, '/<div[^>]*data-app-header[^>]*>/'));
        $this->assertStringContainsString("setProperty('--app-header-h'", $html);
        $this->assertStringContainsString("'data-scrolled'", $html);
    }
}

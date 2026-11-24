<?php

declare(strict_types=1);

use App\Services\Profiles\AvatarData;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * components/avatar: a photo, or initials on a colour picked from the id. The header and the
 * dashboard profile cards use it; the header's avatar still opens the account menu.
 *
 * @internal
 */
final class AvatarComponentTest extends RouteTestCase
{
    private function avatar(array $person, string $size = 'md', bool $interactive = false): string
    {
        return view('components/avatar', ['person' => $person, 'size' => $size, 'interactive' => $interactive], ['saveData' => false]);
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new DOMXPath($doc);
    }

    public function testInitialsComeFromTheFirstAndLastWordAndSitOnTheStableColour(): void
    {
        $user   = PeopleFactory::person('student', 'Ana', 'Reyes');
        $person = (new AvatarData())->forMany([(int) $user->id])[(int) $user->id];

        $this->assertSame('AR', $person['initials']);

        $html = $this->avatar($person);
        $this->assertStringContainsString('>AR<', $html);
        $this->assertStringContainsString(strtolower($person['colour']), strtolower($html));
        $this->assertContains($person['colour'], AvatarData::COLOURS);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testWithoutPrecomputedInitialsTheFirstAndLastWordOfTheNameAreUsed(): void
    {
        $html = $this->avatar(['id' => 11, 'name' => 'Maria Santos Reyes']);

        $this->assertStringContainsString('>MR<', $html);
        $this->assertStringContainsString(AvatarData::COLOURS[3], $html);
    }

    public function testAltIsPhotoOfNameOnlyWhenThereIsAPhoto(): void
    {
        $person = ['id' => 4, 'name' => 'Ana Reyes', 'initials' => 'AR', 'colour' => AvatarData::COLOURS[4], 'photo_url' => null];

        $this->assertStringNotContainsString('alt=', $this->avatar($person));
        $this->assertStringContainsString('aria-label="Ana Reyes"', $this->avatar($person));

        $with = $this->avatar([...$person, 'photo_url' => '/media/a.jpg']);
        $this->assertStringContainsString('alt="Photo of Ana Reyes"', $with);
        $this->assertStringNotContainsString('alt=""', $with);
    }

    public function testSizeClassesAndInteractiveHooks(): void
    {
        $person = ['id' => 4, 'name' => 'Ana Reyes'];

        $this->assertStringContainsString('h-6 w-6', $this->avatar($person, 'xs'));
        $this->assertStringContainsString('h-18 w-18', $this->avatar($person, 'xl'));
        $this->assertStringNotContainsString('<button', $this->avatar($person));

        $btn = $this->dom($this->avatar($person, 'md', true))->query('//button[@data-avatar-open][@data-user-id="4"]');
        $this->assertSame(1, $btn->length);
    }

    public function testNamesAreEscaped(): void
    {
        $html = $this->avatar(['id' => 1, 'name' => '"><script>x</script>', 'initials' => 'X']);

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testTheHeaderShowsInitialsNotThePlaceholderAndStillOpensTheAccountMenu(): void
    {
        $user = PeopleFactory::person('student', 'Ana', 'Reyes');
        $this->loggedInAs($user);

        $result = $this->get('student/dashboard');
        $result->assertStatus(200);
        $html = $result->response()->getBody();
        $x    = $this->dom($html);

        $this->assertStringNotContainsString('placeholder/profile.png', $html);
        $this->assertGreaterThanOrEqual(1, $x->query('//header//button[@data-profile-menu-trigger]//*[@data-avatar]//*[@role="img"][.="AR"]')->length);
        $this->assertGreaterThanOrEqual(1, $x->query('//header//button[@data-profile-menu-trigger][@aria-haspopup="menu"][@aria-controls="profile-menu-panel"]')->length);
        $this->assertGreaterThan(0, $x->query('//*[@id="profile-menu-panel"]//form[contains(@action,"logout")]//button')->length);
    }

    public function testTheDashboardProfileCardsRenderTheAvatar(): void
    {
        foreach (['student', 'instructor', 'chairperson'] as $role) {
            $this->loggedInAs(PeopleFactory::person($role, 'Ana', 'Reyes'));

            $x = $this->dom($this->get($role . '/dashboard')->response()->getBody());

            $this->assertGreaterThan(0, $x->query('//main//*[@data-avatar]//*[@role="img"][.="AR"]')->length, $role);
        }
    }
}

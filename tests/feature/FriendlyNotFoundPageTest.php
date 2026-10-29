<?php

declare(strict_types=1);

use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The page for an address that does not exist or is not the person's: friendly, the same for both, with a
 * way back for whoever is signed in (or the way in for whoever is not), and nothing about the failure
 * outside development. The error handler includes the template with the reason in `$message`, so that is
 * how it is drawn here.
 *
 * @internal
 */
final class FriendlyNotFoundPageTest extends RouteTestCase
{
    private function render(string $message = "Can't find a route for 'GET: http://example.com/index.php/nope'."): string
    {
        ob_start();
        include APPPATH . 'Views/errors/html/error_404.php';

        return (string) ob_get_clean();
    }

    public function testItIsAFriendlyPageInTheAppsOwnWords(): void
    {
        $body = html_entity_decode($this->render());

        $this->assertStringContainsString('<title>Page not found</title>', $body);
        $this->assertStringContainsString('Error 404', $body);
        $this->assertStringContainsString('We couldn\'t find that page', $body);
        $this->assertStringContainsString('mistyped', $body);
        $this->assertStringContainsString('tell your administrator', $body);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
        $this->assertStringNotContainsString('CodeIgniter', $body, 'not the framework\'s stock page');
    }

    public function testItIsAStandalonePageThatDoesNotDependOnTheLayoutOrTheBuild(): void
    {
        $body = $this->render();

        $this->assertStringContainsString('<html lang="en">', $body);
        $this->assertStringContainsString('<main', $body);
        $this->assertStringContainsString('<h1>', $body);
        $this->assertStringNotContainsString('<script', $body, 'no script to fail while an error is being handled');
        $this->assertStringNotContainsString('/build/', $body, 'no built assets');
    }

    public function testAVisitorWhoIsNotSignedInIsOfferedSignIn(): void
    {
        $body = html_entity_decode($this->render());

        $this->assertStringContainsString('>Sign in</a>', $body);
        $this->assertStringContainsString('login', $body);
        $this->assertStringNotContainsString('Go to my dashboard', $body);
    }

    public function testEverySignedInRoleIsOfferedItsDashboardThroughTheSiteRoot(): void
    {
        $home = DeanFactory::department('N');

        foreach ([
            PeopleFactory::person('student', 'Sam', 'Student', null, $home['institution']),
            PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $home['institution']),
            DeanFactory::dean($home['institution'], $home['department']),
        ] as $user) {
            $this->loggedInAs($user);

            $body = html_entity_decode($this->render());

            $this->assertStringContainsString('>Go to my dashboard</a>', $body);
            $this->assertStringNotContainsString('>Sign in</a>', $body);
            $this->assertMatchesRegularExpression('#class="button" href="[^"]*index\.php/?"#', $body, 'the site root, which sends each role home');

            auth()->logout();   // the next person signs in on a clean session
        }
    }

    public function testAnAddressThatDoesNotExistAndOneThatIsNotYoursLookTheSame(): void
    {
        $strip = static fn (string $html): string => (string) preg_replace('#<p class="debug".*?</p>#s', '', $html);

        $missing = $strip($this->render("Can't find a route for 'GET: http://example.com/index.php/dean/courses/999999/x'."));
        $foreign = $strip($this->render('Page Not Found'));

        $this->assertSame($missing, $foreign, 'apart from the developer line, the page is identical, so it tells nobody which it was');
    }

    public function testTheReasonIsShownToDevelopersHereAndEscaped(): void
    {
        $body = $this->render('<script>alert(1)</script> route');

        $this->assertStringContainsString('data-debug', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testNoDeveloperLineWhenThereIsNoReason(): void
    {
        $this->assertStringNotContainsString('data-debug', $this->render(''));
    }
}

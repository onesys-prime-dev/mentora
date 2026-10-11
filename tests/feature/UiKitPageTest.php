<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The component gallery page and the layout fixes that came with the kit:
 * a signed-in person sees every component, the dialog form answers the way
 * shared/dialog.js expects, the header's bell goes to the notifications and
 * the page title is a words, not a key.
 *
 * @internal
 */
final class UiKitPageTest extends RouteTestCase
{
    private function signIn(string $role = 'instructor'): void
    {
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();
    }

    public function testASignedOutVisitorIsSentToTheLoginPage(): void
    {
        $response = $this->call('GET', 'ui-kit')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
    }

    public function testEveryComponentIsOnTheGallery(): void
    {
        $this->signIn();

        $body = $this->step('GET', 'ui-kit')->response()->getBody();

        foreach (['buttons', 'alerts', 'status', 'list', 'states', 'navigation', 'dialogs'] as $section) {
            $this->assertStringContainsString('data-kit="' . $section . '"', $body, "{$section} is missing");
        }

        foreach (['data-table', 'data-toolbar', 'data-dialog', 'data-tabs', 'data-stepper', 'data-skeleton', 'data-lock="sis"', 'data-usage="Nearly full"', 'data-alert="danger"'] as $marker) {
            $this->assertStringContainsString($marker, $body, "{$marker} is missing");
        }
    }

    public function testTheGalleryTitleIsAWordNotAKey(): void
    {
        $this->signIn();

        $body = $this->step('GET', 'ui-kit')->response()->getBody();

        $this->assertStringContainsString('Component gallery', $body);
        $this->assertStringNotContainsString('>ui-kit<', preg_replace('/\s+/', '', $body) ?? '');
    }

    public function testTheHeaderBellIsALinkToTheNotifications(): void
    {
        $this->signIn();

        $body = $this->step('GET', 'ui-kit')->response()->getBody();

        $this->assertMatchesRegularExpression('/<a\s+href="[^"]*notifications"\s+aria-label="Notifications"/', $body);
    }

    public function testThePageDoesNotShutThePhoneOutWithTheDesktopGutter(): void
    {
        $this->signIn();

        $body = $this->step('GET', 'ui-kit')->response()->getBody();

        $this->assertStringContainsString('px-4 sm:px-8 lg:px-16 xl:px-40', $body);

        // And it is what the layout uses when a page says nothing.
        $this->assertStringContainsString("\$mainGutter ?? 'px-4 sm:px-8 lg:px-16 xl:px-40'", (string) file_get_contents(APPPATH . 'Views/layouts/app.php'));
    }

    public function testASortLinkIsMarkedAsTheCurrentSort(): void
    {
        $this->signIn();

        $body = $this->step('GET', 'ui-kit?sort=role&dir=desc')->response()->getBody();

        $this->assertStringContainsString('aria-sort="descending"', $body);
    }

    public function testTheDialogFormRefusesAnEmptyNameInTheShapeTheScriptReads(): void
    {
        $this->signIn();

        $response = $this->step('POST', 'ui-kit', ['name' => '  '])->response();

        $this->assertSame(422, $response->getStatusCode());
        $json = json_decode($response->getBody(), true);
        $this->assertSame('Enter a name.', $json['errors']['name']);
        $this->assertNotEmpty($json['message']);
    }

    public function testTheDialogFormAcceptsANameWithAMessageAndARedirect(): void
    {
        $this->signIn();

        $response = $this->step('POST', 'ui-kit', ['name' => 'Ann Lee'])->response();

        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getBody(), true);
        $this->assertSame('Ann Lee was saved.', $json['message']);
        $this->assertStringContainsString('ui-kit', $json['redirect']);
    }

    public function testRedirectMessagesBecomeToastsOnPagesThatAskForThem(): void
    {
        $this->signIn();
        $this->withSession(['message' => 'Ann Lee was invited.', 'error' => 'Something failed.']);

        $body = $this->call('GET', 'ui-kit')->response()->getBody();

        $this->assertStringContainsString('data-toast-message data-tone="success"', $body);
        $this->assertStringContainsString('Ann Lee was invited.', $body);
        $this->assertStringContainsString('data-toast-message data-tone="danger"', $body);
    }
}

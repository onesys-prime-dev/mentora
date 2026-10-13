<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The header's appearance button works on the app and administration shells:
 * it is a real, labelled button, and the page carries what the script needs
 * (the themeable marker and both background images).
 *
 * @internal
 */
final class DarkModeToggleTest extends RouteTestCase
{
    public function testTheAppShellOffersTheToggleAndBothBackgrounds(): void
    {
        $this->loggedInAs(CourseFactory::user('student'));
        $this->withSession();

        $body = $this->step('GET', 'student/calendar')->response()->getBody();

        $this->assertStringContainsString('<html lang="en" data-themeable>', $body);
        $this->assertStringContainsString('data-theme-toggle', $body);
        $this->assertStringContainsString('Switch to dark mode', $body);
        $this->assertStringNotContainsString('Toggle dark mode (coming soon)', $body);
        $this->assertStringContainsString('--page-bg-dark', $body);
        $this->assertStringContainsString('assets/images/background/dark.png', $body);
    }

    public function testTheAdministrationShellOffersItToo(): void
    {
        $this->loggedInAs(CourseFactory::user('admin'));
        $this->withSession();

        $body = $this->step('GET', 'admin/dashboard')->response()->getBody();

        $this->assertStringContainsString('data-themeable', $body);
        $this->assertStringContainsString('data-theme-toggle', $body);
    }

    public function testTheHeadAppliesTheStoredChoiceBeforePaintingOnlyOnThemeablePages(): void
    {
        $this->loggedInAs(CourseFactory::user('student'));
        $this->withSession();

        $body = $this->step('GET', 'student/calendar')->response()->getBody();

        $this->assertStringContainsString("localStorage.getItem('mentora-theme') === 'dark'", $body);
        $this->assertStringContainsString("root.hasAttribute('data-themeable')", $body);
    }
}

<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The header names the page beside the logo only when the page does not
 * name itself: a page with its own <h1> says where you are once.
 *
 * @internal
 */
final class HeaderTitleTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
    }

    protected function tearDown(): void
    {
        // View data is kept between renders in one process; a page that sets no title would inherit the last one.
        Services::resetSingle('renderer');

        parent::tearDown();
    }

    public function testAPageWithItsOwnHeadingDoesNotRepeatItInTheHeader(): void
    {
        $institution = PeopleFactory::institution('HDR', 'active', 'Header College');
        $admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@hdr.example', $institution);

        $this->loggedInAs($admin);
        $this->withSession();

        $body = $this->step('GET', 'institution/people')->response()->getBody();

        $this->assertStringContainsString('<h1', $body);
        $this->assertStringNotContainsString('data-header-title', $body);
    }

    public function testAPageWithoutAHeadingIsNamedInTheHeader(): void
    {
        Services::resetSingle('renderer');

        $student = CourseFactory::user('student');

        $this->loggedInAs($student);
        $this->withSession();

        $body = $this->step('GET', 'student/calendar')->response()->getBody();

        $this->assertStringNotContainsString('<h1', $body);
        $this->assertMatchesRegularExpression('/data-header-title>\s*Calendar\s*</', $body);
    }

    public function testTheAdministrationHeaderKeepsTheSystemName(): void
    {
        $admin = CourseFactory::user('admin');

        $this->loggedInAs($admin);
        $this->withSession();

        // The admin shell names the platform, not the page, and its pages carry their own heading.
        $body = $this->step('GET', 'admin/dashboard')->response()->getBody();

        $this->assertStringContainsString('administration', $body);
    }
}

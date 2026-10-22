<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * What the error pages decide for themselves: the technical detail is never shown in production, and the
 * main button always has somewhere to go, even when who is signed in cannot be told.
 *
 * @internal
 */
final class ErrorPageHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('error_page');
    }

    public function testNothingAboutAFailureIsShownInProduction(): void
    {
        $this->assertNull(error_page_detail("Can't find a route for 'GET: /dean/secret/12'.", 'production'));
        $this->assertNull(error_page_detail('App\\Controllers\\Secret::index', 'production'));
    }

    public function testADeveloperSeesTheReasonOutsideProduction(): void
    {
        $this->assertSame("Can't find a route for 'GET: /x'.", error_page_detail("Can't find a route for 'GET: /x'.", 'development'));
        $this->assertSame('Reason', error_page_detail('  Reason  ', 'testing'), 'trimmed');
    }

    public function testThereIsNothingToShowWhenThereIsNoMessage(): void
    {
        $this->assertNull(error_page_detail('', 'development'));
        $this->assertNull(error_page_detail("  \n ", 'development'));
    }

    public function testTheMainButtonAlwaysLeadsSomewhere(): void
    {
        $step = error_page_next_step();

        $this->assertContains($step['label'], ['Go to my dashboard', 'Sign in']);
        $this->assertStringStartsWith('http', $step['url']);
    }

    public function testAVisitorWhoIsNotSignedInIsSentToSignIn(): void
    {
        $step = error_page_next_step();

        $this->assertSame('Sign in', $step['label']);
        $this->assertStringEndsWith('login', $step['url']);
    }
}

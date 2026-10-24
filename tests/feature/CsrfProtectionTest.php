<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filters\CsrfFilter;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Every request that changes something needs the page's CSRF token (audit finding 4).
 *
 * @internal
 */
final class CsrfProtectionTest extends RouteTestCase
{
    protected function tearDown(): void
    {
        config('Security')->redirect = false;

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withRoutes([
            ['post', 'csrf-probe', static fn () => 'ok'],
            ['post', 'csrf-probe/body', static fn () => service('response')->setJSON(['body' => service('request')->getBody()])],
        ]);

        $this->loggedInAs(PeopleFactory::person('student'));
    }

    public function testAFormPostWithoutATokenIsSentBackWithTheMessageAndTheTypedInput(): void
    {
        $result = $this->withoutCsrfToken()->post('csrf-probe', ['title' => 'My draft']);

        $result->assertRedirect();
        $this->assertSame(CsrfFilter::MESSAGE, session('error'));
        $this->assertSame('My draft', session('_ci_old_input')['post']['title'] ?? null);
    }

    public function testAScriptPostWithoutATokenGetsA403WithTheMessage(): void
    {
        $result = $this->withoutCsrfToken()
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('csrf-probe');

        $result->assertStatus(403);
        $this->assertSame(['error' => 'csrf_expired', 'message' => CsrfFilter::MESSAGE], json_decode($result->getJSON(), true));
    }

    public function testInProductionAFormPostIsStillSentBackWithTheMessageAndTheTypedInput(): void
    {
        config('Security')->redirect = true; // what production runs with

        $this->withoutCsrfToken()->post('csrf-probe', ['title' => 'My draft'])->assertRedirect();

        $this->assertSame(CsrfFilter::MESSAGE, session('error'));
        $this->assertSame('My draft', session('_ci_old_input')['post']['title'] ?? null);
    }

    public function testInProductionAScriptThatOnlyAsksForJsonGetsJson(): void
    {
        config('Security')->redirect = true;

        $result = $this->withoutCsrfToken()->withHeaders(['Accept' => 'application/json'])->post('csrf-probe');

        $result->assertStatus(403);
        $this->assertSame('csrf_expired', json_decode($result->getJSON(), true)['error'] ?? null);
    }

    public function testSomeoneWhoseSessionEndedIsSentToSignInWithTheMessage(): void
    {
        auth()->logout();

        $result = $this->withoutCsrfToken()
            ->withHeaders(['Referer' => site_url('student/courses')])
            ->post('csrf-probe', ['title' => 'My draft']);

        $result->assertRedirectTo(site_url('login'));
        $this->assertSame(CsrfFilter::MESSAGE, session('error'));
    }

    public function testASignedOutFormIsSentBackToItself(): void
    {
        auth()->logout();

        $result = $this->withoutCsrfToken()
            ->withHeaders(['Referer' => site_url('reset-password/abc')])
            ->post('reset-password/abc', ['password' => 'x']);

        $this->assertStringEndsWith('reset-password/abc', $result->response()->getHeaderLine('Location'));
        $this->assertSame(CsrfFilter::MESSAGE, session('error'));
    }

    public function testAPostWithTheTokenGoesThrough(): void
    {
        $result = $this->post('csrf-probe');

        $result->assertOK();
        $result->assertSee('ok');
    }

    public function testTheSameTokenWorksForASecondRequest(): void
    {
        $this->step('post', 'csrf-probe')->assertOK();
        $this->step('post', 'csrf-probe')->assertOK();
    }

    public function testAJsonBodyThatIsNotAnObjectArrivesUnchanged(): void
    {
        foreach (['[]', '[1,2]', '"text"'] as $body) {
            $result = $this->withBody($body)->withHeaders(['Content-Type' => 'application/json'])->post('csrf-probe/body');

            $this->assertSame($body, json_decode((string) $result->getJSON(), true)['body'] ?? null, "body {$body}");
        }
    }

    public function testCsrfIsOnForEveryRequestExceptTheWorker(): void
    {
        $before = config(\Config\Filters::class)->globals['before'];

        $this->assertSame(['except' => ['internal/ai/*']], $before['csrf'] ?? null);
    }

    public function testSigningInGivesANewToken(): void
    {
        auth()->logout();
        $before = service('security')->getHash();

        auth()->login(PeopleFactory::person('student'));

        $this->assertNotSame($before, service('security')->getHash());
    }

    public function testAPlainLinkCannotSignAnyoneOut(): void
    {
        try {
            $this->get('logout');
            $this->fail('GET logout should not exist');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            // There is no GET route: the address answers "not found".
        }

        $this->assertTrue(auth()->loggedIn());
    }

    public function testTheSignOutButtonSignsOut(): void
    {
        $this->post('logout')->assertRedirectTo(site_url('login'));

        $this->assertFalse(auth()->loggedIn());
    }

    public function testTheMenuSignsOutWithAFormNotALink(): void
    {
        $html = (string) $this->get('student/dashboard')->getBody();

        $this->assertMatchesRegularExpression('#<form[^>]+action="[^"]*/logout"[^>]*method="post"#i', $html);
        $this->assertStringNotContainsString('href="' . site_url('/logout') . '"', $html);
    }

    public function testThePageCarriesTheTokenForScripts(): void
    {
        $html = (string) $this->get('student/dashboard')->getBody();

        $this->assertMatchesRegularExpression('#<meta name="csrf-token" content="[0-9a-f]{32}">#', $html);
    }

    public function testTheWorkerInterfaceIsNotAskedForAToken(): void
    {
        $result = $this->withoutCsrfToken()->withHeaders(['Accept' => 'application/json'])->post('internal/ai/claim');

        // The worker's own guard may refuse it (no secret, not loopback), but never the CSRF check.
        $this->assertStringNotContainsString('csrf_expired', (string) $result->getBody());
    }
}

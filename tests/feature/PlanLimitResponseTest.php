<?php

declare(strict_types=1);

use App\Exceptions\PlanLimitExceeded;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * What a person gets when a plan limit stops an upload: the code that
 * uploads needs no handling of its own, because the exception answers the
 * request itself. A script gets a 403 with a JSON error; a browser is sent
 * back with the message.
 *
 * @internal
 */
final class PlanLimitResponseTest extends RouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withRoutes([
            ['get', 'probe/limit', static fn () => throw new PlanLimitExceeded('Your institution has used its storage allowance.', 'storage')],
            ['post', 'probe/limit', static fn () => throw new PlanLimitExceeded('Your institution has used its storage allowance.', 'storage')],
        ]);

        $this->loggedInAs(CourseFactory::user('instructor'));
        $this->withSession();
    }

    public function testAScriptGetsA403WithAJsonError(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $response = $this->step('POST', 'probe/limit')->response();

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame('plan_limit_exceeded', $body['error']);
        $this->assertSame('storage', $body['limit']);
        $this->assertStringContainsString('storage allowance', $body['message']);
    }

    public function testAnAjaxRequestIsTreatedAsAScript(): void
    {
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(403, $this->step('POST', 'probe/limit')->response()->getStatusCode());
    }

    public function testABrowserIsSentBackWithTheMessage(): void
    {
        $response = $this->step('POST', 'probe/limit')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('storage allowance', (string) session('error'));
    }

    public function testTheExceptionKnowsWhichLimitItIs(): void
    {
        $this->assertSame('storage', (new PlanLimitExceeded('x', 'storage'))->limit());
        $this->assertSame('plan', (new PlanLimitExceeded('x'))->limit());
    }
}

<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

final class RoleSwitchTest extends RouteTestCase
{
    public function testAUserCanSwitchToARoleTheyHold(): void
    {
        $user = CourseFactory::user('chairperson');
        $user->addGroup('instructor');
        $this->loggedInAs($user);

        $response = $this->post('role/switch', ['role' => 'instructor'])->response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(str_ends_with(json_decode((string) $response->getJSON(), true)['redirect'], '/instructor/dashboard'));
        $this->assertSame('instructor', session('active_role'));
    }

    public function testAUserCannotSwitchToARoleTheyDoNotHold(): void
    {
        $this->loggedInAs(CourseFactory::user('student'));

        $response = $this->post('role/switch', ['role' => 'instructor'])->response();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(session('active_role'));
    }

    public function testLoggedOutUsersCannotSwitchRoles(): void
    {
        $response = $this->post('role/switch', ['role' => 'instructor'])->response();

        $this->assertSame(401, $response->getStatusCode());
    }
}

<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * current_user_profile(): the header's account-menu data, looked up once per
 * request however many times the layout asks.
 *
 * @internal
 */
final class CurrentUserProfileHelperTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        helper('navigation');
    }

    public function testTheProfileCarriesNameEmailAndRole(): void
    {
        $user = CourseFactory::user('student');
        $db = db_connect();
        $db->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => 'Sam', 'last_name' => 'Student', 'student_number' => '2026-001']);
        $db->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => 'sam@example.test', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        $profile = current_user_profile($user);

        $this->assertSame('Sam', $profile['first_name']);
        $this->assertSame('Student', $profile['last_name']);
        $this->assertSame('2026-001', $profile['student_number']);
        $this->assertSame('sam@example.test', $profile['email']);
        $this->assertSame('student', $profile['role']);
    }

    public function testItIsLookedUpOncePerUser(): void
    {
        $user = CourseFactory::user('student');
        $other = CourseFactory::user('student');
        $db = db_connect();
        foreach ([[$user, 'Una'], [$other, 'Otto']] as [$u, $name]) {
            $db->table('user_profiles')->insert(['user_id' => $u->id, 'first_name' => $name, 'last_name' => 'X']);
            $db->table('auth_identities')->insert(['user_id' => $u->id, 'type' => 'email_password', 'secret' => "{$name}@example.test", 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->assertSame('Una', current_user_profile($user)['first_name']);

        $db->table('user_profiles')->where('user_id', $user->id)->update(['first_name' => 'Changed']);

        $this->assertSame('Una', current_user_profile($user)['first_name'], 'served from the per-request cache');
        $this->assertSame('Otto', current_user_profile($other)['first_name'], 'each user has their own entry');
    }

    public function testSomeoneWithoutAProfileGetsAnEmptyArray(): void
    {
        $this->assertSame([], current_user_profile(CourseFactory::user('student')));
    }
}

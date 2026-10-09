<?php

declare(strict_types=1);

use App\Models\Users\UserProfileModel;
use App\Services\Auth\RoleAuthorization;
use App\Services\Auth\ActiveRoleService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

final class RoleAuthorizationTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    public function testOnlyTheActiveRoleIsAllowed(): void
    {
        $user = $this->dualRoleUser();
        $service = new ActiveRoleService();
        $service->resolve($user);
        $authorization = new RoleAuthorization($service);

        $this->assertTrue($authorization->allows($user, 'chairperson'));
        $this->assertFalse($authorization->allows($user, 'instructor'));

        $service->switchTo($user, 'instructor');

        $this->assertFalse($authorization->allows($user, 'chairperson'));
        $this->assertTrue($authorization->allows($user, 'instructor'));
    }

    public function testAUserCannotActAsARoleTheyDoNotHold(): void
    {
        $user = $this->withProfile(CourseFactory::user('student'));

        $this->assertFalse((new RoleAuthorization())->allows($user, 'instructor'));
    }

    private function dualRoleUser(): User
    {
        $user = $this->withProfile(CourseFactory::user('chairperson'));
        $user->addGroup('instructor');

        return $user;
    }

    private function withProfile(User $user): User
    {
        (new UserProfileModel())->insert([
            'user_id' => $user->id,
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        return $user;
    }
}

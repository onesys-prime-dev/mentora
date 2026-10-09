<?php

declare(strict_types=1);

namespace App\Services\Auth;

use CodeIgniter\Shield\Entities\User;

final class RoleAuthorization
{
    public function __construct(private readonly ActiveRoleService $activeRoleService = new ActiveRoleService())
    {
    }

    public function allows(User $user, string $role): bool
    {
        return $this->activeRoleService->resolve($user) === $role
            && in_array($role, $user->getGroups() ?? [], true);
    }
}

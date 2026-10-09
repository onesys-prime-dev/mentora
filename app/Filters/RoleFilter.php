<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\RoleAuthorization;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to('/login');
        }

        $user = auth()->user();
        $roles = is_array($arguments) ? $arguments : [];
        $authorization = new RoleAuthorization();

        foreach ($roles as $role) {
            if (is_string($role) && $authorization->allows($user, $role)) {
                return null;
            }
        }

        return redirect()->to('/');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

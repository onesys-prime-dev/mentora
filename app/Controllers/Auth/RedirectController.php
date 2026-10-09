<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Services\Auth\ActiveRoleService;

class RedirectController extends BaseController
{
    // No route group exists yet for 'chairperson' — that lands with the
    // dashboard branch of the chairperson plan. Until then a chairperson's
    // active role correctly resolves, but there's nowhere to send them.
    protected array $dashboards = [
        'admin'      => '/admin/dashboard',
        'student'    => '/student/dashboard',
        'instructor' => '/instructor/dashboard',
        'registrar'  => '/registrar/dashboard',
        'parent'     => '/parent/dashboard',
    ];

    public function index()
    {
        if (! auth()->loggedIn()) {
            return redirect()->to('/login');
        }

        $user = auth()->user();

        $role = (new ActiveRoleService())->resolve($user);

        if ($role === '' || ! isset($this->dashboards[$role])) {

            // Signed in, but in no usable group (abort() is not a CodeIgniter function).
            return $this->response
                ->setStatusCode(403)
                ->setBody('Your account does not have a role that can use this site yet.');

        }

        return redirect()->to($this->dashboards[$role]);
    }
}

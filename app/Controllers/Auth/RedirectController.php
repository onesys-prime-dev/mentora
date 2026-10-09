<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Services\Auth\ActiveRoleService;

class RedirectController extends BaseController
{
    protected array $dashboards = [
        'admin'       => '/admin/dashboard',
        'student'     => '/student/dashboard',
        'instructor'  => '/instructor/dashboard',
        'chairperson' => '/chairperson/dashboard',
        'registrar'   => '/registrar/dashboard',
        'parent'      => '/parent/dashboard',
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

    public function switchRole()
    {
        if (! auth()->loggedIn()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Authentication required.']);
        }

        $role = $this->request->getPost('role');

        if (! is_string($role) || ! (new ActiveRoleService())->switchTo(auth()->user(), $role)) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON(['error' => 'You cannot switch to that role.']);
        }

        $redirect = $this->dashboards[$role] ?? '/';

        return $this->response->setJSON(['redirect' => site_url($redirect)]);
    }
}

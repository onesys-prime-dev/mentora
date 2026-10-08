<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

class RedirectController extends BaseController
{
    public function index()
    {
        if (! auth()->loggedIn()) {
            return redirect()->to('/login');
        }

        $user = auth()->user();

        $routes = [

            'admin' => '/admin/dashboard',

            'student' => '/student/dashboard',

            'instructor' => '/instructor/dashboard',

            'registrar' => '/registrar/dashboard',

            'parent' => '/parent/dashboard',

        ];

        foreach ($routes as $group => $route) {

            if ($user->inGroup($group)) {

                return redirect()->to($route);

            }

        }

        // Signed in, but in none of the groups above (abort() is not a CodeIgniter function).
        return $this->response
            ->setStatusCode(403)
            ->setBody('Your account does not have a role that can use this site yet.');
    }
}

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

        abort(403);
    }
}

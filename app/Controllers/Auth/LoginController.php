<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

class LoginController extends BaseController
{
    public function index(): string
    {
        return view('pages/auth/login', [
            'title' => 'Login',
            'page' => 'login',
        ]);
    }
}

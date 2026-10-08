<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Landing page of the system administrator area. Widgets arrive with the
 * admin home work (SA14); for now it is the empty shell the rest hangs from.
 */
class HomeController extends BaseController
{
    public function index(): string
    {
        return view('pages/admin/home', [
            'title' => 'System Administration',
            'page'  => 'admin',
            'user'  => auth()->user(),
        ]);
    }
}

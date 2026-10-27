<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use DateTimeImmutable;

/**
 * Landing page of the system administrator area (SA14): what needs
 * attention, a snapshot, recent activity and the shortcuts. The numbers come
 * from AdminHome, which reuses what the other admin pages work out.
 */
class HomeController extends BaseController
{
    public function index(): string
    {
        $now  = new DateTimeImmutable();
        $home = service('adminHome');

        return view('pages/admin/home', [
            'title'         => 'System Administration',
            'page'          => 'admin',
            'user'          => auth()->user(),
            'homeAttention' => $home->needsAttention($now),
            'homeHealth'    => $home->health(),
            'homeSnapshot'  => $home->snapshot($now),
            'homeActivity'  => $home->recentActivity(),
        ]);
    }
}

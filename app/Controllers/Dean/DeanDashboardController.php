<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;

/**
 * The dean's landing page. A dean with no active assignment gets an
 * explanation, not an error and not any data; the real dashboard is built
 * on this page in the dean-dashboard branch.
 */
class DeanDashboardController extends BaseController
{
    public function index(): string
    {
        $department = (new DeanAccessService())->departmentFor(auth()->user());

        if ($department === null) {
            return view('pages/dean/no-assignment', [
                'title' => 'Dashboard',
                'page'  => 'dashboard',
                'user'  => auth()->user(),
            ]);
        }

        return view('pages/dean/dashboard', [
            'title'      => 'Dashboard',
            'page'       => 'dashboard',
            'user'       => auth()->user(),
            'department' => db_connect()->table('departments')->select('id, name')->where('id', (int) $department['department_id'])->get()->getRowArray() ?? ['name' => 'Your department'],
        ]);
    }
}

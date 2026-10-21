<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Models\Users\UserProfileModel;
use App\Services\Courses\DeanDashboardService;

/**
 * The dean's home page: the department in numbers, what needs their attention (each item opening the
 * screen where it is dealt with), what chairpersons have been changing and the announcements they made.
 * A dean with no active assignment gets an explanation, not an error and not any data. If the numbers
 * cannot be worked out the page says so and offers to try again, rather than showing nothing.
 */
class DeanDashboardController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        $loadError = false;
        $overview  = null;

        try {
            $overview = (new DeanDashboardService())->overview($user);
        } catch (\Throwable $e) {
            log_message('error', 'Dean dashboard failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        if ($overview === null && ! $loadError) {
            return view('pages/dean/no-assignment', ['title' => 'Dashboard', 'page' => 'dashboard', 'user' => $user]);
        }

        return view('pages/dean/dashboard', [
            'title'     => 'Dashboard',
            'page'      => 'dashboard',
            'user'      => $user,
            'overview'  => $overview,
            'profile'   => (new UserProfileModel())->retrieveUserProfile($user) ?? [],
            'loadError' => $loadError,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Students;

use App\Controllers\BaseController;
use App\Services\Parents\ParentAccessService;

/**
 * The student's own profile, to read: their name, email and student number,
 * and the parents who can see their progress (names only). Nothing on it can
 * be changed here.
 */
class StudentProfileController extends BaseController
{
    public function index(): string
    {
        $user    = auth()->user();
        $profile = db_connect()->table('user_profiles')->select('first_name, last_name, student_number')->where('user_id', (int) $user->id)->get()->getRowArray() ?? [];

        return view('pages/student/profile', [
            'title'         => 'Profile',
            'page'          => 'profile',
            'user'          => $user,
            'accountName'   => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: (string) $user->username,
            'accountEmail'  => (string) $user->email,
            'studentNumber' => trim((string) ($profile['student_number'] ?? '')),
            'parentNames'   => (new ParentAccessService())->parentNamesWithAccess((int) $user->id),
        ]);
    }
}

<?php

namespace App\Services\Dashboard;
use CodeIgniter\Shield\Entities\User;

class DashboardService {

    public function getDashboardData(User $user): array
    {
        $widgets = [];

        if ($user->inGroup('student')) {

            $widgets[] = 'attendance';
            $widgets[] = 'grades';
            $widgets[] = 'courses';

        }

        if ($user->inGroup('instructor')) {

            $widgets[] = 'teaching-load';
            $widgets[] = 'grading-queue';

        }

        if ($user->inGroup('registrar')) {

            $widgets[] = 'enrollment-queue';

        }

        return [

            'user' => $user,

            'widgets' => $widgets

        ];
    }

}
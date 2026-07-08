<?php

namespace App\Services\Dashboard;

use CodeIgniter\Shield\Entities\User;

class DashboardService
{
    public function getDashboardData(User $user): array
    {
        $widgets = [];

        if ($user->inGroup('student')) {
            $widgets = array_merge($widgets, [
                'attendance',
                'grades',
                'courses',
            ]);
        }

        if ($user->inGroup('instructor')) {
            $widgets = array_merge($widgets, [
                'teaching-load',
                'grading-queue',
            ]);
        }

        return [

            'widgets' => $widgets,

        ];
    }
}
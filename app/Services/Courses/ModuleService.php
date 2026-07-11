<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;

class ModuleService
{
    public function show(
        User $user,
        int $offeringId
    ): array {

        return [

            'html' => view(
                'pages/courses/features/modules/index'
            ),

        ];

    }
}
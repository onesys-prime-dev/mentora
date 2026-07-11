<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;

class GradeService
{
    public function show(
        User $user,
        int $offeringId
    ): array {

        return [

            'html' => view(
                'pages/courses/features/grade/index'
            ),

        ];

    }
}
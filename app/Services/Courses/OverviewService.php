<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Courses\CourseOfferingModel;

use CodeIgniter\Exceptions\PageNotFoundException;

class OverviewService
{
    protected CourseOfferingModel $courseOfferingModel;

    public function __construct()
    {
        $this->courseOfferingModel = new CourseOfferingModel();
    }

    public function show(
        User $user,
        int $offeringId
    ): array {

        $overview = $this->courseOfferingModel->getCourseOverviewData($offeringId);

        return [

            'html' => view(
                'pages/courses/features/overview/index',
                [
                    'overview' => $overview,
                ]
            ),

        ];

    }
}
<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Courses\EnrollmentModel;
use App\Models\Courses\InstructorAssignmentModel;

use CodeIgniter\Exceptions\PageNotFoundException;

class CourseService
{
    protected EnrollmentModel $enrollmentModel;

    //protected InstructorAssignmentModel $instructorAssignmentModel;

    public function __construct()
    {
        $this->enrollmentModel = new EnrollmentModel();

        //$this->instructorAssignmentModel = new InstructorAssignmentModel();
    }

    /*
    |--------------------------------------------------------------------------
    | Course List
    |--------------------------------------------------------------------------
    */

    public function getCourseOfferings(User $user): array
    {
        if ($user->inGroup('student')) {

            return $this->enrollmentModel
                ->retrieveStudentCourseOfferings($user->id);

        }

        if ($user->inGroup('instructor')) {

            /*return $this->instructorAssignmentModel
                ->retrieveInstructorCourseOfferings($user->id);
            */
        }

        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Single Course
    |--------------------------------------------------------------------------
    */

    public function getCourseOffering(
        User $user,
        int $offeringId
    ): ?array
    {
        if ($user->inGroup('student')) {

            return $this->enrollmentModel
                ->retrieveStudentCourseOffering(
                    $user->id,
                    $offeringId
                );

        }

        if ($user->inGroup('instructor')) {
/*
            return $this->instructorAssignmentModel
                ->retrieveInstructorCourseOffering(
                    $user->id,
                    $offeringId
                );
*/
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Course Feature
    |--------------------------------------------------------------------------
    */

    public function renderCourseTab(
        User $user,
        int $offeringId,
        string $tab
    ): array
    {
        $course = $this->getCourseOffering(
            $user,
            $offeringId
        );

        if (! $course) {
            throw PageNotFoundException::forPageNotFound();
        }

        $view = $this->resolveTabView($tab);

        return [

            'tab' => $tab,

            'html' => view($view, [

                'user' => $user,

                'course' => $course,

            ]),

        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function resolveTabView(string $tab): string
    {
        $views = [

            'home' => 'pages/courses/features/home/index',

            'modules' => 'pages/courses/features/modules/index',

            'resources' => 'pages/courses/features/resources/index',

            'discussions' => 'pages/courses/features/discussions/index',

            'assessments' => 'pages/courses/features/assessments/index',

            'grade' => 'pages/courses/features/grade/index',

        ];

        if (! isset($views[$tab])) {

            throw PageNotFoundException::forPageNotFound();

        }

        return $views[$tab];
    }
}
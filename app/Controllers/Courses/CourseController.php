<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\CourseService;
use CodeIgniter\Exceptions\PageNotFoundException;

class CourseController extends BaseController
{
    protected CourseService $courseService;

    public function __construct()
    {
        $this->courseService = new CourseService();
    }

    public function index(): string
    {
        $user = auth()->user();

        $loadError = false;

        try {
            $courses = $this->courseService->getCourseOfferings($user);
        } catch (\Throwable $e) {
            log_message('error', 'Courses page failed to load: ' . $e->getMessage());
            $courses = [];
            $loadError = true;
        }

        foreach ($courses as &$course) {
            $course['slug'] = url_title(
                $course['course_title'],
                '-',
                true
            );
        }

        unset($course);

        return view('pages/courses/index', [
            'title'   => 'Courses',
            'page'    => 'courses',
            'user'    => $user,
            'courses' => $courses,
            'loadError' => $loadError,
            'mainGutter' => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
            'previousPage' => 'dashboard'
        ]);
    }

    public function redirect(
        int $offeringId,
        string $slug
    ) {
        $user = auth()->user();

        $prefix = $user->inGroup('instructor')
            ? 'instructor'
            : 'student';

        return redirect()->to(
            site_url(
                "{$prefix}/courses/{$offeringId}/{$slug}/overview"
            )
        );
    }

    public function view(
        int $offeringId,
        string $slug,
        string $activeFeature
    ): string {

        $user = auth()->user();

        $course = $this->courseService
            ->getCourseOffering(
                $user,
                $offeringId
            );

        if (!$course) {

            throw PageNotFoundException::forPageNotFound();

        }

        $course['slug'] = url_title(
            $course['course_title'],
            '-',
            true
        );

        return view(
            'pages/courses/view',
            [

                'title' => $course['course_code'],

                'page' => 'courses',

                'user' => $user,

                'course' => $course,

                'activeFeature' => $activeFeature,

                'previousPage' => 'courses',

                // The workspace gets a gutter that shrinks on small screens
                // instead of the fixed 160px the rest of the app uses.
                'mainGutter' => 'px-4 sm:px-8 lg:px-20 xl:px-40',

            ]
        );

    }
}
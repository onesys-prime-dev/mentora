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

        // Courses whose term was closed: still there to look at.
        try {
            $past = $this->courseService->getCourseOfferings($user, true);
        } catch (\Throwable $e) {
            log_message('error', 'Past courses failed to load: ' . $e->getMessage());
            $past = [];
        }

        foreach ([&$courses, &$past] as &$list) {
            foreach ($list as &$course) {
                $course['slug'] = url_title(
                    $course['course_title'],
                    '-',
                    true
                );
            }

            unset($course);
        }

        unset($list);

        return view('pages/courses/index', [
            'title'   => 'Courses',
            'page'    => 'courses',
            'user'    => $user,
            'courses' => $courses,
            'pastCourses' => $past,
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

        // A dual-role user (e.g. chairperson+instructor) is redirected as
        // whichever role they're currently acting as, not just the first
        // group they happen to hold — otherwise a chairperson's own course
        // card would send them to the student section instead.
        $prefix = \App\Services\Courses\CourseViewer::urlPrefix($user, active_role() ?: ($user->inGroup('instructor') ? 'instructor' : 'student'));

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

                // The institutional administrator looks at courses; the page says so and addresses the read-only routes.
                'readOnlyView' => \App\Services\Courses\CourseViewer::isReadOnlyViewer($user),
                'courseUrlPrefix' => \App\Services\Courses\CourseViewer::urlPrefix($user, (string) ($user->getGroups()[0] ?? '')),

                // A completed or archived offering can be looked at, not changed.
                'offeringClosed' => db_connect()->table('course_offerings')->where('id', $offeringId)->where('status !=', 'active')->countAllResults() > 0,

                'previousPage' => 'courses',

                // The workspace gets a gutter that shrinks on small screens
                // instead of the fixed 160px the rest of the app uses.
                'mainGutter' => 'px-4 sm:px-8 lg:px-20 xl:px-40',

            ]
        );

    }
}
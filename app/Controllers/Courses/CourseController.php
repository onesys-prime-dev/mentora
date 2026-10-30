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
            'fullHeight' => true,
            'user'    => $user,
            'courses' => $courses,
            'pastCourses' => $past,
            'loadError' => $loadError,
            'mainGutter' => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
            'previousPage' => 'dashboard'
        ]);
    }

    /** A lesson of this offering, and one this person may see: drafts too for staff and read-only viewers. */
    private function lessonIsVisible(\CodeIgniter\Shield\Entities\User $user, int $offeringId, int $learningItemId): bool
    {
        $lesson = db_connect()->table('learning_items li')
            ->select('li.status AS item, lu.status AS unit, cm.status AS module')
            ->join('learning_units lu', 'lu.id = li.learning_unit_id')
            ->join('course_modules cm', 'cm.id = lu.module_id')
            ->where(['li.id' => $learningItemId, 'cm.offering_id' => $offeringId])
            ->get()->getRowArray();

        if ($lesson === null) {
            return false;
        }

        return \App\Services\Courses\CourseViewer::seesInstructorView($user, $offeringId)
            || ($lesson['item'] === 'published' && $lesson['unit'] === 'published' && $lesson['module'] === 'published');
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
        // What they are in this course decides the area it opens in (9.1).
        $prefix = \App\Services\Courses\CourseViewer::urlPrefix($user, (new \App\Services\Courses\CourseMembership())->roleIn($user, (int) $offeringId) ?? (active_role() ?: 'student'));

        return redirect()->to(
            site_url(
                "{$prefix}/courses/{$offeringId}/{$slug}/overview"
            )
        );
    }

    /**
     * The course page on a tab. With a lesson (a link to one: `.../modules/items/{id}`), the lesson must be this
     * course's, and visible to this person (any status for its staff and read-only viewers, published only for a
     * student), else not found (audit 5.3); the page then opens on it through `modules?item=` (5.1).
     */
    public function view(
        int $offeringId,
        string $slug,
        string $activeFeature,
        ?int $learningItemId = null
    ): string|\CodeIgniter\HTTP\RedirectResponse {

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

        if ($learningItemId !== null) {
            if (! $this->lessonIsVisible($user, $offeringId, $learningItemId)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $modules = preg_replace('#/items/\d+$#', '', uri_string());

            return redirect()->to(site_url($modules) . '?item=' . $learningItemId);
        }

        return view(
            'pages/courses/view',
            [

                'title' => $course['course_code'],

                'page' => 'courses',

                'user' => $user,

                'course' => $course,

                'activeFeature' => $activeFeature,

                // The banner and tabs stay put and only the content card scrolls.
                'fullHeight' => true,

                // The institutional administrator looks at courses; the page says so and addresses the read-only routes.
                'readOnlyView' => \App\Services\Courses\CourseViewer::isReadOnlyViewer($user),
                'readOnlyAs' => \App\Services\Courses\CourseViewer::isDeanViewer($user)
                    ? 'the dean of ' . ($course['department_name'] ?? 'this department')
                    : 'an administrator of this institution',
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
<?php

declare(strict_types=1);

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\ChairpersonCourseFilters;
use App\Services\Courses\ChairpersonCourseSearch;

/**
 * The chairperson-only Courses page (R6 of the chairperson plan): every
 * course offering across every sub-department they chair, searchable,
 * filterable and sortable — separate from CourseController::index()
 * because the search/filter/pagination this page needs doesn't exist for
 * the instructor/student list and shouldn't complicate it.
 */
class ChairpersonCoursesController extends BaseController
{
    protected ChairpersonCourseSearch $search;

    public function __construct()
    {
        $this->search = new ChairpersonCourseSearch();
    }

    public function index(): string
    {
        $user    = auth()->user();
        $filters = ChairpersonCourseFilters::fromInput($this->request->getGet());

        $loadError = false;
        $result    = ['rows' => [], 'total' => 0];
        $terms     = [];
        $instructors = [];

        try {
            $result      = $this->search->page($user, $filters);
            $terms       = $this->search->terms($user);
            $instructors = $this->search->instructors($user);
        } catch (\Throwable $e) {
            log_message('error', 'Chairperson courses page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $courses = $result['rows'];

        foreach ($courses as &$course) {
            $course['slug'] = url_title((string) $course['course_title'], '-', true);
        }

        unset($course);

        return view('pages/courses/chairperson-index', [
            'title'       => 'Courses',
            'page'        => 'courses',
            'user'        => $user,
            'courses'     => $courses,
            'loadError'   => $loadError,
            'filters'     => $filters,
            'total'       => $result['total'],
            'pages'       => max(1, (int) ceil($result['total'] / $filters->perPage)),
            'terms'       => $terms,
            'instructors' => $instructors,
            'statuses'    => ChairpersonCourseFilters::STATUSES,
            'mainGutter'  => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
            'previousPage' => 'dashboard',
        ]);
    }
}

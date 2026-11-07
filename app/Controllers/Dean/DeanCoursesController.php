<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseFilters;
use App\Services\Courses\DeanCourseSearch;

/**
 * The dean's Courses page: every course in their college. A card opens
 * the course in its read-only view (the dean/courses/{id} routes).
 */
class DeanCoursesController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        if ((new DeanAccessService())->collegeFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Courses', 'page' => 'courses', 'user' => $user]);
        }

        $search  = new DeanCourseSearch();
        $filters = DeanCourseFilters::fromInput($this->request->getGet());

        $loadError      = false;
        $result         = ['rows' => [], 'total' => 0];
        $terms          = [];
        $departments = [];
        $programs       = [];
        $instructors    = [];

        try {
            $result         = $search->page($user, $filters);
            $terms          = $search->terms($user);
            $departments = $search->departments($user);
            $programs       = $search->programs($user);
            $instructors    = $search->instructors($user);
        } catch (\Throwable $e) {
            log_message('error', 'Dean courses page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/courses', [
            'title'          => 'Courses',
            'page'           => 'courses',
            'fullHeight' => true,
            'user'           => $user,
            'courses'        => $result['rows'],
            'loadError'      => $loadError,
            'filters'        => $filters,
            'total'          => $result['total'],
            'pages'          => max(1, (int) ceil($result['total'] / $filters->perPage)),
            'terms'          => $terms,
            'departments' => $departments,
            'programs'       => $programs,
            'instructors'    => $instructors,
            'statuses'       => DeanCourseFilters::STATUSES,
            'previousPage'   => 'dashboard',
        ]);
    }
}

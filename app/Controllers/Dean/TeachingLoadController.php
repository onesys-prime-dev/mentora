<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseSearch;
use App\Services\Courses\TeachingLoadFilters;
use App\Services\Courses\TeachingLoadService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The dean's teaching-load review: each instructor of the department with the sections, students, units,
 * work waiting to be graded and grading speed they carry in a term, a marker for a high load, and a page per
 * instructor listing their sections (each opening read-only). View only; there is nothing to change here.
 * An instructor who teaches none of the department's sections is a 404, so an id cannot be guessed.
 */
class TeachingLoadController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        if ((new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Teaching load', 'page' => 'teaching-load', 'user' => $user]);
        }

        $service = new TeachingLoadService();
        $filters = TeachingLoadFilters::fromInput($this->request->getGet());

        $loadError      = false;
        $terms          = [];
        $subDepartments = [];
        $result         = ['term' => null, 'highLoadUnits' => 0, 'rows' => []];

        try {
            $terms          = $service->terms($user);
            $subDepartments = (new DeanCourseSearch())->subDepartments($user);
            $result         = $service->load($user, $filters);
        } catch (\Throwable $e) {
            log_message('error', 'Teaching load failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/teaching-load', [
            'title'          => 'Teaching load',
            'page'           => 'teaching-load',
            'user'           => $user,
            'filters'        => $filters,
            'terms'          => $terms,
            'subDepartments' => $subDepartments,
            'term'           => $result['term'],
            'highLoadUnits'  => $result['highLoadUnits'],
            'instructors'    => $result['rows'],
            'loadError'      => $loadError,
            'previousPage'   => 'dashboard',
        ]);
    }

    public function show(int $instructorId): string
    {
        $user    = auth()->user();
        $service = new TeachingLoadService();

        if ((new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Teaching load', 'page' => 'teaching-load', 'user' => $user]);
        }

        if (! $service->teachesInDepartment($user, $instructorId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $filters = TeachingLoadFilters::fromInput($this->request->getGet());
        $detail  = $service->instructor($user, $instructorId, $filters->termId);

        // They teach in the department, just not in this term.
        if ($detail === null) {
            $name = db_connect()->table('user_profiles')->select("TRIM(CONCAT_WS(' ', first_name, last_name)) AS name", false)->where('user_id', $instructorId)->get()->getRow('name');
            $detail = [
                'instructor'    => ['id' => $instructorId, 'name' => (string) ($name ?? 'Instructor'), 'sections' => 0, 'students' => 0, 'units' => 0.0, 'ungraded' => 0, 'turnaround' => null, 'high_load' => false],
                'term'          => $service->termFor($user, $filters->termId),
                'highLoadUnits' => $service->load($user, $filters)['highLoadUnits'],
                'sections'      => [],
            ];
        }

        return view('pages/dean/teaching-load-instructor', [
            'title'         => 'Teaching load',
            'page'          => 'teaching-load',
            'user'          => $user,
            'filters'       => $filters,
            'terms'         => $service->terms($user),
            'term'          => $detail['term'],
            'highLoadUnits' => $detail['highLoadUnits'],
            'instructor'    => $detail['instructor'],
            'sections'      => $detail['sections'],
            'previousPage'  => 'dashboard',
        ]);
    }
}

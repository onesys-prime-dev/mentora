<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseSearch;
use App\Services\Courses\MonitorDepartmentFilters;
use App\Services\Courses\MonitorDepartmentService;
use App\Services\Courses\MonitorProgramService;

/**
 * Monitor Department: how the dean's department is doing, compared per
 * sub-department and per program. Server-rendered like Monitor Program: the
 * filters live in the address, every number is computed here, and the page's
 * script only draws the two charts from data already on the page.
 */
class MonitorDepartmentController extends BaseController
{
    public function index(): string
    {
        $user       = auth()->user();
        $department = (new DeanAccessService())->departmentFor($user);

        if ($department === null) {
            return view('pages/dean/no-assignment', ['title' => 'Monitor Department', 'page' => 'monitor-department', 'user' => $user]);
        }

        $service = new MonitorDepartmentService();
        $filters = MonitorDepartmentFilters::fromInput($this->request->getGet());
        $lists   = new DeanCourseSearch();

        $loadError      = false;
        $terms          = [];
        $subDepartments = [];
        $programs       = [];
        $overview       = [
            'totals'            => [],
            'groups'            => [],
            'gradeDistribution' => array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0),
            'atRisk'            => [],
        ];

        try {
            $terms          = $service->terms($user);
            $subDepartments = $lists->subDepartments($user);
            $programs       = $lists->programs($user);
            $overview       = $service->overview($user, $filters);
        } catch (\Throwable $e) {
            log_message('error', 'Monitor Department page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $name = db_connect()->table('departments')->select('name')->where('id', (int) $department['department_id'])->get()->getRow('name');

        return view('pages/dean/monitor-department', [
            'title'          => 'Monitor Department',
            'page'           => 'monitor-department',
            'user'           => $user,
            'departmentName' => (string) ($name ?? 'Your department'),
            'loadError'      => $loadError,
            'filters'        => $filters,
            'terms'          => $terms,
            'subDepartments' => $subDepartments,
            'programs'       => $programs,
            'totals'         => $overview['totals'],
            'groups'         => $overview['groups'],
            'gradeDistribution' => $overview['gradeDistribution'],
            'atRisk'         => $overview['atRisk'],
            'previousPage'   => 'dashboard',
        ]);
    }
}

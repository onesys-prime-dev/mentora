<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseSearch;
use App\Services\Courses\MonitorDepartmentFilters;
use App\Services\Courses\MonitorDepartmentService;
use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramPage;
use App\Services\Courses\MonitorProgramService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Monitor Department: how the dean's department is doing, compared per
 * sub-department and per program. Server-rendered like Monitor Program: the
 * filters live in the address, every number is computed here, and the page's
 * script only draws the two charts from data already on the page.
 */
class MonitorDepartmentController extends BaseController
{
    private const AUDIT_PER_PAGE = 10;

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

        $auditPage      = max(1, (int) ($this->request->getGet('audit_page') ?? 1));
        $audit          = ['rows' => [], 'total' => 0];
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
            $audit          = $service->auditLog($user, $filters, $auditPage, self::AUDIT_PER_PAGE);
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
            'audit'          => $audit,
            'auditPage'      => $auditPage,
            'auditPages'     => max(1, (int) ceil($audit['total'] / self::AUDIT_PER_PAGE)),
            'previousPage'   => 'dashboard',
        ]);
    }

    /**
     * One program of the department: the chairperson's Monitor Program page for it, read-only.
     * A program outside the dean's department is a 404, so a program id cannot be guessed into
     * someone else's numbers.
     */
    public function program(int $programId): string
    {
        $user   = auth()->user();
        $access = new DeanAccessService();

        if ($access->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Monitor Department', 'page' => 'monitor-department', 'user' => $user]);
        }

        if (! $access->canAccessProgram($user, $programId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $monitor   = new MonitorProgramService();
        $filters   = MonitorProgramFilters::fromInput($this->request->getGet());
        $sort      = (string) ($this->request->getGet('sort') ?? 'course_code');
        $dir       = (string) ($this->request->getGet('dir') ?? 'asc');
        $auditPage = max(1, (int) ($this->request->getGet('audit_page') ?? 1));

        $loadError = false;
        $terms     = [];
        $page      = [
            'snapshot'          => ['courses' => 0, 'instructors' => 0, 'students' => 0],
            'comparison'        => [],
            'atRisk'            => [],
            'audit'             => ['rows' => [], 'total' => 0],
            'auditPage'         => $auditPage,
            'auditPages'        => 1,
            'gradeDistribution' => array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0),
            'sort'              => $sort,
            'dir'               => $dir,
        ];

        try {
            $terms        = $monitor->termsForPrograms([$programId]);
            $offeringRows = $monitor->offeringRowsForPrograms([$programId], $filters);
            $page         = (new MonitorProgramPage($monitor))->build($offeringRows, $filters, $sort, $dir, $auditPage, self::AUDIT_PER_PAGE, null);
        } catch (\Throwable $e) {
            log_message('error', 'Monitor Department program page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $program = db_connect()->table('programs p')
            ->select(['p.name AS program', 'sd.name AS sub_department', 'd.name AS department'])
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('p.id', $programId)->get()->getRowArray() ?? [];

        return view('pages/courses/monitor-program', $page + [
            'title'        => 'Monitor Program',
            'page'         => 'monitor-department',
            'user'         => $user,
            'loadError'    => $loadError,
            'filters'      => $filters,
            'terms'        => $terms,

            // The chairperson's page, addressed and worded for the dean.
            'monitorBase'  => site_url("dean/monitor-department/programs/{$programId}"),
            'monitorCourseBase'  => 'dean',
            'monitorHeading'     => (string) ($program['program'] ?? 'Program'),
            'monitorSubheading'  => ($program['department'] ?? '') . ' · ' . ($program['sub_department'] ?? '') . ': how this program is performing. You can look, not change.',
            'monitorAuditTitle'  => 'Recent chairperson actions in this program',
            'monitorShowActor'   => true,
            'monitorEmptyText'   => 'Courses in this program will appear here.',
            'monitorBackUrl'     => site_url('dean/monitor-department?compare=program'),
            'monitorBackLabel'   => 'Back to Monitor Department',
            'mainGutter'   => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
        ]);
    }
}

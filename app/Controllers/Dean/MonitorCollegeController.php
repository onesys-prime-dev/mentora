<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseSearch;
use App\Services\Courses\MonitorCollegeFilters;
use App\Services\Courses\MonitorCollegeService;
use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramPage;
use App\Services\Courses\MonitorProgramService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Monitor College: how the dean's college is doing, compared per
 * department and per program. Server-rendered like Monitor Program: the
 * filters live in the address, every number is computed here, and the page's
 * script only draws the two charts from data already on the page.
 */
class MonitorCollegeController extends BaseController
{
    private const AUDIT_PER_PAGE = 10;

    public function index(): string
    {
        $user       = auth()->user();
        $college = (new DeanAccessService())->collegeFor($user);

        if ($college === null) {
            return view('pages/dean/no-assignment', ['title' => 'Monitor College', 'page' => 'monitor-college', 'user' => $user]);
        }

        $service = new MonitorCollegeService();
        $filters = MonitorCollegeFilters::fromInput($this->request->getGet());
        $lists   = new DeanCourseSearch();

        $auditPage      = max(1, (int) ($this->request->getGet('audit_page') ?? 1));
        $audit          = ['rows' => [], 'total' => 0];
        $instructors    = [];
        $loadError      = false;
        $terms          = [];
        $departments = [];
        $programs       = [];
        $overview       = [
            'totals'            => [],
            'groups'            => [],
            'gradeDistribution' => array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0),
            'atRisk'            => [],
        ];

        try {
            $terms          = $service->terms($user);
            $departments = $lists->departments($user);
            $programs       = $lists->programs($user);
            $overview       = $service->overview($user, $filters);
            $instructors    = $service->instructorActivity($user, $filters);
            $audit          = $service->auditLog($user, $filters, $auditPage, self::AUDIT_PER_PAGE);
        } catch (\Throwable $e) {
            log_message('error', 'Monitor College page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $name = db_connect()->table('colleges')->select('name')->where('id', (int) $college['college_id'])->get()->getRow('name');

        return view('pages/dean/monitor-college', [
            'title'          => 'Monitor College',
            'page'           => 'monitor-college',
            'user'           => $user,
            'collegeName' => (string) ($name ?? 'Your college'),
            'loadError'      => $loadError,
            'filters'        => $filters,
            'terms'          => $terms,
            'departments' => $departments,
            'programs'       => $programs,
            'totals'         => $overview['totals'],
            'groups'         => $overview['groups'],
            'gradeDistribution' => $overview['gradeDistribution'],
            'atRisk'         => $overview['atRisk'],
            'instructorActivity' => $instructors,
            'audit'          => $audit,
            'auditPage'      => $auditPage,
            'auditPages'     => max(1, (int) ceil($audit['total'] / self::AUDIT_PER_PAGE)),
            'previousPage'   => 'dashboard',
        ]);
    }

    /**
     * The page's numbers as JSON, one section per call: totals, groups (the comparison rows),
     * grade-distribution, instructor-activity, at-risk. The same filters and the same service as
     * the page, so the two can never disagree; the dean's college is the only scope.
     */
    public function data(string $section): ResponseInterface
    {
        $user    = auth()->user();
        $service = new MonitorCollegeService();

        if ((new DeanAccessService())->collegeFor($user) === null) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'No college is assigned to you.']);
        }

        if (! in_array($section, ['totals', 'groups', 'grade-distribution', 'instructor-activity', 'at-risk'], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $filters = MonitorCollegeFilters::fromInput($this->request->getGet());

        try {
            if ($section === 'instructor-activity') {
                return $this->response->setJSON($service->instructorActivity($user, $filters));
            }

            $overview = $service->overview($user, $filters);
        } catch (\Throwable $e) {
            log_message('error', 'Monitor College data failed to load: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON(['error' => 'Could not load this data right now.']);
        }

        return $this->response->setJSON(match ($section) {
            'totals'             => $overview['totals'],
            'groups'             => $overview['groups'],
            'grade-distribution' => $overview['gradeDistribution'],
            default              => $overview['atRisk'],
        });
    }

    /**
     * One program of the college: the chairperson's Monitor Program page for it, read-only.
     * A program outside the dean's college is a 404, so a program id cannot be guessed into
     * someone else's numbers.
     */
    public function program(int $programId): string
    {
        $user   = auth()->user();
        $access = new DeanAccessService();

        if ($access->collegeFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Monitor College', 'page' => 'monitor-college', 'user' => $user]);
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
            log_message('error', 'Monitor College program page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        $program = db_connect()->table('programs p')
            ->select(['p.name AS program', 'sd.name AS department', 'd.name AS college'])
            ->join('departments sd', 'sd.id = p.department_id')
            ->join('colleges d', 'd.id = sd.college_id')
            ->where('p.id', $programId)->get()->getRowArray() ?? [];

        return view('pages/courses/monitor-program', $page + [
            'title'        => 'Monitor Program',
            'page'         => 'monitor-college',
            'fullHeight' => true,
            'user'         => $user,
            'loadError'    => $loadError,
            'filters'      => $filters,
            'terms'        => $terms,

            // The chairperson's page, addressed and worded for the dean.
            'monitorBase'  => site_url("dean/monitor-college/programs/{$programId}"),
            'monitorCourseBase'  => 'dean',
            'monitorGradesBase'  => site_url('dean/grades'),
            'monitorGradesQuery' => ['program' => $programId],
            'monitorHeading'     => (string) ($program['program'] ?? 'Program'),
            'monitorSubheading'  => ($program['college'] ?? '') . ' · ' . ($program['department'] ?? '') . ': how this program is performing. You can look, not change.',
            'monitorAuditTitle'  => 'Recent chairperson actions in this program',
            'monitorShowActor'   => true,
            'monitorEmptyText'   => 'Courses in this program will appear here.',
            'monitorBackUrl'     => site_url('dean/monitor-college?compare=program'),
            'monitorBackLabel'   => 'Back to Monitor College',
            'mainGutter'   => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\ChairpersonCourseSearch;
use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramPage;
use App\Services\Courses\MonitorProgramService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Monitor Program (R5 of the chairperson plan): the page itself (index), plus
 * the JSON endpoints it and its charts read from — everything scoped to the
 * signed-in chairperson's own program and never a course outside it.
 */
class MonitorProgramController extends BaseController
{
    private const AUDIT_PER_PAGE = 10;

    protected MonitorProgramService $monitor;

    public function __construct()
    {
        $this->monitor = new MonitorProgramService();
    }

    /**
     * The whole page, server-rendered: filters live in the address (GET),
     * same convention as the Courses page, so a filtered view can be
     * bookmarked. Every metric is computed once here and handed to the
     * view — the only client JS on this page draws the two charts from
     * data already on the page, it never re-fetches.
     */
    public function index(): string
    {
        $user = auth()->user();
        $filters = MonitorProgramFilters::fromInput($this->request->getGet());

        $sort = (string) ($this->request->getGet('sort') ?? 'course_code');
        $dir = (string) ($this->request->getGet('dir') ?? 'asc');
        $auditPage = max(1, (int) ($this->request->getGet('audit_page') ?? 1));

        $loadError = false;
        $terms = [];
        $page = [
            'snapshot' => ['courses' => 0, 'instructors' => 0, 'students' => 0],
            'comparison' => [],
            'atRisk' => [],
            'audit' => ['rows' => [], 'total' => 0],
            'auditPage' => $auditPage,
            'auditPages' => 1,
            'gradeDistribution' => array_fill_keys(array_keys(MonitorProgramService::GRADE_BUCKETS), 0),
            'sort' => $sort,
            'dir' => $dir,
        ];

        try {

            $terms = (new ChairpersonCourseSearch())->terms($user);
            $offeringRows = $this->monitor->offeringRows($user, $filters);

            // The page's figures are built by MonitorProgramPage, the same builder the dean's
            // drill-down into one program uses; this page lists the chairperson's own actions.
            $page = (new MonitorProgramPage($this->monitor))->build($offeringRows, $filters, $sort, $dir, $auditPage, self::AUDIT_PER_PAGE, (int) $user->id);

        } catch (\Throwable $e) {
            log_message('error', 'Monitor Program page failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/courses/monitor-program', $page + [
            'title' => 'Monitor Program',
            'page' => 'monitor-program',
            'fullHeight' => true,
            'user' => $user,
            'loadError' => $loadError,
            'filters' => $filters,
            'terms' => $terms,
            'mainGutter' => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
            'previousPage' => 'dashboard',
        ]);
    }


    public function snapshot(): ResponseInterface
    {
        return $this->respond(function (array $offeringRows) {
            return $this->monitor->snapshot($offeringRows);
        });
    }

    public function enrollment(): ResponseInterface
    {
        return $this->respond(function (array $offeringRows) {
            return array_values($this->monitor->enrollmentAndCompletion($this->offeringIds($offeringRows)));
        });
    }

    public function grades(): ResponseInterface
    {
        return $this->respond(function (array $offeringRows) {
            return array_values($this->monitor->grades($this->offeringIds($offeringRows)));
        });
    }

    public function instructorActivity(): ResponseInterface
    {
        return $this->respond(function (array $offeringRows, MonitorProgramFilters $filters) {
            return array_values($this->monitor->instructorActivity($this->offeringIds($offeringRows), $filters));
        });
    }

    public function atRisk(): ResponseInterface
    {
        return $this->respond(function (array $offeringRows) {
            return $this->monitor->atRisk($this->offeringIds($offeringRows));
        });
    }

    public function courseComparison(): ResponseInterface
    {
        return $this->respond(function (array $offeringRows, MonitorProgramFilters $filters) {
            $sort = (string) ($this->request->getGet('sort') ?? 'course_code');
            $dir  = (string) ($this->request->getGet('dir') ?? 'asc');

            return $this->monitor->courseComparison($offeringRows, $filters, $sort, $dir);
        });
    }

    public function terms(): ResponseInterface
    {
        $user = auth()->user();

        try {
            return $this->response->setJSON((new ChairpersonCourseSearch())->terms($user));
        } catch (\Throwable $e) {
            log_message('error', 'Monitor Program terms failed to load: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Could not load terms.']);
        }
    }

    /**
     * Resolves the user and filters, builds the scoped offering list once,
     * runs $callback against it and returns the JSON — the shared shape
     * behind every action here so each one stays a one-liner and no
     * uncaught exception reaches a chairperson user as a raw 500.
     */
    private function respond(callable $callback): ResponseInterface
    {
        $user    = auth()->user();
        $filters = MonitorProgramFilters::fromInput($this->request->getGet());

        try {
            $offeringRows = $this->monitor->offeringRows($user, $filters);
            $data         = $callback($offeringRows, $filters);
        } catch (\Throwable $e) {
            log_message('error', 'Monitor Program data failed to load: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Could not load this data right now.']);
        }

        return $this->response->setJSON($data);
    }

    /** @return list<int> */
    private function offeringIds(array $offeringRows): array
    {
        return array_map(static fn (array $row) => (int) $row['offering_id'], $offeringRows);
    }
}

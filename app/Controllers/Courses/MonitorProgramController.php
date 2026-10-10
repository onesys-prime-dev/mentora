<?php

declare(strict_types=1);

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\ChairpersonCourseSearch;
use App\Services\Courses\MonitorProgramFilters;
use App\Services\Courses\MonitorProgramService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Monitor Program's data layer (R5 of the chairperson plan, branch
 * feat/monitor-program-data): JSON endpoints the Monitor Program page reads
 * from. No page here yet — that's feat/monitor-program-page — so each
 * action returns only the slice of data its name promises, scoped to the
 * signed-in chairperson's own program and never a course outside it.
 */
class MonitorProgramController extends BaseController
{
    protected MonitorProgramService $monitor;

    public function __construct()
    {
        $this->monitor = new MonitorProgramService();
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

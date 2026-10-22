<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DepartmentReportService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The dean's department report, taken out of the app: a CSV of each part (Monitor Department, teaching
 * load, sign-off status) and one print-friendly page of all three. Both use the filters in the address, so
 * what leaves is what the dean was looking at. Taking data out is recorded with the acting role. Looking
 * is all it needs, so a read-only institution's dean can export too. A dean with no department has
 * nothing to export: it is a 404.
 */
class DepartmentReportController extends BaseController
{
    public function export(string $section): ResponseInterface
    {
        if (! DepartmentReportService::isSection($section)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $user    = auth()->user();
        $service = new DepartmentReportService();
        $report  = $service->build($user, $this->query(), null, [$section]);

        if ($report === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $export = $service->csv($section, $report);

        $this->record('department_report.exported', $section, $export['rows']);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $service->fileStem($section, $report['department']) . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $export['csv']);
    }

    public function print(): string
    {
        $user   = auth()->user();
        $report = (new DepartmentReportService())->build($user, $this->query());

        if ($report === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->record('department_report.printed', 'all', count($report['monitor']['groups']) + count($report['teaching_load']['rows']));

        return view('pages/dean/report-print', ['report' => $report, 'sections' => DepartmentReportService::SECTIONS]);
    }

    /** @return array<string, mixed> */
    private function query(): array
    {
        $get = $this->request->getGet();

        return is_array($get) ? $get : [];
    }

    /** Taking data out is itself recorded. */
    private function record(string $action, string $section, int $rows): void
    {
        $department = (new \App\Services\Courses\DeanAccessService())->departmentFor(auth()->user());

        service('auditLogger')->log($action, auth()->user(), [
            'acting_role' => 'dean',
            'target_type' => 'department',
            'target_id'   => $department['department_id'] ?? null,
            'new'         => ['section' => $section, 'rows' => $rows, 'filters' => $this->query()],
        ]);
    }
}

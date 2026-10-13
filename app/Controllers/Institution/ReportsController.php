<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Courses\MonitorProgramFilters;
use App\Services\Institutions\InstitutionReports;
use App\Services\Institutions\InstitutionScope;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institution's reports: the page, a course opened from it, and the CSV
 * of each report. The routes decide who may (reports.view); scope and the
 * numbers are InstitutionReports (and so Monitor Program).
 */
class ReportsController extends BaseController
{
    private InstitutionReports $reports;

    public function __construct()
    {
        $this->reports = new InstitutionReports();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        [$filters, $department, $program] = $this->filters();

        $sort = (string) ($this->request->getGet('sort') ?? 'course_code');
        $dir  = (string) ($this->request->getGet('dir') ?? 'asc');

        return view('pages/institution/reports', [
            'title'          => 'Reports',
            'page'           => 'institution-reports',
            'pageTitle'      => 'Reports',
            'user'           => auth()->user(),
            'reportData'     => $this->reports->report($institutionId, $filters, $department, $program, $sort, $dir),
            'reportOptions'  => $this->reports->options($institutionId),
            'reportFilters'  => $filters,
            'reportDepartment' => $department,
            'reportProgram'  => $program,
            'reportSort'     => $sort,
            'reportDir'      => $dir === 'desc' ? 'desc' : 'asc',
            'reportExports'  => InstitutionReports::EXPORTS,
            'mainGutter'     => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
        ]);
    }

    public function offering(int $id): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        [$filters] = $this->filters();

        $detail = $this->reports->offering($institutionId, $id, $filters);

        return view('pages/institution/report-offering', [
            'title'         => 'Course report',
            'page'          => 'institution-reports',
            'pageTitle'     => 'Reports',
            'user'          => auth()->user(),
            'offeringReport' => $detail,
            'reportFilters' => $filters,
            'mainGutter'    => 'px-4 sm:px-8 xl:px-20 2xl:px-40',
        ]);
    }

    public function export(string $kind): ResponseInterface
    {
        if (! InstitutionReports::isExport($kind)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $institutionId = (new InstitutionScope())->requireInstitutionId();
        [$filters, $department, $program] = $this->filters();

        $report = $this->reports->report($institutionId, $filters, $department, $program);
        $export = $this->reports->csv($kind, $report, $filters);

        // Taking data out is itself recorded.
        service('auditLogger')->log('institution.report_exported', auth()->user(), [
            'target_type'    => 'institution',
            'target_id'      => $institutionId,
            'institution_id' => $institutionId,
            'new'            => ['report' => $kind, 'rows' => $export['rows'], 'filters' => $filters->toQuery(['department' => $department, 'program' => $program])],
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="report-' . $kind . '-' . date('Y-m-d') . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $export['csv']);
    }

    /**
     * @return array{0: MonitorProgramFilters, 1: ?int, 2: ?int}
     */
    private function filters(): array
    {
        $get = $this->request->getGet();
        $get = is_array($get) ? $get : [];

        $int = static fn (mixed $v): ?int => is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1 && (int) $v > 0 ? (int) $v : null;

        return [MonitorProgramFilters::fromInput($get), $int($get['department'] ?? null), $int($get['program'] ?? null)];
    }
}

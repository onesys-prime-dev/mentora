<?php

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\Grades\GradeReportService;

/**
 * Report-style exports of a section's grades: the class gradebook (XLSX /
 * CSV), the class summary and a student's report (PDF or a print view). The
 * routes decide who may ask: the instructor (and chairperson) for the class
 * and any student, a student only for their own. Every export is audited.
 */
class GradeReportController extends BaseController
{
    protected GradeReportService $reports;

    public function __construct()
    {
        $this->reports = new GradeReportService();
    }

    /** The class gradebook as a spreadsheet: ?format=xlsx (default) or csv. */
    public function classSheet(int $offeringId)
    {
        $report = $this->reports->classReport($offeringId);

        if (!$report) {
            return $this->refuse('This section has no grading yet.');
        }

        $format = strtolower((string) $this->request->getGet('format')) === 'csv' ? 'csv' : 'xlsx';

        $file = $this->reports->classSheet($report, $format);

        $this->audit('class_gradebook', $offeringId, $format, count($report['rows']));

        return $this->response->download($file['file_path'], null)->setFileName($file['filename']);
    }

    /** The class summary: ?format=pdf (default) or html (a print view). */
    public function summary(int $offeringId)
    {
        $report = $this->reports->classReport($offeringId);

        if (!$report) {
            return $this->refuse('This section has no grading yet.');
        }

        $this->audit('class_summary', $offeringId, $this->pageFormat(), count($report['rows']));

        return $this->page('class-summary', ['report' => $report], $this->reports->fileStem($report, 'class-summary'));
    }

    /** One student's report for the instructor. */
    public function student(int $offeringId, int $studentId)
    {
        $report = $this->reports->studentReport($offeringId, $studentId);

        if (!$report) {
            return $this->refuse('That student is not in this section.');
        }

        $this->audit('student_report', $offeringId, $this->pageFormat(), 1, $studentId);

        return $this->page('student-report', ['report' => $report, 'showClassMean' => true], $this->reports->fileStem($report, 'grade-report-' . $studentId));
    }

    /** A student's own report, once the grades are final. */
    public function mine(int $offeringId)
    {
        $studentId = (int) auth()->id();

        $report = $this->reports->studentReport($offeringId, $studentId, true);

        if (!$report) {
            return $this->refuse('Your grade report is available once your instructor has finalized the grades.');
        }

        $this->audit('student_report', $offeringId, $this->pageFormat(), 1, $studentId);

        return $this->page('student-report', ['report' => $report, 'showClassMean' => false], $this->reports->fileStem($report, 'my-grades'));
    }

    private function pageFormat(): string
    {
        return strtolower((string) $this->request->getGet('format')) === 'html' ? 'html' : 'pdf';
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data, string $stem)
    {
        $format = $this->pageFormat();

        $html = view('pages/courses/features/grades/reports/' . $view, $data + ['printBar' => $format === 'html'], ['saveData' => false]);

        if ($format === 'html') {
            return $this->response->setContentType('text/html; charset=UTF-8')->setBody($html);
        }

        return $this->response
            ->setContentType('application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $stem . '.pdf"')
            ->setBody($this->reports->pdf($html));
    }

    /** A plain message instead of a file, with a status the page can read. */
    private function refuse(string $message)
    {
        return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => $message]);
    }

    private function audit(string $kind, int $offeringId, string $format, int $rows, ?int $studentId = null): void
    {
        (new AuditLogger())->log('grades.report_exported', auth()->user(), [
            'target_type' => 'course_offering',
            'target_id' => $offeringId,
            'new' => array_filter(['kind' => $kind, 'format' => $format, 'rows' => $rows, 'student_id' => $studentId], static fn ($v) => $v !== null),
        ]);
    }
}

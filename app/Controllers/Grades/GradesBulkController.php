<?php

declare(strict_types=1);

namespace App\Controllers\Grades;

use App\Controllers\BaseController;
use App\Filters\CoursePermissionFilter;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RolePermissions;
use App\Services\Courses\Grades\GradeReportService;
use App\Services\Grades\ChairpersonProgramOverview;
use App\Services\Grades\InstructorGradesOverview;
use App\Services\Grades\ReadySectionFinalizer;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;

/**
 * The two actions of the Grades page that span every section of a term: Finalize ready sections and
 * Download all.
 *
 * They sit outside api/courses, so the course filters (coursemember, closedoffering, coursepermission) do
 * not run; the checks are made here instead (spec §13). The sections are only those the person teaches in
 * that term (InstructorGradesOverview), grading.manage is needed for both, and each section is checked on
 * its own, its refusal named. Viewing as someone never finalizes anything: the impersonation filter refuses
 * every POST before this controller runs, and the readonly filter of the instructor area refuses it in a
 * read-only institution.
 */
class GradesBulkController extends BaseController
{
    private const AREA = 'instructor';

    /** POST instructor/grades/finalize-ready (body: term): finalizes every ready section of the term. */
    public function finalizeReady(): ResponseInterface
    {
        $user = auth()->user();

        if (! (new RolePermissions())->allows($user, 'grading.manage', self::AREA)) {
            return $this->refuse(403, CoursePermissionFilter::MESSAGE);
        }

        $termId = $this->termId($this->request->getPost('term'));
        $cards  = $termId === null ? [] : (new InstructorGradesOverview())->sections($user, $termId);
        $result = (new ReadySectionFinalizer())->finalize($user, $cards);

        if (! $this->wantsJson()) {
            return redirect()->to($this->page($termId))->with('message', $this->sentence($result));
        }

        return $this->response->setJSON(['success' => true] + $result);
    }

    /** GET instructor/grades/download?term=: every section's class gradebook in one Excel file. */
    public function download(): ResponseInterface
    {
        $user = auth()->user();

        if (! (new RolePermissions())->allows($user, 'grading.manage', self::AREA)) {
            return $this->refuse(403, CoursePermissionFilter::MESSAGE);
        }

        $termId = $this->termId($this->request->getGet('term'));

        return $this->workbook(
            $user,
            $termId === null ? [] : (new InstructorGradesOverview())->sections($user, $termId),
            $termId,
            self::AREA,
            'None of your sections this term has grading yet, so there is nothing to download.',
            'gradebooks',
        );
    }

    /**
     * GET chairperson/grades/download-program?term=: the class gradebook of every section of the chaired
     * sub-departments in one Excel file. The sections are the ones the Program tab lists (so a section of
     * another program is never in it); program.monitor is the route's filter.
     */
    public function downloadProgram(): ResponseInterface
    {
        $user   = auth()->user();
        $termId = $this->termId($this->request->getGet('term'));

        return $this->workbook(
            $user,
            $termId === null ? [] : (new ChairpersonProgramOverview())->all($user, $termId),
            $termId,
            'chairperson',
            "None of the program's sections this term has grading yet, so there is nothing to download.",
            'program-gradebooks',
        );
    }

    /**
     * One workbook of the sections that have grading, with one export entry per sheet; a refusal saying
     * $nothing when none has.
     *
     * @param list<array<string, mixed>> $sections rows with an offering_id
     */
    private function workbook(User $user, array $sections, ?int $termId, string $area, string $nothing, string $stem): ResponseInterface
    {
        $service = new GradeReportService();
        $reports = [];

        foreach ($sections as $section) {
            // A section that never had grading set up has no sheet; nothing is created by asking.
            $report = $service->classReport((int) $section['offering_id']);

            if ($report !== null) {
                $reports[(int) $section['offering_id']] = $report;
            }
        }

        if ($reports === []) {
            return $this->refuse(404, $nothing, $termId, $area);
        }

        $file  = $service->classWorkbook(array_values($reports), $stem);
        $audit = new AuditLogger();

        // One entry per section, as the single class sheet writes it.
        foreach ($reports as $offeringId => $report) {
            $audit->log('grades.report_exported', $user, [
                'target_type' => 'course_offering',
                'target_id'   => $offeringId,
                'new'         => ['kind' => 'class_gradebook', 'format' => 'xlsx', 'rows' => count($report['rows'])],
            ]);
        }

        return $this->response->download($file['file_path'], null)->setFileName($file['filename']);
    }

    /** A term id from the request: a whole number, else null. */
    private function termId(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    /** The Grades page of a term, where a browser is sent back to. */
    private function page(?int $termId, string $area = self::AREA): string
    {
        return site_url($area . '/grades') . ($termId === null ? '' : '?term=' . $termId);
    }

    /** A script gets the status and a JSON message; a browser goes back to the page with the message. */
    private function refuse(int $status, string $message, ?int $termId = null, string $area = self::AREA): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->response->setStatusCode($status)->setJSON(['success' => false, 'message' => $message]);
        }

        return redirect()->to($this->page($termId, $area))->with('error', $message);
    }

    /**
     * What happened, in a sentence: "Finalized 2 sections. Skipped 1: IT101-B — 1 piece of handed-in work is
     * still waiting to be graded."
     *
     * @param array{done: list<array<string, mixed>>, skipped: list<array{label: string, reason: string}>} $result
     */
    private function sentence(array $result): string
    {
        $done = count($result['done']);
        $text = 'Finalized ' . $done . ' ' . ($done === 1 ? 'section' : 'sections') . '.';

        if ($result['skipped'] !== []) {
            $text .= ' Skipped ' . count($result['skipped']) . ': ' . implode('; ', array_map(
                static fn (array $s): string => $s['label'] . ' — ' . rtrim($s['reason'], '.'),
                $result['skipped'],
            )) . '.';
        }

        return $text;
    }
}

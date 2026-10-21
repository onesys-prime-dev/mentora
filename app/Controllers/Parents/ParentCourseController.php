<?php

declare(strict_types=1);

namespace App\Controllers\Parents;

use App\Controllers\BaseController;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\Grades\GradeReportService;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentCourseTabs;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A child's course for the parent: the course page with its four read-only
 * tabs. The route filters have already answered 404 for a child the parent is
 * not linked to or a course the child is not in, and the portal-off page when
 * the portal is switched off; every route here is a GET.
 */
class ParentCourseController extends BaseController
{
    /** The only tabs a parent has. */
    private const FEATURES = ['overview', 'announcements', 'assessments', 'grades'];

    /** The course address on its own opens the Overview. */
    public function redirect(int $studentId, int $offeringId, string $slug): RedirectResponse
    {
        return redirect()->to(site_url("parent/children/{$studentId}/courses/{$offeringId}/{$slug}/overview"));
    }

    /** The Overview tab's fragment. */
    public function overview(int $studentId, int $offeringId): ResponseInterface
    {
        return $this->response->setJSON((new ParentCourseTabs())->overview($offeringId));
    }

    /** The Announcements tab's fragment (the `focus` query a calendar link adds is not needed here). */
    public function announcements(int $studentId, int $offeringId): ResponseInterface
    {
        return $this->response->setJSON((new ParentCourseTabs())->announcements($offeringId));
    }

    /** The Assessments tab's fragment: titles, due dates and status words, never a score. */
    public function assessments(int $studentId, int $offeringId): ResponseInterface
    {
        $access = new ParentAccessService();
        $child  = $access->childUser(auth()->user(), $studentId) ?? throw PageNotFoundException::forPageNotFound();
        $course = $access->childOffering(auth()->user(), $studentId, $offeringId) ?? throw PageNotFoundException::forPageNotFound();

        return $this->response->setJSON((new ParentCourseTabs())->assessments($child, $offeringId, (int) $course['course_id']));
    }

    /** The Grades tab's fragment: the standing and the categories, never an item. */
    public function grades(int $studentId, int $offeringId): ResponseInterface
    {
        $access = new ParentAccessService();
        $child  = $access->childUser(auth()->user(), $studentId) ?? throw PageNotFoundException::forPageNotFound();
        $course = $access->childOffering(auth()->user(), $studentId, $offeringId) ?? throw PageNotFoundException::forPageNotFound();

        return $this->response->setJSON((new ParentCourseTabs())->grades($child, ['id' => $offeringId, 'course_id' => (int) $course['course_id']]));
    }

    /**
     * The child's grade report as a PDF, once the grades are final. It is the
     * student's report without the item-by-item part (the Points column and
     * the Scores list), so it says no more than the Grades tab does. Every
     * download is audited.
     */
    public function report(int $studentId, int $offeringId): ResponseInterface
    {
        $reports = new GradeReportService();
        $report  = $reports->studentReport($offeringId, $studentId, true);

        if ($report === null) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'The grade report is available once the grades are final.']);
        }

        (new AuditLogger())->log('grades.report_exported', auth()->user(), [
            'target_type' => 'course_offering',
            'target_id'   => $offeringId,
            'new'         => ['kind' => 'student_report', 'format' => 'pdf', 'rows' => 1, 'student_id' => $studentId],
        ]);

        $html = view('pages/courses/features/grades/reports/student-report', ['report' => $report, 'showClassMean' => false, 'showItems' => false, 'printBar' => false], ['saveData' => false]);

        return $this->response
            ->setContentType('application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $reports->fileStem($report, 'grade-report') . '.pdf"')
            ->setBody($reports->pdf($html));
    }

    public function page(int $studentId, int $offeringId, string $slug, string $feature): string
    {
        // Modules, Resources and Discussions are not for a parent: there is nothing to open.
        if (! in_array($feature, self::FEATURES, true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $parent = auth()->user();
        $access = new ParentAccessService();
        $course = $access->childOffering($parent, $studentId, $offeringId) ?? throw PageNotFoundException::forPageNotFound();
        $name   = (string) (array_column($access->children($parent), 'name', 'student_id')[$studentId] ?? 'your child');

        helper('url');

        $course['slug'] = url_title((string) $course['course_title'], '-', true);

        return view('pages/courses/view', [
            'title'          => $course['course_code'],
            'page'           => 'courses',
            'user'           => $parent,
            'course'         => $course,
            'activeFeature'  => $feature,
            'courseRole'     => 'parent',
            'courseFeatures' => array_combine(self::FEATURES, ['Overview', 'Announcements', 'Assessments', 'Grades']),
            'courseUrlPrefix' => 'parent/children/' . $studentId,
            'courseBackUrl'  => site_url('parent/children/' . $studentId),
            'parentNote'     => 'View only. You are looking at ' . $course['course_code'] . ' ' . $course['section_code'] . ' as ' . $name . '\'s parent. Nothing here can be changed.',
            'offeringClosed' => db_connect()->table('course_offerings')->where('id', $offeringId)->where('status !=', 'active')->countAllResults() > 0,
            'previousPage'   => 'dashboard',
            'mainGutter'     => 'px-4 sm:px-8 lg:px-20 xl:px-40',
        ]);
    }
}

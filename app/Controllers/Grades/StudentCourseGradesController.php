<?php

declare(strict_types=1);

namespace App\Controllers\Grades;

use App\Controllers\BaseController;
use App\Models\Grades\GradingSchemeModel;
use App\Services\Courses\GradeService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * One course's small grade breakdown for the student's Grades page, fetched when its row is expanded (spec §5.3).
 *
 * The `coursemember:student` filter has already decided the student is in this course. A course without a
 * Published scheme has nothing to show and is a 404; the scheme is looked at without creating it, so asking
 * about such a course writes nothing.
 */
class StudentCourseGradesController extends BaseController
{
    public function show(int $offeringId): ResponseInterface
    {
        $user   = auth()->user();
        $scheme = (new GradingSchemeModel())->findForOffering($offeringId);

        if ($scheme === null || $scheme['status'] !== 'Published') {
            throw PageNotFoundException::forPageNotFound();
        }

        $offering = db_connect()->table('course_offerings o')
            ->select('o.id, o.course_id, c.course_title')
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.id', $offeringId)
            ->get()
            ->getRowArray();

        if ($offering === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $page = (new GradeService())->buildStudentGrades($user, ['id' => (int) $offering['id'], 'course_id' => (int) $offering['course_id']]);

        $html = view('pages/grades/partials/student/breakdown', [
            'summary'    => $page['summary'],
            'periods'    => $page['periodBreakdown'],
            'categories' => $page['categoryBreakdown'],
            'courseUrl'  => site_url('student/courses/' . $offeringId . '/' . url_title((string) $offering['course_title'], '-', true) . '/grades'),
        ], ['saveData' => false]);

        return $this->response->setJSON(['success' => true, 'html' => $html]);
    }
}

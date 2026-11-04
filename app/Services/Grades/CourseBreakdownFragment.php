<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Models\Grades\GradingSchemeModel;
use App\Services\Courses\GradeService;
use CodeIgniter\Shield\Entities\User;

/**
 * The small breakdown a course row on a Grades page loads when it is expanded (spec §5.3), for the student and,
 * through the same path, for a parent looking at their child.
 *
 * A course without a Published scheme has nothing to show: the scheme is looked at without creating it, so
 * asking about such a course writes nothing.
 */
class CourseBreakdownFragment
{
    /**
     * @param string $courseUrlPrefix where the course pages live for this reader: `student` or `parent/children/{child}`
     *
     * @param bool   $forParent    true when the reader is the parent: the fragment then speaks of "the instructor", not "your instructor"
     *
     * @return ?string the fragment's HTML; null when the course has no published grades to show
     */
    public function html(User $student, int $offeringId, string $courseUrlPrefix, bool $forParent = false): ?string
    {
        $scheme = (new GradingSchemeModel())->findForOffering($offeringId);

        if ($scheme === null || $scheme['status'] !== 'Published') {
            return null;
        }

        $offering = db_connect()->table('course_offerings o')
            ->select('o.id, o.course_id, c.course_title')
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.id', $offeringId)
            ->get()
            ->getRowArray();

        if ($offering === null) {
            return null;
        }

        $page = (new GradeService())->buildStudentGrades($student, ['id' => (int) $offering['id'], 'course_id' => (int) $offering['course_id']]);

        return view('pages/grades/partials/student/breakdown', [
            'summary'    => $page['summary'],
            'periods'    => $page['periodBreakdown'],
            'categories' => $page['categoryBreakdown'],
            'forParent'  => $forParent,
            'courseUrl'  => site_url($courseUrlPrefix . '/courses/' . $offeringId . '/' . url_title((string) $offering['course_title'], '-', true) . '/grades'),
        ], ['saveData' => false]);
    }
}

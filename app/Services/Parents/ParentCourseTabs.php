<?php

declare(strict_types=1);

namespace App\Services\Parents;

use App\Models\Courses\AnnouncementModel;
use App\Models\Courses\CourseOfferingModel;
use App\Models\Grades\GradingSchemeModel;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\GradeService;
use App\Services\Courses\OverviewService;
use CodeIgniter\Shield\Entities\User;

/**
 * What each tab of a child's course holds for a parent. The data comes from
 * the services the student's own tabs use; the parts a parent must not see
 * (anything about a single item or another student, the student's own
 * progress and activity, replies, files) are simply never passed to the views.
 *
 * Every method answers `['html' => ...]`, the shape the tab scripts expect.
 * Who may ask is decided by the route filters, not here.
 */
class ParentCourseTabs
{
    /** Announcements shown on the tab (the latest, pinned first); there is no "load more" for a parent. */
    public const ANNOUNCEMENT_LIMIT = 20;

    /**
     * The course's announcements as a feed: subject, text, author, date. Never
     * the replies, reactions or attachments (a parent cannot open the
     * student's files and replies are other students' words), and no
     * composer or menu.
     */
    public function announcements(int $offeringId): array
    {
        helper('announcement');

        // The model gives one row per role the author holds, so a post can come back more than
        // once: keep the first of each, then take the latest. The wider fetch leaves room for that.
        $rows = [];

        foreach ((new AnnouncementModel())->retrieveForOffering($offeringId, ['limit' => self::ANNOUNCEMENT_LIMIT * 5]) as $row) {
            $rows[(int) $row['id']] ??= $row;
        }

        $rows = array_values($rows);

        return ['html' => view('pages/parent/course/announcements', [
            'announcements' => array_slice($rows, 0, self::ANNOUNCEMENT_LIMIT),
            'truncated'     => count($rows) > self::ANNOUNCEMENT_LIMIT,
            'limit'         => self::ANNOUNCEMENT_LIMIT,
        ], ['saveData' => false])];
    }

    /**
     * What the child has been given and where it stands: title, type, due date
     * and a status word. Never a score, an attempt, feedback or a way into a
     * quiz. A child who is also staff has no list (the shared service would
     * answer as that staff member).
     */
    public function assessments(User $child, int $offeringId, int $courseId): array
    {
        $groups = [];

        if (ParentAccessService::readsAsStudent($child)) {
            $rows = (new AssessmentOverviewService())->build($child, $offeringId, $courseId)['assessments'];

            usort($rows, static fn (array $a, array $b): int => ($a['due_ts'] ?? PHP_INT_MAX) <=> ($b['due_ts'] ?? PHP_INT_MAX));

            foreach ($rows as $row) {
                $groups[$row['assessment_type'] ?: 'Other'][] = [
                    'title'  => (string) $row['title'],
                    'due'    => $row['due_ts'] !== null ? 'Due ' . date('D j M, g:i A', (int) $row['due_ts']) : 'No due date',
                    'status' => (string) $row['student']['label'],
                    'tone'   => (string) $row['student']['tone'],
                ];
            }
        }

        return ['html' => view('pages/parent/course/assessments', ['groups' => $groups], ['saveData' => false])];
    }

    /**
     * The child's standing as the student's own Grades tab gives it: overall
     * percentage, band, "Final" or "So far", the tag, and each category's
     * weight and percentage (grouped under their periods when the scheme has
     * them). Never an item, a manual score or an assessment. Only once the
     * scheme is published; a course without a scheme shows nothing (and none
     * is created by looking), and neither does a child who is also staff.
     *
     * @param array<string, mixed> $offering at least `id` and `course_id`
     */
    public function grades(User $child, array $offering): array
    {
        $standing = null;

        if (ParentAccessService::readsAsStudent($child) && (new GradingSchemeModel())->findForOffering((int) $offering['id']) !== null) {
            $page    = (new GradeService())->buildStudentGrades($child, $offering);
            $summary = $page['summary'];

            if ($summary['is_published']) {
                $lines = static fn (array $categories): array => array_map(static fn (array $c): array => [
                    'name'       => (string) $c['name'],
                    'weight'     => $c['weight'],
                    'percentage' => $c['percentage'],
                ], $categories);

                $standing = [
                    'percentage' => $summary['overall_percentage'] !== null ? (float) $summary['overall_percentage'] : null,
                    'band'       => $summary['grade_label'] ?? null,
                    'tag'        => (string) $summary['tag'],
                    'is_final'   => (bool) $summary['is_final'],
                    'categories' => $lines($page['categoryBreakdown']),
                    'periods'    => array_map(static fn (array $p): array => [
                        'name'       => (string) $p['name'],
                        'weight'     => $p['weight'],
                        'percentage' => $p['percentage'],
                        'categories' => $lines($p['categories']),
                    ], $page['periodBreakdown']),
                ];
            }
        }

        return ['html' => view('pages/parent/course/grades', ['standing' => $standing], ['saveData' => false])];
    }

    /** The course facts: what it is, who teaches it, when and where, the term. */
    public function overview(int $offeringId): array
    {
        return ['html' => view('pages/parent/course/overview', [
            'overview' => (new CourseOfferingModel())->getCourseOverviewData($offeringId),
            'schedule' => (new OverviewService())->scheduleBlocks($offeringId),
            // The course-info partial is the one the student's Overview uses; for a parent it shows
            // the same four facts, with no class size.
            'role'     => 'student',
        ], ['saveData' => false])];
    }
}

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
     * What the instructors posted in the child's course chat, newest first, and a button to message each of
     * them. Plain text only (escaped in the view); no replies, no student's words, no composer.
     *
     * @param array{instructors: list<array{id: int, name: string}>, posts: list<array<string, mixed>>} $feed from ParentFeedService
     */
    public function instructor(array $feed, int $studentId): array
    {
        return ['html' => view('pages/parent/course/instructor', ['feed' => $feed, 'studentId' => $studentId], ['saveData' => false])];
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
                    'group'  => $row['is_quiz'] ? null : $this->groupLine((int) $row['id'], (int) $child->id, (float) $row['total_points']),
                ];
            }
        }

        return ['html' => view('pages/parent/course/assessments', ['groups' => $groups], ['saveData' => false])];
    }

    /**
     * A group activity (4F, D14): the child's own group (its name and the members' first names) and the child's own
     * grade as the student's result gives it (AssessmentResultService::memberAttempts, so it matches the gradebook):
     * the final, the group and individual parts, and the child's own adjustment and reason. Never the private note,
     * and never a teammate's score or adjustment (only the child's own member row is ever read). Null when this is
     * not a group activity or the child is in no group of it.
     *
     * @return array{name: string, members: list<string>, grade: ?array<string, mixed>}|null
     */
    private function groupLine(int $assessmentId, int $childId, float $total): ?array
    {
        $group = (new \App\Services\Groups\GroupSubmissionResolver())->groupFor($assessmentId, $childId);

        if ($group === null) {
            return null;
        }

        $members = array_column(db_connect()->table('student_group_members m')
            ->select("COALESCE(NULLIF(TRIM(p.first_name), ''), u.username) AS name", false)
            ->join('users u', 'u.id = m.student_user_id')->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->where('m.group_id', (int) $group['id'])->orderBy('m.id', 'ASC')->get()->getResultArray(), 'name');

        $results = new \App\Services\Courses\Assessments\AssessmentResultService();
        $own     = db_connect()->table('assessment_submissions')->where('assessment_id', $assessmentId)->where('student_id', $childId)->get()->getResultArray();
        $counted = $results->resultFor(false, $results->memberAttempts($assessmentId, $childId, $own))['counted'];
        $score   = $counted['member_score'] ?? null;

        return [
            'name'    => (string) $group['name'],
            'members' => $members,
            'grade'   => $score === null ? null : [
                'excused'    => !empty($counted['excused']),
                'final'      => $counted['score'],
                'total'      => $total,
                'group'      => $score['group_points'],
                'individual' => $score['individual_points'],
                'adjustment' => (float) $score['adjustment'],
                'reason'     => (string) $score['adjustment_reason'],
            ],
        ];
    }

    /**
     * The child's standing as the student's own Grades tab gives it: overall
     * percentage, band, "Final" or "So far", the tag, and each category's
     * weight and percentage (grouped under their periods when the scheme has
     * them). Never an item, a manual score or an assessment. Only once the
     * scheme is published; a course without a scheme shows nothing (and none
     * is created by looking), and neither does a child who is also staff.
     *
     * @param array<string, mixed> $offering at least `id` and `course_id`; with `term_id` the tab also links to the child's Grades page
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

        // "All grades →": the child's Grades page for this course's term, where the page itself is open (a child who is
        // also staff has none to show).
        $allGrades = isset($offering['term_id']) && ParentAccessService::readsAsStudent($child)
            ? site_url('parent/children/' . (int) $child->id . '/grades?term=' . (int) $offering['term_id'])
            : null;

        return ['html' => view('pages/parent/course/grades', ['standing' => $standing, 'allGrades' => $allGrades], ['saveData' => false])];
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

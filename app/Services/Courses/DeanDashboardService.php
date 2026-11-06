<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Courses\EnrollmentModel;
use App\Models\Learning\CourseModuleModel;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\GradeSignoffService;
use App\Services\Institutions\AcademicDefaults;
use CodeIgniter\Shield\Entities\User;

/**
 * What the dean's home page shows, for their college: a snapshot, what needs attention, what the
 * chairpersons have been changing and the announcements they made.
 *
 * The numbers use the same definitions as the screens they link to and the same institution thresholds
 * the chairperson's and instructor's dashboards use (days of silence, days before ungraded work is
 * overdue), but are worked out for the whole college in a few queries per fact: the chairperson's
 * widgets build a full assessment overview for every section, which cannot be done for a college. Only
 * sections that are running now (active offerings) count toward what is slipping.
 */
class DeanDashboardService
{
    private const HIGHLIGHTS = 5;

    public function __construct(
        private readonly DeanAccessService $access = new DeanAccessService(),
        private readonly MonitorProgramService $monitor = new MonitorProgramService(),
        private readonly AcademicDefaults $defaults = new AcademicDefaults(),
    ) {
    }

    /**
     * Everything the page needs.
     *
     * @return array{
     *     college: array{id: int, name: string},
     *     snapshot: array{departments: int, programs: int, courses: int, instructors: int, students: int},
     *     attention: list<array{key: string, tone: string, count: int, title: string, detail: string, url: string}>,
     *     chairperson_activity: array{to_review: int, entries: list<array<string, mixed>>},
     *     announcements: list<array<string, mixed>>,
     * }|null null for a dean with no college
     */
    public function overview(User $dean, ?\DateTimeImmutable $now = null): ?array
    {
        $college = $this->access->collegeFor($dean);

        if ($college === null) {
            return null;
        }

        $now        = $now ?? new \DateTimeImmutable();
        $programIds = $this->access->programIds($dean);
        $rows       = $this->monitor->offeringRowsForPrograms($programIds, new MonitorProgramFilters());
        $running    = array_values(array_filter($rows, static fn (array $r): bool => $r['offering_status'] === 'active'));

        $name = db_connect()->table('colleges')->select('name')->where('id', (int) $college['college_id'])->get()->getRow('name');

        return [
            'college'           => ['id' => (int) $college['college_id'], 'name' => (string) ($name ?? 'Your college')],
            'snapshot'             => $this->snapshot((int) $college['college_id'], $programIds, $running),
            'attention'            => DeanAttentionRules::items($this->facts($dean, $running, $now)),
            'chairperson_activity' => $this->chairpersonActivity($dean),
            'announcements'        => array_slice((new CollegeAnnouncementService())->listForDean($dean)['rows'], 0, 3),
        ];
    }

    /**
     * The numbers each attention rule is made from.
     *
     * @param list<array<string, mixed>> $running the college's active sections
     *
     * @return array{overrides: int, signoff_ready: int, signoff_blocked: int, followups_overdue: int, overdue_grading: list<array{id: int, name: string, count: int}>, inactive_courses: list<array{id: int, name: string, count: int}>, at_risk: int}
     */
    public function facts(User $dean, array $running, \DateTimeImmutable $now): array
    {
        $thresholds = $this->defaults->thresholdsForUser($dean);
        $ids        = array_map(static fn (array $r): int => (int) $r['offering_id'], $running);

        $signoff = (new GradeSignoffService())->counts($dean);

        // Work handed in at least this many days ago and still not graded is overdue.
        $overdueBefore = $now->modify('-' . $thresholds['overdueGradingDays'] . ' days')->format('Y-m-d H:i:s');
        $silentBefore  = $now->modify('-' . $thresholds['inactiveDays'] . ' days')->format('Y-m-d H:i:s');

        $overdue = UngradedWork::countByOffering($ids, $overdueBefore);
        $last    = $ids === [] ? [] : (new CourseModuleModel())->retrieveLastActivityByOffering($ids);

        $overdueBySub  = [];
        $inactiveBySub = [];

        foreach ($running as $row) {
            $id  = (int) $row['offering_id'];
            $department = (int) $row['department_id'];

            $overdueBySub[$department]  = ($overdueBySub[$department] ?? 0) + ($overdue[$id] ?? 0);
            $inactiveBySub[$department] = ($inactiveBySub[$department] ?? 0) + (($last[$id] ?? null) === null || $last[$id] < $silentBefore ? 1 : 0);
        }

        return [
            'overrides'         => (new GradeOverrideRequestService())->pendingCountForDean($dean),
            'signoff_ready'     => $signoff['ready'],
            'signoff_blocked'   => $signoff['blocked'],
            'followups_overdue' => (new FollowUpService())->openCounts($dean, $now->format('Y-m-d'))['sent_overdue'],
            'overdue_grading'   => $this->byDepartment($dean, $overdueBySub),
            'inactive_courses'  => $this->byDepartment($dean, $inactiveBySub),
            'at_risk'           => count($this->monitor->atRisk($ids)),
        ];
    }

    /**
     * The college in numbers.
     *
     * @param list<int>                  $programIds
     * @param list<array<string, mixed>> $running
     *
     * @return array{departments: int, programs: int, courses: int, instructors: int, students: int}
     */
    private function snapshot(int $collegeId, array $programIds, array $running): array
    {
        $db  = db_connect();
        $ids = array_map(static fn (array $r): int => (int) $r['offering_id'], $running);

        return [
            'departments' => $db->table('departments')->where('college_id', $collegeId)->countAllResults(),
            'programs'        => count($programIds),
            'courses'         => count($running),
            'instructors'     => $ids === [] ? 0 : $db->table('instructor_assignments')->select('instructor_user_id')->whereIn('offering_id', $ids)->distinct()->countAllResults(),
            'students'        => (new EnrollmentModel())->retrieveEnrolledCountForOfferings($ids),
        ];
    }

    /**
     * What chairpersons changed lately and how much the dean has yet to look at.
     *
     * @return array{to_review: int, entries: list<array<string, mixed>>}
     */
    private function chairpersonActivity(User $dean): array
    {
        $review = new ChairpersonReviewService();

        return [
            'to_review' => $review->pendingCount($dean),
            'entries'   => $review->entries($dean, new ChairpersonReviewFilters(view: 'all'), 1, self::HIGHLIGHTS)['rows'],
        ];
    }

    /**
     * Counts per department as rows with the department's name, for the college's own
     * departments only.
     *
     * @param array<int, int> $counts department id => count
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    private function byDepartment(User $dean, array $counts): array
    {
        $college = $this->access->collegeFor($dean);
        $names      = $college === null ? [] : array_column(db_connect()->table('departments')->select(['id', 'name'])->where('college_id', (int) $college['college_id'])->get()->getResultArray(), 'name', 'id');

        $rows = [];

        foreach ($counts as $id => $count) {
            if (isset($names[$id])) {
                $rows[] = ['id' => (int) $id, 'name' => (string) $names[$id], 'count' => $count];
            }
        }

        return $rows;
    }
}

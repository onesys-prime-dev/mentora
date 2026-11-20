<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Controllers\BaseController;
use App\Services\Courses\CourseAbilities;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\GroupLimits;
use App\Services\Groups\GroupMembershipService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The course Groups tab (screen 1): the offering's active group sets, as HTML for the course page to swap in.
 * The set detail is drawn by the page's script from GroupSetsController::show.
 */
class GroupsTabController extends BaseController
{
    public function show(int $offeringId): ResponseInterface
    {
        $sets = db_connect()->query(
            'SELECT s.id, s.name, s.formation_mode, s.min_size, s.max_size,
                (SELECT COUNT(*) FROM student_groups g WHERE g.set_id = s.id) AS group_count,
                (SELECT COUNT(*) FROM student_group_members m WHERE m.set_id = s.id) AS member_count,
                (SELECT COUNT(*) FROM student_groups g WHERE g.set_id = s.id
                    AND (SELECT COUNT(*) FROM student_group_members m WHERE m.group_id = g.id) < s.min_size) AS below_min
             FROM group_sets s WHERE s.offering_id = ? AND s.status = ? ORDER BY s.id DESC',
            [$offeringId, 'active'],
        )->getResultArray();

        $access = self::access($offeringId);

        return $this->response->setJSON(['html' => view('pages/courses/features/groups/index', [
            'groupSets'   => $sets,
            'poolSize'    => count((new GroupMembershipService())->poolForOffering($offeringId)),
            'maxSets'     => GroupLimits::MAX_SETS_PER_OFFERING,
            // The page draws no write control unless this is true; the server refuses anyway.
            'canEdit'     => $access['canEdit'],
            'lockReason'  => $access['lockReason'],
        ])]);
    }

    /**
     * Whether the person may change the offering's groups here, and if not, why. Shared with ActivityGroupsController.
     *
     * @return array{canEdit: bool, finalized: bool, lockReason: string}
     */
    public static function access(int $offeringId): array
    {
        $finalized = (new GradeFinalizationService())->isFinalized($offeringId);
        $closed    = db_connect()->table('course_offerings')->where('id', $offeringId)->where('status !=', 'active')->countAllResults() > 0;
        $viewOnly  = CourseAbilities::viewOnly(auth()->user(), 'content');

        return [
            'canEdit'    => ! $viewOnly && ! $finalized && ! $closed,
            'finalized'  => $finalized,
            'lockReason' => match (true) {
                $finalized => 'The grades are finalized, so groups cannot be changed. Reopen the grades first.',
                $viewOnly  => 'View only. The course\'s instructor or chairperson manages its groups.',
                $closed    => 'This course is closed, so its groups cannot be changed.',
                default    => '',
            },
        ];
    }
}

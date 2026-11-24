<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;

/** Turns (viewer, subject id) into the ViewContext ProfilePolicy needs. Roles come from Shield groups, never active_role. */
class ProfileContextResolver
{
    public function for(User $viewer, int $subjectId): ?ViewContext
    {
        $db      = db_connect();
        $subject = $db->table('users')->select('id, active')->where('id', $subjectId)->where('deleted_at', null)->get()->getRowArray();

        if ($subject === null) {
            return null;
        }

        $viewerId     = (int) $viewer->id;
        $viewerRoles  = $this->groups($viewerId);
        $subjectRoles = $this->groups($subjectId);
        $planLimits   = new PlanLimits();
        $viewerInst   = $planLimits->institutionOf($viewerId);
        $subjectInst  = $planLimits->institutionOf($subjectId);

        // Same notion of deactivated as AccountControl: switched off by Shield or by an administrator.
        $deactivated = $db->table('user_account_controls')->where('user_id', $subjectId)->where('deactivated_at IS NOT NULL', null, false)->countAllResults() > 0;

        return new ViewContext(
            isSelf: $viewerId === $subjectId,
            sameInstitution: $viewerInst !== null && $viewerInst === $subjectInst,
            viewerRank: RoleRank::highest($viewerRoles),
            subjectRoles: RoleRank::sort($subjectRoles),
            subjectActive: (int) $subject['active'] === 1 && ! $deactivated,
            viewerIsParentOfSubject: $this->linked($viewerId, $subjectId),
            viewerIsChildOfSubject: $this->linked($subjectId, $viewerId),
            viewerIsInstitutionAdmin: in_array('institutional_admin', $viewerRoles, true),
            subjectIsSystemAdmin: in_array('admin', $subjectRoles, true),
        );
    }

    /** @return list<string> */
    private function groups(int $userId): array
    {
        return array_column(db_connect()->table('auth_groups_users')->select('group')->where('user_id', $userId)->get()->getResultArray(), 'group');
    }

    private function linked(int $parentId, int $studentId): bool
    {
        return db_connect()->table('parent_student_links')->where('parent_user_id', $parentId)->where('student_user_id', $studentId)->countAllResults() > 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Parents\ParentAccessService;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;

/** Turns (viewer, subject id) into the ViewContext ProfilePolicy needs. Roles come from Shield groups, never active_role. */
class ProfileContextResolver
{
    public function for(User $viewer, int $subjectId): ?ViewContext
    {
        return $this->contexts($viewer, [$subjectId])[$subjectId] ?? null;
    }

    /**
     * The subjects for whom ProfilePolicy::fields(for($viewer, $id)) is not empty, in the order given.
     *
     * @param list<int> $subjectIds
     *
     * @return list<int>
     */
    public function visibleIds(User $viewer, array $subjectIds): array
    {
        $policy = new ProfilePolicy();

        return array_keys(array_filter($this->contexts($viewer, $subjectIds), static fn (ViewContext $c): bool => $policy->fields($c) !== []));
    }

    /**
     * for() for many subjects in a fixed number of queries, whatever the list size.
     *
     * @param list<int> $subjectIds
     *
     * @return array<int, ViewContext> by subject id, in the order given; missing or deleted subjects are left out
     */
    public function contexts(User $viewer, array $subjectIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $subjectIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $db     = db_connect();
        $active = array_column($db->table('users')->select('id, active')->whereIn('id', $ids)->where('deleted_at', null)->get()->getResultArray(), 'active', 'id');
        $ids    = array_values(array_filter($ids, static fn (int $id): bool => isset($active[$id])));
        if ($ids === []) {
            return [];
        }

        $viewerId = (int) $viewer->id;
        $people   = array_values(array_unique([$viewerId, ...$ids]));
        $groups   = array_fill_keys($people, []);
        foreach ($db->table('auth_groups_users')->select('user_id, group')->whereIn('user_id', $people)->get()->getResultArray() as $row) {
            $groups[(int) $row['user_id']][] = $row['group'];
        }
        $institutions = (new PlanLimits())->institutionsOf($people);
        $viewerInst   = $institutions[$viewerId] ?? null;

        // Same notion of deactivated as AccountControl: switched off by Shield or by an administrator.
        $deactivated = array_flip(array_map('intval', array_column(
            $db->table('user_account_controls')->select('user_id')->whereIn('user_id', $ids)->where('deactivated_at IS NOT NULL', null, false)->get()->getResultArray(),
            'user_id',
        )));

        // Live links by ParentAccessService's rules, both ways round.
        $links         = new ParentAccessService();
        $childrenOfMe  = array_flip(array_column($links->liveLinks([$viewerId], $ids), 'student_id'));
        $parentsOfMe   = array_flip(array_column($links->liveLinks($ids, [$viewerId]), 'parent_id'));
        $viewerRoles   = $groups[$viewerId];

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = new ViewContext(
                isSelf: $viewerId === $id,
                sameInstitution: $viewerInst !== null && $viewerInst === ($institutions[$id] ?? null),
                viewerRank: RoleRank::highest($viewerRoles),
                subjectRoles: RoleRank::sort($groups[$id]),
                subjectActive: (int) $active[$id] === 1 && ! isset($deactivated[$id]),
                viewerIsParentOfSubject: isset($childrenOfMe[$id]),
                viewerIsChildOfSubject: isset($parentsOfMe[$id]),
                viewerIsInstitutionAdmin: in_array('institutional_admin', $viewerRoles, true),
                subjectIsSystemAdmin: in_array('admin', $groups[$id], true),
            );
        }

        return $out;
    }
}

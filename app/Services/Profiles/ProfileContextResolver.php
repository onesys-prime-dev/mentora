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
        return $this->build([(int) $viewer->id], $subjectIds)[(int) $viewer->id] ?? [];
    }

    /**
     * visibleIds() the other way round: of these viewers, those who may see the subject, in the order given.
     * One fixed set of queries for the whole list (a notification's recipients, for instance).
     *
     * @param list<int> $viewerIds
     *
     * @return list<int>
     */
    public function viewersWhoSee(int $subjectId, array $viewerIds): array
    {
        $policy = new ProfilePolicy();
        $out    = [];

        foreach ($this->build($viewerIds, [$subjectId]) as $viewerId => $contexts) {
            if (isset($contexts[$subjectId]) && $policy->fields($contexts[$subjectId]) !== []) {
                $out[] = $viewerId;
            }
        }

        return $out;
    }

    /**
     * Every viewer's ViewContext of every subject, from one set of queries over all of them.
     *
     * @param list<int> $viewerIds
     * @param list<int> $subjectIds
     *
     * @return array<int, array<int, ViewContext>> by viewer id, then subject id, both in the order given; missing or deleted subjects are left out
     */
    private function build(array $viewerIds, array $subjectIds): array
    {
        $clean   = static fn (array $list): array => array_values(array_unique(array_filter(array_map('intval', $list), static fn (int $id): bool => $id > 0)));
        $ids     = $clean($subjectIds);
        $viewers = $clean($viewerIds);
        if ($ids === [] || $viewers === []) {
            return [];
        }

        $db     = db_connect();
        $active = array_column($db->table('users')->select('id, active')->whereIn('id', $ids)->where('deleted_at', null)->get()->getResultArray(), 'active', 'id');
        $ids    = array_values(array_filter($ids, static fn (int $id): bool => isset($active[$id])));
        if ($ids === []) {
            return [];
        }

        $people = array_values(array_unique([...$viewers, ...$ids]));
        $groups = array_fill_keys($people, []);
        foreach ($db->table('auth_groups_users')->select('user_id, group')->whereIn('user_id', $people)->get()->getResultArray() as $row) {
            $groups[(int) $row['user_id']][] = $row['group'];
        }
        $institutions = (new PlanLimits())->institutionsOf($people);

        // Same notion of deactivated as AccountControl: switched off by Shield or by an administrator.
        $deactivated = array_flip(array_map('intval', array_column(
            $db->table('user_account_controls')->select('user_id')->whereIn('user_id', $ids)->where('deactivated_at IS NOT NULL', null, false)->get()->getResultArray(),
            'user_id',
        )));

        // Live links by ParentAccessService's rules, both ways round, as "viewer:subject" pairs.
        $links    = new ParentAccessService();
        $parentOf = array_flip(array_map(static fn (array $l): string => $l['parent_id'] . ':' . $l['student_id'], $links->liveLinks($viewers, $ids)));
        $childOf  = array_flip(array_map(static fn (array $l): string => $l['student_id'] . ':' . $l['parent_id'], $links->liveLinks($ids, $viewers)));

        $out = [];
        foreach ($viewers as $viewerId) {
            $viewerRoles = $groups[$viewerId];
            $viewerInst  = $institutions[$viewerId] ?? null;

            foreach ($ids as $id) {
                $out[$viewerId][$id] = new ViewContext(
                    isSelf: $viewerId === $id,
                    sameInstitution: $viewerInst !== null && $viewerInst === ($institutions[$id] ?? null),
                    viewerRank: RoleRank::highest($viewerRoles),
                    subjectRoles: RoleRank::sort($groups[$id]),
                    subjectActive: (int) $active[$id] === 1 && ! isset($deactivated[$id]),
                    viewerIsParentOfSubject: isset($parentOf[$viewerId . ':' . $id]),
                    viewerIsChildOfSubject: isset($childOf[$viewerId . ':' . $id]),
                    viewerIsInstitutionAdmin: in_array('institutional_admin', $viewerRoles, true),
                    subjectIsSystemAdmin: in_array('admin', $groups[$id], true),
                );
            }
        }

        return $out;
    }
}

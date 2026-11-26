<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Admin\Impersonation;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Notifications\NotificationService;
use App\Services\Profiles\AvatarData;
use App\Services\Profiles\RoleRank;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Throwable;

/**
 * Owner-run groups (FR7): anyone but a parent starts a group and runs it; an institution administrator can do
 * whatever the owner can. Every member goes through the same facts `canStart` uses, from the person adding, so who a
 * group may hold can never drift from who a direct message may reach. Changes to one group (adds, removals, leaving,
 * handover, closing) lock its row first, so two people acting at once, or someone leaving while ownership moves,
 * leave it consistent and never past MAX_MEMBERS.
 *
 * Whatever a refused member's reason (block, minor, restriction, scope), the caller only learns "refused".
 */
class GroupService
{
    /** Members including the owner. */
    public const MAX_MEMBERS = 50;

    public const MAX_TITLE = 80;

    private const MIN_QUERY = 2;

    /** Names scanned before the scope narrows them; the scope is the filter, not the limit. */
    private const CANDIDATES = 200;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    public function __construct(?MessagingContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = new MessagingPolicy();
    }

    /**
     * People the creator may add whose name matches, never the creator.
     *
     * @return list<array{id: int, name: string, role: string, avatar: array<string, mixed>|null}>
     */
    public function candidates(User $creator, string $query, int $limit = 20): array
    {
        $query = trim($query);
        $party = $this->actorParty($creator);

        if ($party === null || mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        $db    = db_connect();
        $like  = $db->escape('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%');
        $names = $db->table('institution_users iu')->select('up.user_id, up.first_name, up.last_name')
            ->join('user_profiles up', 'up.user_id = iu.user_id')
            ->where('iu.institution_id', $party->institutionId)->where('iu.user_id !=', (int) $creator->id)
            ->where("(up.first_name LIKE {$like} OR up.last_name LIKE {$like} OR CONCAT(up.first_name, ' ', up.last_name) LIKE {$like})", null, false)
            ->orderBy('up.last_name')->orderBy('up.first_name')->limit(self::CANDIDATES)->get()->getResultArray();

        $users = model(UserModel::class);
        $found = [];

        foreach ($names as $row) {
            $member = $users->findById((int) $row['user_id']);

            if ($member === null || ! $this->reachable($creator, $party, $member)) {
                continue;
            }

            $found[] = ['id' => (int) $member->id, 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'role' => RoleRank::highest($this->resolver->roles($member)) ?? ''];

            if (count($found) >= $limit) {
                break;
            }
        }

        // Shown as the creator may see each person; matched above on the legal name only.
        $avatars = (new AvatarData())->forViewerJson($creator, array_column($found, 'id'));

        return array_map(static fn (array $f): array => ['id' => $f['id'], 'name' => $avatars[$f['id']]['name'] ?? $f['name'], 'role' => $f['role'], 'avatar' => $avatars[$f['id']] ?? null], $found);
    }

    /**
     * The new group as a `conversations` row, or null when the creator may not start groups, the title or the
     * member list is not acceptable, or any member is someone they may not add. Nothing is created on a null.
     *
     * @throws RateLimitedException when the creator has started too many new threads this hour
     *
     * @param list<int|string> $memberIds
     *
     * @return array<string, mixed>|null
     */
    public function create(User $creator, string $title, array $memberIds): ?array
    {
        $party = $this->actorParty($creator);
        $title = self::cleanTitle($title);

        if ($party === null || $title === null) {
            return null;
        }

        $ids = self::cleanIds($memberIds, (int) $creator->id);

        if ($ids === [] || count($ids) + 1 > self::MAX_MEMBERS) {
            return null;
        }

        $users    = model(UserModel::class);
        $everyone = [(int) $creator->id => $creator];
        $parties  = [(int) $creator->id => $party];

        foreach ($ids as $id) {
            $member = $users->findById($id);
            $mp     = $member === null ? null : $this->resolver->party($member);

            if ($member === null || ! $this->reachable($creator, $party, $member, $mp)) {
                return null;
            }

            $everyone[$id] = $member;
            $parties[$id]  = $mp;
        }

        if (! $this->minorsAllowed($everyone, $parties, $ids)) {
            return null;
        }

        (new MessageRateLimiter())->check('thread', (int) $creator->id, null, $party);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->transStart();
        $db->table('conversations')->insert([
            'institution_id' => $party->institutionId, 'type' => 'group', 'title' => $title, 'created_by' => (int) $creator->id, 'owner_id' => (int) $creator->id,
            'is_locked' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();

        foreach ([(int) $creator->id, ...$ids] as $userId) {
            $db->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $userId, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $group = $db->table('conversations')->where('id', $id)->get()->getRowArray();
        $this->note($group, 'group_created', $creator, (int) $creator->id, null);

        foreach ($ids as $userId) {
            $this->tell($group, $userId, $now);
        }

        $db->transComplete();

        return $db->transStatus() ? $group : null;
    }

    /**
     * The group as its settings dialog shows it, for an active member or an administrator of its institution; null for
     * anyone else (and for a conversation that is not a group). Names are as this viewer may see them (FR5).
     *
     * @return array{title: string, closed: bool, can_manage: bool, max: int, members: list<array{id: int, name: string, owner: bool, me: bool, avatar: array<string, mixed>|null}>}|null
     */
    public function members(User $viewer, int $conversationId): ?array
    {
        $c = db_connect()->table('conversations')->where(['id' => $conversationId, 'type' => 'group'])->get()->getRowArray();

        if ($c === null || $this->resolver->institutionOf($viewer) !== (int) $c['institution_id']) {
            return null;
        }

        $ids   = $this->activeMemberIds($conversationId);
        $admin = in_array('institutional_admin', $this->resolver->roles($viewer), true);

        if (! $admin && ! in_array((int) $viewer->id, $ids, true)) {
            return null;
        }

        $avatars = (new AvatarData())->forViewerJson($viewer, $ids);

        return [
            'title'      => (string) $c['title'],
            'closed'     => $c['closed_at'] !== null,
            'can_manage' => $this->mayManage($viewer, $c),
            'max'        => self::MAX_MEMBERS,
            'members'    => array_map(static fn (int $id): array => [
                'id' => $id, 'name' => $avatars[$id]['name'] ?? 'Someone', 'owner' => (int) $c['owner_id'] === $id, 'me' => $id === (int) $viewer->id, 'avatar' => $avatars[$id] ?? null,
            ], $ids),
        ];
    }

    /**
     * The membership lines of a group for one member, oldest first, from the time they joined (and within
     * `$from`..`$to`, both inclusive and optional, as `Y-m-d H:i:s`), or only those with an id above `$afterId` when
     * that is given (a poll that already holds the earlier ones). At most the newest 200. Callers merge them by id, so a
     * line delivered twice is harmless. Names are as this viewer may see them, looked up once for the batch; the
     * caller has already checked the viewer is a member.
     *
     * @return list<array{id: int, event: string, actor_id: int|null, target_id: int|null, actor_name: string|null, target_name: string|null, created_at: string}>
     */
    public function events(User $viewer, int $conversationId, ?string $from = null, ?string $to = null, ?int $afterId = null): array
    {
        $db     = db_connect();
        $joined = (string) ($db->table('conversation_participants')->select('joined_at')->where(['conversation_id' => $conversationId, 'user_id' => (int) $viewer->id])->get()->getRow('joined_at') ?? '');
        $q      = $db->table('conversation_events')->where('conversation_id', $conversationId)->where('created_at >=', $from === null || $from < $joined ? $joined : $from);

        if ($afterId !== null) {
            $q->where('id >', $afterId);
        }

        if ($to !== null) {
            $q->where('created_at <=', $to);
        }

        // The newest 200, shown oldest first.
        $rows = array_reverse($q->orderBy('id', 'DESC')->limit(200)->get()->getResultArray());

        if ($rows === []) {
            return [];
        }

        $ids  = array_values(array_unique(array_filter(array_map('intval', [...array_column($rows, 'actor_id'), ...array_column($rows, 'target_id')]))));
        $name = (new AvatarData())->forViewerJson($viewer, $ids);

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'], 'event' => (string) $r['event'],
            'actor_id'   => $r['actor_id'] === null ? null : (int) $r['actor_id'], 'target_id' => $r['target_id'] === null ? null : (int) $r['target_id'],
            'actor_name' => $r['actor_id'] === null ? null : ($name[(int) $r['actor_id']]['name'] ?? 'Someone'), 'target_name' => $r['target_id'] === null ? null : ($name[(int) $r['target_id']]['name'] ?? 'Someone'),
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Adds people to a group. Each is checked alone (a person who passes is added even if another is refused); the
     * list says which, with no reason. Everyone is refused when the actor may not change this group.
     *
     * @param list<int|string> $memberIds
     *
     * @return array{added: list<int>, refused: list<int>}
     */
    public function add(User $actor, int $conversationId, array $memberIds): array
    {
        $ids  = self::cleanIds($memberIds, (int) $actor->id);
        $none = ['added' => [], 'refused' => $ids];
        $ap   = $this->actorParty($actor);

        if ($ap === null || $ids === []) {
            return $none;
        }

        // What depends only on the actor and the candidate is read before the group is locked, so sends to it never
        // wait on those lookups. The cap, the membership and the minor rule need the group as it is, and stay inside.
        $users    = model(UserModel::class);
        $prepared = [];

        foreach ($ids as $id) {
            $member = $users->findById($id);
            $party  = $member === null ? null : $this->resolver->party($member);

            if ($member !== null && $this->reachable($actor, $ap, $member, $party)) {
                $prepared[$id] = [$member, $party];
            }
        }

        return $this->locked($conversationId, function (array $c) use ($actor, $ids, $none, $users, $prepared): array {
            if (! $this->mayManage($actor, $c)) {
                return $none;
            }

            $result    = ['added' => [], 'refused' => []];
            $db        = db_connect();
            $current   = [];
            $memberIds = $this->activeMemberIds((int) $c['id']);

            foreach ($memberIds as $memberId) {
                $user = $users->findById($memberId);

                if ($user !== null) {
                    $current[$memberId] = $user;
                }
            }

            // Parties of the current members are built only once a minor is in play (D26), then once each.
            $parties      = null;
            $minorInGroup = $memberIds !== [] && $db->table('institution_users')->whereIn('user_id', $memberIds)->where('is_minor', 1)->countAllResults() > 0;

            $now    = date('Y-m-d H:i:s');
            $latest = (int) ($db->table('messages')->selectMax('id', 'last')->where('conversation_id', (int) $c['id'])->get()->getRow('last') ?? 0);

            foreach ($ids as $id) {
                if (isset($current[$id])) {
                    continue;
                }

                [$member, $party] = $prepared[$id] ?? [null, null];

                if ($member === null || count($current) >= self::MAX_MEMBERS) {
                    $result['refused'][] = $id;

                    continue;
                }

                if ($minorInGroup || $party->minor) {
                    $parties ??= array_map(fn (User $u): Party => $this->resolver->party($u), $current);

                    if (! $this->minorsAllowed($current + [$id => $member], $parties + [$id => $party], [$id])) {
                        $result['refused'][] = $id;

                        continue;
                    }

                    $parties[$id] = $party;
                    $minorInGroup = $minorInGroup || $party->minor;
                }

                // New members see from their join time on: nothing sent before is theirs.
                $row = ['joined_at' => $now, 'left_at' => null, 'first_visible_message_id' => $latest > 0 ? $latest + 1 : 0, 'last_read_message_id' => null, 'last_notified_message_id' => null];
                $key = ['conversation_id' => (int) $c['id'], 'user_id' => $id];

                if ($db->table('conversation_participants')->where($key)->countAllResults() > 0) {
                    $db->table('conversation_participants')->where($key)->update($row);
                } else {
                    $db->table('conversation_participants')->insert($row + $key);
                }

                $current[$id]      = $member;
                $result['added'][] = $id;
                $this->tell($c, $id, $now, $this->note($c, 'member_added', $actor, (int) $actor->id, $id));
            }

            return $result;
        }) ?? $none;
    }

    /** Removes one member. The owner uses leave(); an administrator removing the owner passes ownership on. */
    public function remove(User $actor, int $conversationId, int $memberId): bool
    {
        return $memberId !== (int) $actor->id && $this->locked($conversationId, function (array $c) use ($actor, $memberId): bool {
            if (! $this->mayManage($actor, $c) || ! in_array($memberId, $this->activeMemberIds((int) $c['id']), true)) {
                return false;
            }

            $this->endMembership($c, $memberId, 'member_removed', $actor);

            return true;
        }) === true;
    }

    /** Any member may leave; the owner leaving passes ownership on, and a group down to one member closes. */
    public function leave(User $user, int $conversationId): bool
    {
        return $this->locked($conversationId, function (array $c) use ($user): bool {
            if (! $this->mayAct($user, $c) || ! in_array((int) $user->id, $this->activeMemberIds((int) $c['id']), true)) {
                return false;
            }

            $this->endMembership($c, (int) $user->id, 'member_left', $user);

            return true;
        }) === true;
    }

    public function rename(User $actor, int $conversationId, string $title): bool
    {
        $title = self::cleanTitle($title);

        return $title !== null && $this->locked($conversationId, function (array $c) use ($actor, $title): bool {
            if (! $this->mayManage($actor, $c)) {
                return false;
            }

            db_connect()->table('conversations')->where('id', (int) $c['id'])->update(['title' => $title, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->note($c, 'renamed', $actor, (int) $actor->id, null);

            return true;
        }) === true;
    }

    /** Closes a group: archived and read-only for everyone, never erased. */
    public function close(User $actor, int $conversationId): bool
    {
        return $this->locked($conversationId, function (array $c) use ($actor): bool {
            if (! $this->mayManage($actor, $c)) {
                return false;
            }

            $this->closeRow($c, $actor);

            return true;
        }) === true;
    }

    /** A deactivated person no longer owns groups: ownership passes on, the account stays a member. `$by` is who deactivated them, for the audit trail. */
    public function handOverFor(int $userId, ?User $by = null): void
    {
        $ids = array_column(db_connect()->table('conversations')->select('id')->where(['type' => 'group', 'owner_id' => $userId, 'closed_at' => null])->get()->getResultArray(), 'id');

        foreach ($ids as $id) {
            $this->locked((int) $id, function (array $c) use ($userId, $by): bool {
                if ((int) $c['owner_id'] === $userId && $c['closed_at'] === null) {
                    $this->passOwnership($c, $userId, $by);
                }

                return true;
            });
        }
    }

    /**
     * Runs $fn with the group row locked inside a transaction; its result, or null when there is no such group or
     * the transaction failed.
     *
     * @param callable(array<string, mixed>): mixed $fn
     */
    private function locked(int $conversationId, callable $fn): mixed
    {
        $db = db_connect();
        $db->transStart();

        try {
            $c      = $db->query("SELECT * FROM conversations WHERE id = ? AND type = 'group' FOR UPDATE", [$conversationId])->getRowArray();
            $result = $c === null ? null : $fn($c);
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transComplete();

        return $db->transStatus() ? $result : null;
    }

    /** The person's party when they may start groups and add people: active, in an institution, not impersonated, not restricted. */
    private function actorParty(User $user): ?Party
    {
        if (Impersonation::active()) {
            return null;
        }

        $party = $this->resolver->party($user);

        // BC-5: any restriction or pause stops creating groups and adding members.
        if ($party->institutionId === null || ! $party->active || $party->capacity(false) === null || $this->resolver->restrictionKind((int) $user->id) !== 'none' || ! (new InstitutionAccess())->canWrite((int) $user->id)) {
            return null;
        }

        return $party;
    }

    /**
     * Whether the person may change this group now: it is open, in their institution, writable, and their account active.
     *
     * @param array<string, mixed> $c
     */
    private function mayAct(User $user, array $c): bool
    {
        if (Impersonation::active() || $c['closed_at'] !== null || $this->resolver->institutionOf($user) !== (int) $c['institution_id']) {
            return false;
        }

        $party = $this->resolver->party($user);

        return $party->active && ! $party->isSystemAdmin() && (new InstitutionAccess())->canWrite((int) $user->id);
    }

    /**
     * The owner, or an administrator of the group's institution, and only while the group is open.
     *
     * @param array<string, mixed> $c
     */
    private function mayManage(User $actor, array $c): bool
    {
        if (! $this->mayAct($actor, $c)) {
            return false;
        }

        if (in_array('institutional_admin', $this->resolver->roles($actor), true)) {
            return true;
        }

        return (int) $c['owner_id'] === (int) $actor->id && in_array((int) $actor->id, $this->activeMemberIds((int) $c['id']), true);
    }

    /** FR1 from the actor to the member (which includes a block from the member), for someone who can be in a group at all. */
    private function reachable(User $actor, Party $actorParty, User $member, ?Party $party = null): bool
    {
        $party ??= $this->resolver->party($member);

        if ($party->capacity(false) === null) {
            return false;
        }

        $context = $this->resolver->forStart($actor, $member, null, $actorParty, $party);

        return $context !== null && $this->policy->canStart($actorParty, $party, null, $context);
    }

    /**
     * D26: for every minor in the group, every other person must be able to reach them, checked for each pair where
     * one of the two is new. Pairs that are both already in the group are never re-checked. Only the relationship
     * facts count (same institution, a role pair the table allows, the shared course, college, scope or linked child,
     * the student switch). Not counted: whether either account is active, a restriction or pause, and a block (D26
     * checks blocks against the person adding only). So a member's account state never freezes a group.
     *
     * @param array<int, User>  $everyone the group as it would be, by id
     * @param array<int, Party> $parties  their parties, by id
     * @param list<int>         $newIds   who is being added
     */
    private function minorsAllowed(array $everyone, array $parties, array $newIds): bool
    {
        foreach ($everyone as $minorId => $minor) {
            if (! $parties[$minorId]->minor) {
                continue;
            }

            if ($parties[$minorId]->capacity(false) === null) {
                return false;
            }

            foreach ($everyone as $otherId => $other) {
                if ($otherId === $minorId || (! in_array($otherId, $newIds, true) && ! in_array($minorId, $newIds, true))) {
                    continue;
                }

                $context = $this->resolver->forStart($other, $minor, null, $parties[$otherId], $parties[$minorId]);

                if ($context === null || ! $this->policy->canStart($parties[$otherId], $parties[$minorId], null, $context->relationshipOnly())) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<int> active members, longest-standing first */
    private function activeMemberIds(int $conversationId): array
    {
        $rows = db_connect()->table('conversation_participants')->select('user_id')->where(['conversation_id' => $conversationId, 'left_at' => null])->orderBy('joined_at')->orderBy('id')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'user_id'));
    }

    /**
     * Ends one membership, then passes ownership on if it was theirs and closes a group down to one member.
     *
     * @param array<string, mixed> $c
     */
    private function endMembership(array $c, int $memberId, string $event, User $actor): void
    {
        db_connect()->table('conversation_participants')->where(['conversation_id' => (int) $c['id'], 'user_id' => $memberId, 'left_at' => null])->update(['left_at' => date('Y-m-d H:i:s')]);
        $this->note($c, $event, $actor, (int) $actor->id, $memberId);

        if ((int) $c['owner_id'] === $memberId) {
            $this->passOwnership($c, $memberId, $actor);
        }

        if (count($this->activeMemberIds((int) $c['id'])) <= 1) {
            $this->closeRow($c, null);
        }
    }

    /** @param array<string, mixed> $c */
    private function closeRow(array $c, ?User $actor): void
    {
        $now = date('Y-m-d H:i:s');

        db_connect()->table('conversations')->where('id', (int) $c['id'])->update(['closed_at' => $now, 'updated_at' => $now]);
        $this->note($c, 'closed', $actor, $actor === null ? null : (int) $actor->id, null);
    }

    /**
     * The longest-standing member holding a staff role, else the longest-standing member (active accounts first).
     *
     * @param array<string, mixed> $c
     */
    private function passOwnership(array $c, int $fromId, ?User $actor): void
    {
        $users  = model(UserModel::class);
        $staff  = null;
        $anyone = null;
        $first  = null;

        foreach (array_diff($this->activeMemberIds((int) $c['id']), [$fromId]) as $memberId) {
            $user = $users->findById($memberId);

            if ($user === null) {
                continue;
            }

            $party  = $this->resolver->party($user);
            $first ??= $memberId;

            if (! $party->active) {
                continue;
            }

            $anyone ??= $memberId;

            if ($party->isStaff()) {
                $staff = $memberId;

                break;
            }
        }

        $next = $staff ?? $anyone ?? $first;

        if ($next === null) {
            return;
        }

        db_connect()->table('conversations')->where('id', (int) $c['id'])->update(['owner_id' => $next]);
        $this->note($c, 'owner_changed', $actor, $fromId, $next);
    }

    /**
     * One system line for the group and one audit entry: who did what to whom, ids only (D22).
     *
     * @param array<string, mixed> $c
     */
    private function note(array $c, string $event, ?User $auditActor, ?int $actorId, ?int $targetId): int
    {
        $db = db_connect();
        $db->table('conversation_events')->insert(['conversation_id' => (int) $c['id'], 'event' => $event, 'actor_id' => $actorId, 'target_id' => $targetId, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $db->insertID();

        service('auditLogger')->log('messages.' . $event, $auditActor, [
            'target_type' => 'conversation', 'target_id' => (int) $c['id'], 'institution_id' => (int) $c['institution_id'],
            'new'         => array_filter(['actor_id' => $actorId, 'user_id' => $targetId], static fn ($v): bool => $v !== null),
        ]);

        return $id;
    }

    /**
     * Being added is told at once, in the app, without names (S2-g).
     *
     * @param array<string, mixed> $c
     */
    private function tell(array $c, int $userId, string $now, int $eventId = 0): void
    {
        (new NotificationService())->send(
            $userId,
            'message',
            'Added to a group',
            'You were added to the group ' . (string) $c['title'] . '.',
            false,
            "group-add:{$c['id']}:{$userId}:{$eventId}:" . strtotime($now),
            (int) $c['institution_id'],
            'messages/' . $c['id'],
        );
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int>
     */
    private static function cleanIds(array $ids, int $except): array
    {
        return array_values(array_diff(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)), [$except]));
    }

    /** One line of plain text, 1 to MAX_TITLE characters, or null. */
    private static function cleanTitle(string $title): ?string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);

        if ($clean === null) {
            return null;
        }

        $clean = trim($clean);

        return $clean === '' || mb_strlen($clean) > self::MAX_TITLE ? null : $clean;
    }
}

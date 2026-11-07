<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Staff groups (spec 3.2): a small group chat that a chairperson (the
 * instructors of their department) or a dean (the instructors and
 * chairpersons of their college) starts for people in their scope.
 *
 * Every member goes through the same scope facts `canStart` uses, so who a
 * group may hold can never drift from who a direct message may reach. A group
 * is created whole or not at all, and its membership is fixed at creation.
 */
class GroupService
{
    public const MAX_MEMBERS = 30;

    public const MAX_TITLE = 80;

    private const MIN_QUERY = 2;

    /** What each creator role may add. */
    private const MEMBER_ROLES = [
        'chairperson' => ['instructor'],
        'dean'        => ['instructor', 'chairperson'],
    ];

    /** Names scanned before the scope narrows them; the scope is the filter, not the limit. */
    private const CANDIDATES = 200;

    private MessagingContextResolver $resolver;

    public function __construct(?MessagingContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
    }

    /**
     * People the creator may add whose name matches, never the creator.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function candidates(User $creator, string $query, int $limit = 20): array
    {
        $query       = trim($query);
        $role        = $this->resolver->activeRole($creator);
        $institution = $this->resolver->institutionOf($creator);

        if (! isset(self::MEMBER_ROLES[$role]) || $institution === null || mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        $db    = db_connect();
        $like  = $db->escape('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%');
        $names = $db->table('institution_users iu')->select('up.user_id, up.first_name, up.last_name')
            ->join('user_profiles up', 'up.user_id = iu.user_id')
            ->where('iu.institution_id', $institution)->where('iu.user_id !=', (int) $creator->id)
            ->where("(up.first_name LIKE {$like} OR up.last_name LIKE {$like} OR CONCAT(up.first_name, ' ', up.last_name) LIKE {$like})", null, false)
            ->orderBy('up.last_name')->orderBy('up.first_name')->limit(self::CANDIDATES)->get()->getResultArray();

        $users = model(UserModel::class);
        $found = [];

        foreach ($names as $row) {
            $member = $users->findById((int) $row['user_id']);

            if ($member === null || ! $this->allowed($creator, $role, $member)) {
                continue;
            }

            $found[] = ['id' => (int) $member->id, 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'role' => $this->resolver->activeRole($member)];

            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
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
        $role        = $this->resolver->activeRole($creator);
        $institution = $this->resolver->institutionOf($creator);
        $title       = self::cleanTitle($title);

        if (! isset(self::MEMBER_ROLES[$role]) || $institution === null || $title === null) {
            return null;
        }

        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0)), [(int) $creator->id]));

        if ($ids === [] || count($ids) > self::MAX_MEMBERS) {
            return null;
        }

        $users = model(UserModel::class);

        foreach ($ids as $id) {
            $member = $users->findById($id);

            if ($member === null || ! $this->allowed($creator, $role, $member)) {
                return null;
            }
        }

        (new MessageRateLimiter())->check('thread', (int) $creator->id);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->transStart();
        $db->table('conversations')->insert([
            'institution_id' => $institution, 'type' => 'group', 'title' => $title, 'created_by' => (int) $creator->id,
            'is_locked' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();

        foreach ([(int) $creator->id, ...$ids] as $userId) {
            $db->table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $userId, 'joined_at' => $now, 'first_visible_message_id' => 0]);
        }

        $db->transComplete();

        return $db->transStatus() ? $db->table('conversations')->where('id', $id)->get()->getRowArray() : null;
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

    private function allowed(User $creator, string $creatorRole, User $member): bool
    {
        if (! in_array($this->resolver->activeRole($member), self::MEMBER_ROLES[$creatorRole], true)) {
            return false;
        }

        $context = $this->resolver->forStart($creator, $member, null);

        return $context !== null && $context->sameInstitution && $context->inScope && $context->senderActive && $context->recipientActive && ! $context->isSelf;
    }
}

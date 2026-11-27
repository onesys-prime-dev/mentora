<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Parents\ParentAccessService;
use App\Services\Profiles\AvatarData;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * The new-message picker. People are found by first or last name, or by email
 * where the sender may see that email (UP D3: a staff member's for everyone,
 * a student's for staff; S2-e), in the sender's institution. Every candidate is
 * put through the same policy check starting a thread uses, so the list cannot
 * offer anyone a start would refuse. Candidates are read in name order, a batch
 * at a time, until the page is full or the names run out. A parent is offered
 * once per linked child they share with the person, because each child is a
 * separate thread. Names are shown as the sender may see each person
 * (AvatarData::forViewer), but only the legal name is matched, so a hidden
 * preferred name cannot be found by searching.
 */
class RecipientSearch
{
    public const MIN_QUERY = 2;

    /** Names read per query; the policy is the filter, so the search keeps reading until the page is full. */
    private const BATCH = 50;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    public function __construct(?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
    }

    /**
     * @return list<array{id: int, name: string, role: string, subject_student_id: int|null, subject_name: string|null, avatar: array<string, mixed>|null}>
     */
    public function search(User $sender, string $query, int $limit = 20): array
    {
        return $this->page($sender, $query, null, $limit)['recipients'];
    }

    /**
     * One page, starting after the person $after (the last one an earlier page returned). A cursor naming anyone
     * this search would not offer the sender finds nothing, exactly like an unknown one, so a crafted cursor learns
     * nothing about hidden people. `next` is the cursor for the following page, null once the names end.
     * $offer replaces the start check (rows, wanted count => entries), and $membersOnly drops anyone who is neither
     * staff nor a student: how GroupService pages the people a creator may add.
     *
     * @param (callable(list<array<string, mixed>>, int): list<array{id: int, name: string, role: string, subject_student_id: int|null, subject_name: null}>)|null $offer
     *
     * @return array{recipients: list<array{id: int, name: string, role: string, subject_student_id: int|null, subject_name: string|null, avatar: array<string, mixed>|null}>, next: int|null}
     */
    public function page(User $sender, string $query, ?int $after, int $limit = 20, ?callable $offer = null, bool $membersOnly = false): array
    {
        $query       = trim($query);
        $institution = $this->resolver->institutionOf($sender);
        $none        = ['recipients' => [], 'next' => null];

        if (mb_strlen($query) < self::MIN_QUERY || $institution === null) {
            return $none;
        }

        $db          = db_connect();
        $like        = $db->escape('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%');
        $senderParty = $this->resolver->party($sender);

        // UP D3 as ProfilePolicy has it: a staff member's email is visible to all unless they are also a student; a student's to staff.
        $holds   = static fn (string $in): string => "EXISTS (SELECT 1 FROM auth_groups_users g WHERE g.user_id = iu.user_id AND g.`group` IN ({$in}))";
        $staff   = $holds("'" . implode("', '", Party::STAFF_ROLES) . "'");
        $student = $holds("'student'");
        $visible = $senderParty->isStaff() ? "({$staff} OR {$student})" : "({$staff} AND NOT {$student})";
        $email   = "(EXISTS (SELECT 1 FROM auth_identities ai WHERE ai.user_id = iu.user_id AND ai.type = 'email_password' AND ai.secret LIKE {$like}) AND {$visible})";
        $reach   = $this->reachable($sender, $senderParty, $institution, $staff, $student) . ($membersOnly ? " AND ({$staff} OR {$student})" : '');
        $senderId = (int) $sender->id;
        $offer ??= fn (array $rows, int $want): array => $this->offers($sender, $senderParty, $rows, $want);

        // Matches in name order after $from (keyset cursor), optionally only the person $only.
        $read = static function (?array $from, int $n, ?int $only = null) use ($db, $institution, $senderId, $like, $email, $reach): array {
            $builder = $db->table('institution_users iu')->select('up.user_id, up.first_name, up.last_name')
                ->join('user_profiles up', 'up.user_id = iu.user_id')
                ->where('iu.institution_id', $institution)->where('iu.user_id !=', $senderId)
                ->where("(up.first_name LIKE {$like} OR up.last_name LIKE {$like} OR CONCAT(up.first_name, ' ', up.last_name) LIKE {$like} OR {$email})", null, false)
                ->where($reach, null, false);

            if ($only !== null) {
                $builder->where('iu.user_id', $only);
            }

            if ($from !== null) {
                [$last, $first, $id] = [$db->escape($from['last_name']), $db->escape($from['first_name']), (int) $from['user_id']];
                $builder->where("(up.last_name > {$last} OR (up.last_name = {$last} AND (up.first_name > {$first} OR (up.first_name = {$first} AND up.user_id > {$id}))))", null, false);
            }

            return $builder->orderBy('up.last_name')->orderBy('up.first_name')->orderBy('up.user_id')->limit($n)->get()->getResultArray();
        };

        $from = null;

        if ($after !== null) {
            $from = $read(null, 1, $after)[0] ?? null;

            if ($from === null || $offer([$from], 1) === []) {
                return $none;
            }
        }

        $found = [];
        $next  = null;

        do {
            $batch = $read($from, self::BATCH);
            $found = [...$found, ...$offer($batch, $limit - count($found))];

            if (count($found) >= $limit) {
                // The page ended on this person; a cursor only while someone after them still matches.
                $lastId = $found[count($found) - 1]['id'];
                $row    = array_values(array_filter($batch, static fn (array $r): bool => (int) $r['user_id'] === $lastId))[0];
                $next   = $read($row, 1) === [] ? null : $lastId;

                break;
            }

            $from = $batch === [] ? null : $batch[count($batch) - 1];
        } while (count($batch) === self::BATCH);

        $avatars = (new AvatarData())->forViewerJson($sender, array_values(array_unique([...array_column($found, 'id'), ...array_filter(array_column($found, 'subject_student_id'))])));

        foreach ($found as &$entry) {
            $entry['name']         = $avatars[$entry['id']]['name'] ?? $entry['name'];
            $entry['subject_name'] = $entry['subject_student_id'] === null ? null : ($avatars[$entry['subject_student_id']]['name'] ?? null);
            $entry['avatar']       = $avatars[$entry['id']] ?? null;
        }

        return ['recipients' => $found, 'next' => $next];
    }

    /**
     * The entries $rows offer the sender, in order, through the same policy check a start makes, stopping after
     * the person who brings the count to $want. A person's entries stay together (one per child), so the result
     * may run a child or two over.
     *
     * @param list<array<string, mixed>> $rows user_id, first_name, last_name
     *
     * @return list<array{id: int, name: string, role: string, subject_student_id: int|null, subject_name: null}>
     */
    private function offers(User $sender, Party $senderParty, array $rows, int $want): array
    {
        $people = [];

        foreach ($rows === [] ? [] : model(UserModel::class)->whereIn('id', array_map('intval', array_column($rows, 'user_id')))->findAll() as $user) {
            if ($user instanceof User) {
                $people[(int) $user->id] = $user;
            }
        }

        $parties = $this->resolver->parties(array_values($people));
        $found   = [];

        foreach ($rows as $candidate) {
            $recipient = $people[(int) $candidate['user_id']] ?? null;

            if ($recipient === null) {
                continue;
            }

            $recipientParty = $parties[(int) $recipient->id];
            $shared         = $this->resolver->childSubjects($sender, $senderParty, $recipient, $recipientParty);

            foreach ([...($shared ?? []), null] as $subject) {
                $context = $this->resolver->forStart($sender, $recipient, $subject, $senderParty, $recipientParty);

                if ($context === null || ! $this->policy->canStart($senderParty, $recipientParty, $subject, $context)) {
                    continue;
                }

                if ($subject !== null && ! $context->linkedChild()) {
                    continue;
                }

                $found[] = [
                    'id' => (int) $recipient->id, 'name' => trim($candidate['first_name'] . ' ' . $candidate['last_name']), 'role' => $recipientParty->topRole(),
                    'subject_student_id' => $subject, 'subject_name' => null,
                ];
            }

            if (count($found) >= $want) {
                break;
            }
        }

        return $found;
    }

    /**
     * Who the sender could reach at all, as SQL: necessary conditions of FR1 only, so a page never reads through
     * thousands of people no start could reach. The policy still decides each one, so this can hide people but
     * never offer them. Restated here, so a rule change must update it too: an active account (step 1); capacity
     * and the D3 scope (steps 2-3: staff narrow nothing, anyone else reaches staff, a parent about their child,
     * and a student other students inside the scope); a minor only within D9; no binding block (step 4).
     */
    private function reachable(User $sender, Party $party, int $institution, string $staff, string $student): string
    {
        $where = [
            'EXISTS (SELECT 1 FROM users au WHERE au.id = iu.user_id AND au.active = 1 AND au.deleted_at IS NULL)',
            'NOT EXISTS (SELECT 1 FROM user_account_controls ac WHERE ac.user_id = iu.user_id AND ac.deactivated_at IS NOT NULL)',
            'NOT ' . $this->resolver->bindingBlockSql((int) $sender->id, 'iu.user_id'),
        ];
        $minor = $this->resolver->minorReachSql($sender, $party, 'iu.user_id');

        if ($minor !== null) {
            // Only a student carries the flag, as Party::$minor reads it.
            $where[] = "(iu.is_minor = 0 OR NOT {$student} OR {$minor})";
        }

        if ($party->capacity(false) !== 'staff') {
            $classmate = 'EXISTS (SELECT 1 FROM enrollments ea JOIN enrollments eb ON eb.offering_id = ea.offering_id JOIN course_offerings co ON co.id = ea.offering_id'
                . " WHERE co.status = 'active' AND ea.status = 'enrolled' AND eb.status = 'enrolled' AND ea.student_user_id = {$party->id} AND eb.student_user_id = iu.user_id)";
            $peers = match ($party->capacity(false) === 'student' ? (new StudentMessaging())->scope($institution) : 'off') {
                'institution' => $student,
                'course'      => "({$student} AND {$classmate})",
                default       => '1 = 0',
            };
            $where[] = "({$staff} OR {$peers})";
        }

        return '(' . implode(' AND ', $where) . ')';
    }

    /** A parent with no linked child can start no thread at all (S2-i); the picker tells only that person why. */
    public function noLinkedChild(User $sender): bool
    {
        $party = $this->resolver->party($sender);

        return $party->isParent() && $party->capacity(false) === null && (new ParentAccessService())->children($sender) === [];
    }
}

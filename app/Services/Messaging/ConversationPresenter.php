<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Profiles\AvatarData;
use App\Services\Profiles\RoleRank;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Turns the rows ConversationService::listFor returns into what the Messages
 * list shows: a name, initials, a one-line preview of the last message the
 * person can see, and whether they can post. The preview is decrypted here
 * for a participant only; the ciphertext never leaves this class.
 */
class ConversationPresenter
{
    private const PREVIEW = 80;

    private MessageCipher $cipher;

    private MessagingContextResolver $resolver;

    private MessagingPolicy $policy;

    private ChatControls $controls;

    public function __construct(?MessageCipher $cipher = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->cipher   = $cipher ?? new MessageCipher();
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
        $this->controls = new ChatControls($this->resolver, $this->policy);
    }

    /**
     * @param list<array<string, mixed>> $rows from ConversationService::listFor
     *
     * @return list<array<string, mixed>>
     */
    public function present(User $viewer, array $rows): array
    {
        $institution = $this->resolver->institutionOf($viewer);
        $people      = self::names(array_values(array_filter(array_map('intval', [...array_column($rows, 'other_user_id'), ...array_column($rows, 'subject_student_id')]))));
        $courses     = $this->courses(array_values(array_filter(array_map('intval', array_column($rows, 'offering_id')))));
        $viewerParty = $this->resolver->party($viewer);
        $teaching    = $this->teaching((int) $viewer->id, array_values(array_filter(array_map('intval', array_column($rows, 'offering_id')))));
        $blocked     = $this->blockedIds((int) $viewer->id);
        $blockable   = $this->blockTargets($viewer, $institution, $rows, $blocked);
        // One lookup for the list: the other person of each direct chat and each subject student, as this viewer
        // may see them (FR2); their gated names replace the legal ones (FR5).
        $avatars     = (new AvatarData())->forViewerJson($viewer, array_values(array_filter(array_map('intval', [...array_column(array_filter($rows, static fn (array $r): bool => $r['type'] === 'direct'), 'other_user_id'), ...array_column($rows, 'subject_student_id')]))));
        $starters    = $this->starterRoles($viewer, $viewerParty, $rows);
        $out         = [];

        foreach ($rows as $row) {
            // The driver hands integers back as strings; the page compares ids strictly.
            $row['id'] = (int) $row['id'];

            $other   = $row['other_user_id'] === null ? null : (int) $row['other_user_id'];
            $subject = $row['subject_student_id'] === null ? null : (int) $row['subject_student_id'];

            $closed = false;

            if ($row['type'] === 'course') {
                $course = $courses[(int) $row['offering_id']] ?? ['code' => '', 'title' => '', 'closed' => false];
                // By character, not byte: a title ending in a letter whose UTF-8 form ends in the bytes of "·" must survive.
                $name   = (string) preg_replace('/^[\s·]+|[\s·]+$/u', '', $course['code'] . ' · ' . $course['title']);
                $short  = $course['code'];
                $closed = $course['closed'];
            } elseif ($row['type'] === 'group') {
                $name  = (string) ($row['title'] ?? 'Group');
                $short = $name;
            } else {
                $name  = $other === null ? 'Someone' : ($avatars[$other]['name'] ?? $people[$other] ?? 'Someone');
                $short = $name;
            }

            $row['name']     = $name;
            $row['closed']   = $closed;
            $row['initials'] = $this->initials($short);
            $row['avatar']   = $row['type'] === 'direct' && $other !== null ? ($avatars[$other] ?? null) : null;
            $row['subtitle'] = $subject === null ? null : ($avatars[$subject]['name'] ?? $people[$subject] ?? null);
            $row['preview']  = $this->preview((int) $row['id'], (int) $viewer->id);
            $row['can_post'] = $this->policy->canPost($this->resolver->forPost($viewer, [
                'id' => $row['id'], 'type' => $row['type'], 'institution_id' => $institution,
                'offering_id' => $row['offering_id'], 'is_locked' => $row['is_locked'], 'subject_student_id' => $row['subject_student_id'],
            ]));

            $row['pinned']        = ($row['pinned_at'] ?? null) !== null;
            $row['archived']      = ($row['archived_at'] ?? null) !== null;
            $row['muted']         = (int) ($row['is_muted'] ?? 0) === 1;
            $row['marked_unread'] = (int) ($row['marked_unread'] ?? 0) === 1;
            $row['can_pin_chat']  = $row['type'] !== 'course';
            $row['can_delete']    = in_array($row['type'], ['direct', 'group'], true);

            $row['blocked_by_me'] = $row['type'] === 'direct' && $other !== null && isset($blocked[$other]);
            $row['can_block']     = $row['type'] === 'direct' && $other !== null && ! $row['blocked_by_me'] && isset($blockable[$other]);
            $teaches             = $row['offering_id'] !== null && isset($teaching[(int) $row['offering_id']]);
            $created             = $row['type'] === 'group' && (int) ($row['created_by'] ?? 0) === (int) $viewer->id;
            $row['can_moderate'] = $this->policy->canModerate($teaches ? $viewerParty->withRole('instructor') : $viewerParty, (string) $row['type'], $teaches || $created);

            // The line is the recipient's: a stored first contact, or a minor viewer until they reply (FR5). The sender
            // sees only the stored fact, so a recipient's minor flag never shows on their side.
            $direct                      = $row['type'] === 'direct' && ($row['first_contact_cleared_at'] ?? null) === null;
            $mine                        = (int) ($row['created_by'] ?? 0) === (int) $viewer->id;
            $starterRoles                = $starters[(int) ($row['created_by'] ?? 0)] ?? null;
            $row['first_contact_line']   = $direct && ! $mine && $starterRoles !== null && ((int) ($row['first_contact'] ?? 0) === 1 || $viewerParty->minor);
            $row['first_contact_role']   = $row['first_contact_line'] ? (RoleRank::highest($starterRoles) ?? '') : null;
            $row['first_contact_sender'] = $direct && $mine && (int) ($row['first_contact'] ?? 0) === 1;

            $row['controls']            = ChatControls::forConversation($row);
            $row['can_manage_controls'] = $this->controls->canManage($viewer, $row, $viewerParty);

            $out[] = $row;
        }

        return $out;
    }

    /**
     * The roles of the people who started the open first-contact threads the viewer received, in one query for the
     * whole list (a person who no longer exists is left out, so their thread shows no line).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, list<string>> user id => roles
     */
    private function starterRoles(User $viewer, Party $viewerParty, array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'direct' && ($row['first_contact_cleared_at'] ?? null) === null && (int) $row['created_by'] !== (int) $viewer->id
                && ((int) ($row['first_contact'] ?? 0) === 1 || $viewerParty->minor)) {
                $ids[(int) $row['created_by']] = (int) $row['created_by'];
            }
        }

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (db_connect()->table('users u')->select('u.id, g.group')->join('auth_groups_users g', 'g.user_id = u.id', 'left')
            ->where('u.deleted_at', null)->whereIn('u.id', array_values($ids))->get()->getResultArray() as $r) {
            $out[(int) $r['id']] ??= [];

            if ($r['group'] !== null) {
                $out[(int) $r['id']][] = (string) $r['group'];
            }
        }

        return $out;
    }

    /** @return array<int, true> the people this viewer has blocked */
    private function blockedIds(int $userId): array
    {
        $out = [];

        foreach (db_connect()->table('user_blocks')->select('blocked_id')->where('blocker_id', $userId)->get()->getResultArray() as $row) {
            $out[(int) $row['blocked_id']] = true;
        }

        return $out;
    }

    /**
     * The other people of this list's direct threads the Block control is offered for (BlockService::allows
     * with the protected link ignored: when only that refuses, the click gets the explaining 422, FR4).
     * One batched institution lookup and one user fetch for the whole list.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, true>           $blocked
     *
     * @return array<int, true>
     */
    private function blockTargets(User $viewer, ?int $institution, array $rows, array $blocked): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'direct' && $row['other_user_id'] !== null && ! isset($blocked[(int) $row['other_user_id']])) {
                $ids[(int) $row['other_user_id']] = (int) $row['other_user_id'];
            }
        }

        if ($institution === null || $ids === []) {
            return [];
        }

        $institutions = (new PlanLimits())->institutionsOf(array_values($ids));
        $service      = new BlockService($this->resolver);
        $out          = [];

        /** @var list<User> $targets */
        $targets = model(UserModel::class)->whereIn('id', array_values($ids))->findAll();

        foreach ($targets as $target) {
            if ($service->allows($viewer, $institution, $target, $institutions[(int) $target->id] ?? null, false)) {
                $out[(int) $target->id] = true;
            }
        }

        return $out;
    }

    /**
     * The offerings, of these, that the user teaches (one query for the whole list).
     *
     * @param list<int> $offeringIds
     *
     * @return array<int, true>
     */
    private function teaching(int $userId, array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $out = [];

        foreach (db_connect()->table('instructor_assignments')->select('offering_id')->where('instructor_user_id', $userId)->whereIn('offering_id', $offeringIds)->get()->getResultArray() as $row) {
            $out[(int) $row['offering_id']] = true;
        }

        return $out;
    }

    /**
     * Full names (first and last, as the profile spells them) by user id.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public static function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (db_connect()->table('user_profiles')->select('user_id, first_name, last_name')->whereIn('user_id', array_values(array_unique($ids)))->get()->getResultArray() as $row) {
            $names[(int) $row['user_id']] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }

        return $names;
    }

    /**
     * @param list<int> $offeringIds
     *
     * @return array<int, array{code: string, title: string, closed: bool}>
     */
    private function courses(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $out = [];

        foreach (db_connect()->table('course_offerings co')->select('co.id, co.status, c.course_code, c.course_title')->join('courses c', 'c.id = co.course_id')->whereIn('co.id', $offeringIds)->get()->getResultArray() as $row) {
            $out[(int) $row['id']] = ['code' => (string) $row['course_code'], 'title' => (string) $row['course_title'], 'closed' => $row['status'] !== 'active'];
        }

        return $out;
    }

    private function initials(string $name): string
    {
        $words = preg_split('/[\s·]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) <= 1) {
            return mb_strtoupper(mb_substr($words[0] ?? '', 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
    }

    /** The last message this person can see, as one short line; null when there is none. */
    private function preview(int $conversationId, int $userId): ?string
    {
        $row = db_connect()->query(
            'SELECT m.* FROM messages m
             JOIN conversation_participants p ON p.conversation_id = m.conversation_id AND p.user_id = ?
             WHERE m.conversation_id = ? AND m.id >= p.first_visible_message_id
               AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
             ORDER BY m.id DESC LIMIT 1',
            [$userId, $conversationId, $userId],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        if ($row['removed_at'] !== null) {
            $direct = $row['removed_by'] !== null && db_connect()->table('conversations')->where(['id' => $conversationId, 'type' => 'direct'])->countAllResults() > 0;

            return $direct ? 'Message removed by an administrator' : 'Message removed';
        }

        $body = $this->cipher->decrypt((string) $row['body_enc'], (int) $row['conversation_id'], (int) $row['sender_id']);

        if ($body === null) {
            // The id only: the text, or anything derived from it, is never logged.
            log_message('warning', 'Messages: message {id} could not be decrypted.', ['id' => (int) $row['id']]);

            return 'Message unavailable';
        }

        $line = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strlen($line) > self::PREVIEW ? mb_substr($line, 0, self::PREVIEW) . '…' : $line;
    }
}

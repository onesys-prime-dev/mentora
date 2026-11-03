<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use CodeIgniter\Shield\Entities\User;

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

    public function __construct(?MessageCipher $cipher = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null)
    {
        $this->cipher   = $cipher ?? new MessageCipher();
        $this->resolver = $resolver ?? new MessagingContextResolver();
        $this->policy   = $policy ?? new MessagingPolicy();
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
        $out         = [];

        foreach ($rows as $row) {
            $other   = $row['other_user_id'] === null ? null : (int) $row['other_user_id'];
            $subject = $row['subject_student_id'] === null ? null : (int) $row['subject_student_id'];

            if ($row['type'] === 'course') {
                $course = $courses[(int) $row['offering_id']] ?? ['code' => '', 'title' => ''];
                $name   = trim($course['code'] . ' · ' . $course['title'], ' ·');
                $short  = $course['code'];
            } elseif ($row['type'] === 'group') {
                $name  = (string) ($row['title'] ?? 'Group');
                $short = $name;
            } else {
                $name  = $other === null ? 'Someone' : ($people[$other] ?? 'Someone');
                $short = $name;
            }

            $row['name']     = $name;
            $row['initials'] = $this->initials($short);
            $row['subtitle'] = $subject === null ? null : ($people[$subject] ?? null);
            $row['preview']  = $this->preview((int) $row['id'], (int) $viewer->id);
            $row['can_post'] = $this->policy->canPost($this->resolver->forPost($viewer, [
                'id' => $row['id'], 'type' => $row['type'], 'institution_id' => $institution,
                'offering_id' => $row['offering_id'], 'is_locked' => $row['is_locked'],
            ]));

            $out[] = $row;
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
     * @return array<int, array{code: string, title: string}>
     */
    private function courses(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $out = [];

        foreach (db_connect()->table('course_offerings co')->select('co.id, c.course_code, c.course_title')->join('courses c', 'c.id = co.course_id')->whereIn('co.id', $offeringIds)->get()->getResultArray() as $row) {
            $out[(int) $row['id']] = ['code' => (string) $row['course_code'], 'title' => (string) $row['course_title']];
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
            return 'Message removed';
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

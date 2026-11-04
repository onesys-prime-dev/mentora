<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Services\Parents\ParentAccessService;
use CodeIgniter\Shield\Entities\User;

/**
 * What a parent sees of a child's course chat: the instructors' posts, newest
 * first, and nothing else (spec 3.2). No student's words, nothing removed,
 * nothing from before the child joined. The parent is never a member of the
 * chat; this is the one way their reading of it is allowed.
 */
class ParentFeedService
{
    private MessageCipher $cipher;

    private ParentAccessService $access;

    private MessagingPolicy $policy;

    public function __construct(?MessageCipher $cipher = null, ?ParentAccessService $access = null, ?MessagingPolicy $policy = null)
    {
        $this->cipher = $cipher ?? new MessageCipher();
        $this->access = $access ?? new ParentAccessService();
        $this->policy = $policy ?? new MessagingPolicy();
    }

    /**
     * Null when the parent may not look: not linked to the child, or the child is not in that offering.
     *
     * @return array{instructors: list<array{id: int, name: string}>, posts: list<array{id: int, sender_name: string, body: string, created_at: string}>}|null
     */
    public function feed(User $parent, int $studentId, int $offeringId, int $limit = 30): ?array
    {
        if (! $this->access->canViewOffering($parent, $studentId, $offeringId) || ! $this->policy->canSeeParentFeed(true, true, 'instructor')) {
            return null;
        }

        $db          = db_connect();
        $ids         = array_map('intval', array_column($db->table('instructor_assignments')->select('instructor_user_id')->where('offering_id', $offeringId)->get()->getResultArray(), 'instructor_user_id'));
        $names       = ConversationPresenter::names($ids);
        $instructors = array_map(static fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? 'Instructor'], $ids);
        $chat        = $db->table('conversations')->select('id')->where(['type' => 'course', 'offering_id' => $offeringId])->get()->getRowArray();

        // What the child can see, no more: a child who has left the chat has no feed.
        $joined = $chat === null || $ids === [] ? null : $db->table('conversation_participants')->select('first_visible_message_id')
            ->where(['conversation_id' => (int) $chat['id'], 'user_id' => $studentId, 'left_at' => null])->get()->getRowArray();

        if ($joined === null) {
            return ['instructors' => $instructors, 'posts' => []];
        }

        $rows = $db->table('messages')
            ->where('conversation_id', (int) $chat['id'])->whereIn('sender_id', $ids)->where('removed_at', null)
            ->where('id >=', (int) $joined['first_visible_message_id'])
            ->orderBy('id', 'DESC')->limit(max(1, min(100, $limit)))->get()->getResultArray();

        $posts = [];

        foreach ($rows as $row) {
            $body = $this->cipher->decrypt((string) $row['body_enc'], (int) $row['conversation_id'], (int) $row['sender_id']);

            if ($body === null) {
                // The id only: the text, or anything derived from it, is never logged.
                log_message('warning', 'Messages: message {id} could not be decrypted.', ['id' => (int) $row['id']]);
                $body = 'Message unavailable';
            }

            $posts[] = ['id' => (int) $row['id'], 'sender_name' => $names[(int) $row['sender_id']] ?? 'Instructor', 'body' => $body, 'created_at' => (string) $row['created_at']];
        }

        return ['instructors' => $instructors, 'posts' => $posts];
    }
}

<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

/** One reaction per person per target: the thread itself, or one reply. */
class DiscussionReactionModel extends Model
{
    protected $table         = 'discussion_reactions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['thread_id', 'reply_id', 'user_id', 'target_key', 'emoji', 'created_at'];

    /** @return array{action: 'added'|'removed'|'changed', emoji: ?string} */
    public function toggle(int $threadId, ?int $replyId, int $userId, string $emoji): array
    {
        $targetKey = $replyId ?? 0;

        $existing = $this->where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->where('target_key', $targetKey)
            ->first();

        if (!$existing) {
            $this->insert([
                'thread_id' => $threadId,
                'reply_id' => $replyId,
                'user_id' => $userId,
                'target_key' => $targetKey,
                'emoji' => $emoji,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return ['action' => 'added', 'emoji' => $emoji];
        }

        if ($existing['emoji'] === $emoji) {
            $this->delete($existing['id']);

            return ['action' => 'removed', 'emoji' => null];
        }

        $this->update($existing['id'], ['emoji' => $emoji]);

        return ['action' => 'changed', 'emoji' => $emoji];
    }

    /** @return array{thread: array<string,int>, replies: array<int, array<string,int>>} */
    public function retrieveCounts(int $threadId): array
    {
        $rows = $this->select(['target_key', 'emoji', 'COUNT(*) AS total'])
            ->where('thread_id', $threadId)
            ->groupBy(['target_key', 'emoji'])
            ->findAll();

        $out = ['thread' => [], 'replies' => []];

        foreach ($rows as $row) {
            if ((int) $row['target_key'] === 0) {
                $out['thread'][$row['emoji']] = (int) $row['total'];
            } else {
                $out['replies'][(int) $row['target_key']][$row['emoji']] = (int) $row['total'];
            }
        }

        return $out;
    }

    /** @return array{thread: ?string, replies: array<int,string>} this user's own reactions */
    public function retrieveOwn(int $threadId, int $userId): array
    {
        $rows = $this->select(['target_key', 'emoji'])
            ->where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->findAll();

        $out = ['thread' => null, 'replies' => []];

        foreach ($rows as $row) {
            if ((int) $row['target_key'] === 0) {
                $out['thread'] = $row['emoji'];
            } else {
                $out['replies'][(int) $row['target_key']] = $row['emoji'];
            }
        }

        return $out;
    }
}

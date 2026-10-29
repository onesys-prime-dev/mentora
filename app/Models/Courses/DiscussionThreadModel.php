<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class DiscussionThreadModel extends Model
{
    protected $table         = 'discussion_threads';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $allowedFields = [
        'offering_id',
        'learning_item_id',
        'author_id',
        'title',
        'body',
        'is_pinned',
        'is_locked',
        'answer_reply_id',
        'reply_count',
        'last_activity_at',
    ];

    /**
     * One role per author, preferring instructor, as announcements do: joining the author's groups listed
     * a thread once per role (QA finding F-02).
     */
    private const AUTHOR_ROLE = "(SELECT g.`group` FROM auth_groups_users g WHERE g.user_id = discussion_threads.author_id"
        . " ORDER BY g.`group` = 'instructor' DESC, g.`group` LIMIT 1) AS author_role";

    /** Columns every thread read needs: author, unread flag, and the module/unit a linked thread sits under. */
    private function withContext(int $userId)
    {
        return $this->select([
            'discussion_threads.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
            self::AUTHOR_ROLE,
            'course_modules.id AS module_id',
            'course_modules.title AS module_title',
            'learning_units.title AS unit_title',
            '(discussion_thread_reads.last_read_at IS NULL OR discussion_thread_reads.last_read_at < discussion_threads.last_activity_at) AS is_unread',
        ], false)
            ->join('user_profiles', 'user_profiles.user_id = discussion_threads.author_id', 'left')
            ->join('learning_items', 'learning_items.id = discussion_threads.learning_item_id', 'left')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id', 'left')
            ->join('course_modules', 'course_modules.id = learning_units.module_id', 'left')
            ->join(
                'discussion_thread_reads',
                'discussion_thread_reads.thread_id = discussion_threads.id AND discussion_thread_reads.user_id = ' . (int) $userId,
                'left'
            );
    }

    /**
     * @param array{search?: string, sort?: string, filter?: string, limit?: int, offset?: int} $options
     */
    public function retrieveForOffering(int $offeringId, int $userId, array $options = []): array
    {
        $builder = $this->withContext($userId)
            ->where('discussion_threads.offering_id', $offeringId);

        // Students must not see a discussion whose learning item is still a draft.
        if (!empty($options['hide_unpublished'])) {
            $builder->groupStart()
                ->where('discussion_threads.learning_item_id IS NULL', null, false)
                ->orWhere('learning_items.status', 'published')
                ->groupEnd();
        }

        $search = trim((string) ($options['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()
                ->like('discussion_threads.title', $search)
                ->orLike('discussion_threads.body', $search)
                ->groupEnd();
        }

        switch ($options['filter'] ?? 'all') {
            case 'pinned':
                $builder->where('discussion_threads.is_pinned', 1);
                break;
            case 'mine':
                $builder->where('discussion_threads.author_id', $userId);
                break;
            case 'unanswered':
                $builder->where('discussion_threads.answer_reply_id IS NULL', null, false);
                break;
            case 'modules':
                $builder->where('discussion_threads.learning_item_id IS NOT NULL', null, false);
                break;
            case 'unread':
                $builder->groupStart()
                    ->where('discussion_thread_reads.last_read_at IS NULL', null, false)
                    ->orWhere('discussion_thread_reads.last_read_at < discussion_threads.last_activity_at', null, false)
                    ->groupEnd();
                break;
        }

        $builder->orderBy('discussion_threads.is_pinned', 'DESC');

        match ($options['sort'] ?? 'activity') {
            'newest' => $builder->orderBy('discussion_threads.created_at', 'DESC'),
            'replies' => $builder->orderBy('discussion_threads.reply_count', 'DESC')
                ->orderBy('discussion_threads.last_activity_at', 'DESC'),
            default => $builder->orderBy('discussion_threads.last_activity_at', 'DESC'),
        };

        $builder->orderBy('discussion_threads.id', 'DESC');

        if (isset($options['limit'])) {
            $builder->limit((int) $options['limit'], (int) ($options['offset'] ?? 0));
        }

        return $builder->findAll();
    }

    public function retrieveOne(int $id, int $userId = 0, bool $hideUnpublished = false): ?array
    {
        $builder = $this->withContext($userId)
            ->where('discussion_threads.id', $id);

        if ($hideUnpublished) {
            $builder->groupStart()
                ->where('discussion_threads.learning_item_id IS NULL', null, false)
                ->orWhere('learning_items.status', 'published')
                ->groupEnd();
        }

        return $builder->first();
    }
}

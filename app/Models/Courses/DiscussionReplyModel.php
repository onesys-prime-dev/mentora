<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class DiscussionReplyModel extends Model
{
    protected $table         = 'discussion_replies';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $allowedFields = ['thread_id', 'author_id', 'body'];

    private function withAuthor()
    {
        return $this->select([
            'discussion_replies.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
            'auth_groups_users.group AS author_role',
        ])
            ->join('user_profiles', 'user_profiles.user_id = discussion_replies.author_id', 'left')
            ->join('auth_groups_users', 'auth_groups_users.user_id = discussion_replies.author_id', 'left');
    }

    /** Oldest first — a thread reads top to bottom. */
    public function retrieveForThread(int $threadId): array
    {
        return $this->withAuthor()
            ->where('discussion_replies.thread_id', $threadId)
            ->orderBy('discussion_replies.id', 'ASC')
            ->findAll();
    }

    public function retrieveOne(int $id): ?array
    {
        return $this->withAuthor()->where('discussion_replies.id', $id)->first();
    }
}

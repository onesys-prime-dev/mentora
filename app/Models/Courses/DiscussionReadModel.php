<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class DiscussionReadModel extends Model
{
    protected $table         = 'discussion_thread_reads';
    protected $primaryKey    = 'thread_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['thread_id', 'user_id', 'last_read_at'];

    public function markRead(int $threadId, int $userId): void
    {
        // Viewing as someone saves nothing for them (3.2): the thread stays unread for the person themselves.
        if (\App\Services\Admin\Impersonation::current() !== null) {
            return;
        }

        $this->db->query(
            'INSERT INTO discussion_thread_reads (thread_id, user_id, last_read_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)',
            [$threadId, $userId, (new \DateTime())->format('Y-m-d H:i:s.u')]
        );
    }
}

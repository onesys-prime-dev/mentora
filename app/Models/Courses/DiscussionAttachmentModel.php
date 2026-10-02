<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class DiscussionAttachmentModel extends Model
{
    protected $table         = 'discussion_attachments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['thread_id', 'reply_id', 'file_id', 'order_index'];

    /** @param list<int|string> $fileIds */
    public function attach(int $threadId, ?int $replyId, array $fileIds): void
    {
        $order = 1;

        foreach ($fileIds as $fileId) {

            if (!$fileId) {
                continue;
            }

            $this->insert([
                'thread_id' => $threadId,
                'reply_id' => $replyId,
                'file_id' => (int) $fileId,
                'order_index' => $order++,
            ]);

        }
    }

    /** @return array{thread: list<array>, replies: array<int, list<array>>} */
    public function retrieveForThread(int $threadId): array
    {
        $rows = $this->select([
            'discussion_attachments.reply_id',
            'files.id',
            'files.original_filename',
            'files.mime_type',
            'files.file_size',
        ])
            ->join('files', 'files.id = discussion_attachments.file_id')
            ->where('discussion_attachments.thread_id', $threadId)
            ->where('files.status', 'active')
            ->orderBy('discussion_attachments.order_index', 'ASC')
            ->findAll();

        $out = ['thread' => [], 'replies' => []];

        foreach ($rows as $row) {
            if ($row['reply_id'] === null) {
                $out['thread'][] = $row;
            } else {
                $out['replies'][(int) $row['reply_id']][] = $row;
            }
        }

        return $out;
    }

    /** File ids on a thread and all its replies, collected before a delete so they can be purged. */
    public function fileIdsForThread(int $threadId): array
    {
        return array_map('intval', $this->where('thread_id', $threadId)->findColumn('file_id') ?? []);
    }

    public function fileIdsForReply(int $replyId): array
    {
        return array_map('intval', $this->where('reply_id', $replyId)->findColumn('file_id') ?? []);
    }
}

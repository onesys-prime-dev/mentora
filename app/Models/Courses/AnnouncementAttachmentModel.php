<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class AnnouncementAttachmentModel extends Model
{
    protected $table         = 'announcement_attachments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'announcement_id',
        'file_id',
        'order_index',
    ];

    public function retrieveForAnnouncements(array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = $this->select([
            'announcement_attachments.announcement_id',
            'files.id',
            'files.original_filename',
            'files.mime_type',
            'files.file_size',
        ])
            ->join('files', 'files.id = announcement_attachments.file_id')
            ->whereIn('announcement_attachments.announcement_id', $announcementIds)
            ->where('files.status', 'active')
            ->orderBy('announcement_attachments.order_index', 'ASC')
            ->findAll();

        $byAnnouncement = [];
        foreach ($rows as $row) {
            $byAnnouncement[(int) $row['announcement_id']][] = $row;
        }

        return $byAnnouncement;
    }
}

<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class AnnouncementReplyModel extends Model
{
    protected $table         = 'announcement_replies';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'announcement_id',
        'author_id',
        'message',
        'created_at',
    ];

    /** Oldest-first — a thread reads top to bottom. */
    public function retrieveForAnnouncement(int $announcementId): array
    {
        return $this->select([
            'announcement_replies.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
        ])
            ->join('user_profiles', 'user_profiles.user_id = announcement_replies.author_id', 'left')
            ->where('announcement_id', $announcementId)
            ->orderBy('announcement_replies.id', 'ASC')
            ->findAll();
    }

    /** Batched equivalent of calling retrieveForAnnouncement() per id. */
    public function retrieveForAnnouncements(array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = $this->select([
            'announcement_replies.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
        ])
            ->join('user_profiles', 'user_profiles.user_id = announcement_replies.author_id', 'left')
            ->whereIn('announcement_id', $announcementIds)
            ->orderBy('announcement_replies.id', 'ASC')
            ->findAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['announcement_id']][] = $row;
        }

        return $grouped;
    }

    public function retrieveCounts(array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = $this->select(['announcement_id', 'COUNT(*) AS total'])
            ->whereIn('announcement_id', $announcementIds)
            ->groupBy('announcement_id')
            ->findAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['announcement_id']] = (int) $row['total'];
        }

        return $counts;
    }
}

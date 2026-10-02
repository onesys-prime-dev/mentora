<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class AnnouncementReactionModel extends Model
{
    protected $table         = 'announcement_reactions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'announcement_id',
        'user_id',
        'emoji',
        'created_at',
    ];

    /**
     * Toggle semantics, same as Teams: tapping your current reaction again
     * removes it; tapping a different one replaces it; one row per person.
     *
     * @return array{action: 'added'|'removed'|'changed', emoji: ?string}
     */
    public function toggle(int $announcementId, int $userId, string $emoji): array
    {
        $existing = $this->where('announcement_id', $announcementId)
            ->where('user_id', $userId)
            ->first();

        if (!$existing) {
            $this->insert([
                'announcement_id' => $announcementId,
                'user_id' => $userId,
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

    /** @return array<int, array<string, int>> announcement_id => [emoji => count] */
    public function retrieveCounts(array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = $this->select(['announcement_id', 'emoji', 'COUNT(*) AS total'])
            ->whereIn('announcement_id', $announcementIds)
            ->groupBy(['announcement_id', 'emoji'])
            ->findAll();

        $byAnnouncement = [];
        foreach ($rows as $row) {
            $byAnnouncement[(int) $row['announcement_id']][$row['emoji']] = (int) $row['total'];
        }

        return $byAnnouncement;
    }

    /** @return array<int, string> announcement_id => this user's own emoji */
    public function retrieveOwnReactions(array $announcementIds, int $userId): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = $this->select(['announcement_id', 'emoji'])
            ->whereIn('announcement_id', $announcementIds)
            ->where('user_id', $userId)
            ->findAll();

        $own = [];
        foreach ($rows as $row) {
            $own[(int) $row['announcement_id']] = $row['emoji'];
        }

        return $own;
    }
}

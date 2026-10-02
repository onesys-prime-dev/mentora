<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class AnnouncementModel extends Model
{
    protected $table            = 'announcements';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $dateFormat       = 'datetime';
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'offering_id',
        'author_id',
        'subject',
        'message',
        'is_pinned',
    ];

    /**
     * Pinned posts always lead; sort controls the created_at tiebreak.
     *
     * @param array{search?: string, sort?: string, pinned_only?: bool, limit?: int, offset?: int} $options
     */
    public function retrieveForOffering(int $offeringId, array $options = []): array
    {
        $builder = $this->select([
            'announcements.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
            'auth_groups_users.group AS author_role',
        ])
            ->join('user_profiles', 'user_profiles.user_id = announcements.author_id', 'left')
            ->join('auth_groups_users', 'auth_groups_users.user_id = announcements.author_id', 'left')
            ->where('announcements.offering_id', $offeringId);

        if (!empty($options['pinned_only'])) {
            $builder->where('announcements.is_pinned', 1);
        }

        $search = trim((string) ($options['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()
                ->like('announcements.subject', $search)
                ->orLike('announcements.message', $search)
                ->groupEnd();
        }

        $direction = ($options['sort'] ?? 'newest') === 'oldest' ? 'ASC' : 'DESC';

        $builder->orderBy('announcements.is_pinned', 'DESC')
            ->orderBy('announcements.created_at', $direction)
            ->orderBy('announcements.id', $direction);

        if (isset($options['limit'])) {
            $builder->limit((int) $options['limit'], (int) ($options['offset'] ?? 0));
        }

        return $builder->findAll();
    }

    /**
     * Newest posts across a set of offerings (e.g. every course a student
     * is enrolled in), for the dashboard's Announcements widget — pinned
     * status doesn't matter at this scale, just recency.
     */
    public function retrieveForOfferings(array $offeringIds, int $limit = 8): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->select([
            'announcements.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
            'auth_groups_users.group AS author_role',
        ])
            ->join('user_profiles', 'user_profiles.user_id = announcements.author_id', 'left')
            ->join('auth_groups_users', 'auth_groups_users.user_id = announcements.author_id', 'left')
            ->whereIn('announcements.offering_id', $offeringIds)
            ->orderBy('announcements.created_at', 'DESC')
            ->orderBy('announcements.id', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    public function retrieveOne(int $id): ?array
    {
        return $this->select([
            'announcements.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
            'auth_groups_users.group AS author_role',
        ])
            ->join('user_profiles', 'user_profiles.user_id = announcements.author_id', 'left')
            ->join('auth_groups_users', 'auth_groups_users.user_id = announcements.author_id', 'left')
            ->where('announcements.id', $id)
            ->first();
    }
}

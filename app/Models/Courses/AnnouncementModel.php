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
        'created_at',
    ];

    /**
     * The author's role, one per post however many groups they hold (QA AMB-COM-03): instructor when
     * they hold it, so a post shows once and its card says who wrote it.
     */
    private const AUTHOR_ROLE = "(SELECT g.`group` FROM auth_groups_users g WHERE g.user_id = announcements.author_id"
        . " ORDER BY g.`group` = 'instructor' DESC, g.`group` LIMIT 1) AS author_role";

    /**
     * Pinned posts always lead; sort controls the created_at tiebreak. A post dated for a later
     * day is left out until then (QA AMB-COM-01) unless `scheduled` asks for it: the course's own
     * instructors see theirs in the feed.
     *
     * @param array{search?: string, sort?: string, pinned_only?: bool, limit?: int, offset?: int, scheduled?: bool} $options
     */
    public function retrieveForOffering(int $offeringId, array $options = []): array
    {
        $builder = $this->select([
            'announcements.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
        ])
            ->select(self::AUTHOR_ROLE, false)
            ->join('user_profiles', 'user_profiles.user_id = announcements.author_id', 'left')
            ->where('announcements.offering_id', $offeringId);

        if (empty($options['scheduled'])) {
            $builder->where('announcements.created_at <=', date('Y-m-d H:i:s'));
        }

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
     * status doesn't matter at this scale, just recency. Posts dated for a later
     * day wait for it.
     */
    public function retrieveForOfferings(array $offeringIds, int $limit = 8): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->select([
            'announcements.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
        ])
            ->select(self::AUTHOR_ROLE, false)
            ->join('user_profiles', 'user_profiles.user_id = announcements.author_id', 'left')
            ->whereIn('announcements.offering_id', $offeringIds)
            ->where('announcements.created_at <=', date('Y-m-d H:i:s'))
            ->orderBy('announcements.created_at', 'DESC')
            ->orderBy('announcements.id', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /** Posts across a set of offerings, posted within [from, to] — the Calendar's announcement events. */
    public function retrieveForOfferingsInRange(array $offeringIds, string $from, string $to): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->select('announcements.*')
            ->whereIn('announcements.offering_id', $offeringIds)
            ->where('announcements.created_at >=', $from)
            ->where('announcements.created_at <=', min($to, date('Y-m-d H:i:s')))
            ->orderBy('announcements.created_at', 'DESC')
            ->findAll();
    }

    /**
     * Announcement counts per offering within [$from, $until) when given —
     * the "announcements" side of instructor activity.
     *
     * @return array<int, int>
     */
    public function retrieveCountsByOffering(array $offeringIds, ?string $from = null, ?string $until = null): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $builder = $this->select(['offering_id', 'COUNT(*) AS announcement_count'])
            ->whereIn('offering_id', $offeringIds);

        if ($from !== null) {
            $builder->where('created_at >=', $from);
        }

        if ($until !== null) {
            $builder->where('created_at <', $until);
        }

        $rows = $builder->groupBy('offering_id')->findAll();

        $byOffering = [];

        foreach ($rows as $row) {
            $byOffering[(int) $row['offering_id']] = (int) $row['announcement_count'];
        }

        return $byOffering;
    }

    public function retrieveOne(int $id): ?array
    {
        return $this->select([
            'announcements.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
        ])
            ->select(self::AUTHOR_ROLE, false)
            ->join('user_profiles', 'user_profiles.user_id = announcements.author_id', 'left')
            ->where('announcements.id', $id)
            ->first();
    }
}
<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class AnnouncementModel extends Model
{
    protected $table = 'announcements';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'offering_id',
        'author_id',
        'title',
        'body',
        'status',
    ];

    protected $validationRules = [
        'offering_id' => 'required|is_natural_no_zero',
        'author_id' => 'required|is_natural_no_zero',
        'title' => 'required|max_length[150]',
        'body' => 'required',
        'status' => 'permit_empty|in_list[Draft,Published]',
    ];

    /** Published posts across a set of offerings, posted within [from, to]. */
    public function retrieveForOfferingsInRange(array $offeringIds, string $from, string $to): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->select([
                'announcements.*',
                'course_offerings.course_id',
                'courses.course_code',
            ])
            ->join('course_offerings', 'course_offerings.id = announcements.offering_id')
            ->join('courses', 'courses.id = course_offerings.course_id')
            ->whereIn('announcements.offering_id', $offeringIds)
            ->where('announcements.status', 'Published')
            ->where('announcements.created_at >=', $from)
            ->where('announcements.created_at <=', $to)
            ->orderBy('announcements.created_at', 'DESC')
            ->findAll();
    }
}

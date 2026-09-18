<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class CourseScheduleModel extends Model
{
    protected $table = 'course_schedules';

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
        'day_of_week',
        'start_time',
        'end_time',
        'room',
        'delivery_mode',

    ];

    protected $validationRules = [

        'offering_id' => 'required|is_natural_no_zero',

        'day_of_week' => 'required|in_list[mon,tue,wed,thu,fri,sat,sun]',

        'start_time' => 'required',

        'end_time' => 'required',

        'room' => 'permit_empty|max_length[50]',

        'delivery_mode' => 'required|in_list[onsite,online,hybrid]',

    ];

    /**
     * Meeting blocks for a set of offerings, in week order then start time,
     * so the caller can fold consecutive days into "MWF 8:00-9:30".
     */
    public function retrieveForOfferings(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'offering_id',
                'day_of_week',
                'start_time',
                'end_time',
                'room',
                'delivery_mode',
            ])
            ->whereIn('offering_id', $offeringIds)
            ->orderBy(
                "FIELD(day_of_week,'mon','tue','wed','thu','fri','sat','sun')",
                '',
                false
            )
            ->orderBy('start_time', 'ASC')
            ->get()
            ->getResultArray();
    }
}

<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

/**
 * Curves applied to a section's grades (see the grade_adjustments migration).
 */
class GradeAdjustmentModel extends Model
{
    protected $table            = 'grade_adjustments';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_offering_id',
        'scope',
        'grade_item_id',
        'method',
        'input',
        'params',
        'note',
        'students_raised',
        'created_by',
        'created_at',
        'undone_by',
        'undone_at',
        'live_marker',
        'target_key',
    ];

    /**
     * The adjustments that count now, params decoded: item adjustments keyed
     * by grade item id, and the final-grade one (or null).
     *
     * @return array{items: array<int, array<string, mixed>>, final: ?array<string, mixed>}
     */
    public function liveFor(int $offeringId): array
    {
        $live = ['items' => [], 'final' => null];

        foreach ($this->where('course_offering_id', $offeringId)->where('live_marker', 1)->findAll() as $row) {

            $row['params'] = json_decode((string) $row['params'], true) ?: [];

            if ($row['scope'] === 'final') {
                $live['final'] = $row;
            } else {
                $live['items'][(int) $row['grade_item_id']] = $row;
            }

        }

        return $live;
    }
}

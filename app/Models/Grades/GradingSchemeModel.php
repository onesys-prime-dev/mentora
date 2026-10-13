<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

/**
 * One grading scheme per course offering; the single row with
 * course_offering_id NULL and is_system_default = 1 is the template new
 * offerings copy (GradingSchemeService::forOffering).
 */
class GradingSchemeModel extends Model
{
    protected $table            = 'grading_schemes';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_offering_id',
        'name',
        'is_system_default',
        'uses_periods',
        'passing_percentage',
        'grade_scale_id',
        'status',
        'needs_recalculation',
        'last_recalculated_at',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
    ];

    public function findForOffering(int $offeringId): ?array
    {
        return $this->where('course_offering_id', $offeringId)->first();
    }

    /**
     * Which of these offerings have a published scheme — grades aren't
     * meaningful to show off of a scheme still in Draft (GradeService's
     * publish-gating rule), so a caller filters its offering list against
     * this before reading any percentage.
     *
     * @return list<int>
     */
    public function retrievePublishedOfferingIds(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return array_map(
            'intval',
            array_column(
                $this->select('course_offering_id')
                    ->whereIn('course_offering_id', $offeringIds)
                    ->where('status', 'Published')
                    ->findAll(),
                'course_offering_id'
            )
        );
    }

    public function findSystemDefault(): ?array
    {
        // The platform's template: an institution's own default scheme has no offering either,
        // but belongs to an institution, so it is never mistaken for this one.
        return $this->where('is_system_default', 1)
            ->where('course_offering_id', null)
            ->where('institution_id', null)
            ->first();
    }

    public function markNeedsRecalculation(int $schemeId): void
    {
        $this->update($schemeId, [
            'needs_recalculation' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

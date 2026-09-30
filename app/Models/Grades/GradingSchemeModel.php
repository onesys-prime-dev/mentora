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

    public function findSystemDefault(): ?array
    {
        return $this->where('is_system_default', 1)
            ->where('course_offering_id', null)
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

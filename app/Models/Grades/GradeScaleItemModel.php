<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

class GradeScaleItemModel extends Model
{
    protected $table            = 'grade_scale_items';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'grade_scale_id',
        'name',
        'minimum_percentage',
        'maximum_percentage',
        'numeric_value',
        'sort_order',
    ];

    /**
     * Bands of a scale, highest first — the order a lookup walks them.
     */
    public function listForScale(int $scaleId): array
    {
        return $this->where('grade_scale_id', $scaleId)
            ->orderBy('minimum_percentage', 'DESC')
            ->findAll();
    }
}

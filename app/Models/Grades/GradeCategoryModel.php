<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

class GradeCategoryModel extends Model
{
    protected $table            = 'grade_categories';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'grading_scheme_id',
        'name',
        'weight_percentage',
        'sort_order',
        'created_at',
        'updated_at',
        'status',
    ];

    public function listForScheme(int $schemeId): array
    {
        return $this->where('grading_scheme_id', $schemeId)
            ->where('status', 'Active')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    public function totalWeight(int $schemeId): float
    {
        return (float) ($this->selectSum('weight_percentage', 'total')
            ->where('grading_scheme_id', $schemeId)
            ->where('status', 'Active')
            ->first()['total'] ?? 0);
    }
}

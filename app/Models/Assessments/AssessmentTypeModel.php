<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentTypeModel extends Model
{
    protected $table            = 'assessment_types';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'name',
        'description',
        'is_system',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status',
    ];
}

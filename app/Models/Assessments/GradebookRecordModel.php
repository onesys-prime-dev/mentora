<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class GradebookRecordModel extends Model
{
    protected $table            = 'gradebook_records';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_id',
        'student_id',
        'calculated_final_grade',
        'overridden_final_grade',
        'is_override_final_grade',
        'overall_percentage',
        'remarks',
        'instructor_remarks',
        'override_reason',
        'last_calculated_at',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];
}
<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

/**
 * Audit trail of final-grade overrides (not of ordinary recalculations).
 */
class GradeHistoryModel extends Model
{
    protected $table            = 'grade_history';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'gradebook_record_id',
        'previous_percentage',
        'new_percentage',
        'reason',
        'changed_by',
        'changed_at',
    ];
}

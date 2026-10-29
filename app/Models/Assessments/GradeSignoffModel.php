<?php

declare(strict_types=1);

namespace App\Models\Assessments;

use CodeIgniter\Model;

/**
 * The dean's sign-offs of finalized grades (GradeSignoffService).
 */
class GradeSignoffModel extends Model
{
    protected $table = 'grade_signoffs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = ['offering_id', 'signed_by', 'signed_at', 'reopened_by', 'reopened_at', 'reopen_reason', 'open_marker'];

    /** The offering's current sign-off, if any. */
    public function currentFor(int $offeringId): ?array
    {
        return $this->where(['offering_id' => $offeringId, 'open_marker' => 1])->first();
    }
}

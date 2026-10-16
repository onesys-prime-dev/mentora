<?php

declare(strict_types=1);

namespace App\Models\Assessments;

use CodeIgniter\Model;

/**
 * Requests to override a student's final grade (GradeOverrideRequestService).
 */
class GradeOverrideRequestModel extends Model
{
    protected $table = 'grade_override_requests';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = [
        'offering_id', 'student_id', 'requested_percentage', 'calculated_percentage', 'previous_percentage',
        'reason', 'requested_by', 'requester_role', 'status', 'open_marker',
        'endorsed_by', 'endorsed_at', 'endorse_comment',
        'returned_by', 'returned_at', 'return_comment',
        'decided_by', 'decided_at', 'decision_reason',
    ];

    /** The one open request a student has in an offering, if any. */
    public function openFor(int $offeringId, int $studentId): ?array
    {
        return $this->where(['offering_id' => $offeringId, 'student_id' => $studentId, 'open_marker' => 1])->first();
    }

    /**
     * The open requests of an offering, by student id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openByStudent(int $offeringId): array
    {
        $byStudent = [];

        foreach ($this->where(['offering_id' => $offeringId, 'open_marker' => 1])->findAll() as $row) {
            $byStudent[(int) $row['student_id']] = $row;
        }

        return $byStudent;
    }
}

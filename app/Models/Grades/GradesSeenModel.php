<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

/**
 * The signature of a student's grade in an offering as a viewer last saw it
 * (one row per viewer, student and offering; see GradesSeen).
 */
class GradesSeenModel extends Model
{
    protected $table            = 'grades_seen';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'viewer_user_id',
        'student_user_id',
        'offering_id',
        'signature',
        'seen_at',
    ];

    /**
     * @param list<int> $offeringIds
     *
     * @return array<int, string> offering id => the signature last seen
     */
    public function signaturesFor(int $viewerId, int $studentId, array $offeringIds): array
    {
        $rows = $this->select('offering_id, signature')
            ->where('viewer_user_id', $viewerId)
            ->where('student_user_id', $studentId)
            ->whereIn('offering_id', $offeringIds)
            ->findAll();

        return array_column($rows, 'signature', 'offering_id');
    }

    /** @param list<array<string, mixed>> $rows one per offering, with every allowed field but id */
    public function upsertSeen(array $rows): void
    {
        $this->builder()->upsertBatch($rows);
    }
}

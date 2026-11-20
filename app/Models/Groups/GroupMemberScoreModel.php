<?php

declare(strict_types=1);

namespace App\Models\Groups;

use CodeIgniter\Model;

/**
 * One graded member of a group submission (see GroupGradingService).
 */
class GroupMemberScoreModel extends Model
{
    protected $table = 'group_member_scores';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = [
        'submission_id', 'student_user_id', 'group_id', 'group_points', 'individual_points', 'individual_rubric_scores', 'adjustment',
        'adjustment_reason', 'private_note', 'final_score', 'final_percentage', 'excused', 'pending', 'graded_by', 'graded_at',
    ];
}

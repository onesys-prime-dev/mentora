<?php

declare(strict_types=1);

namespace App\Models\Groups;

use CodeIgniter\Model;

/**
 * The group settings of one activity.
 */
class ActivityGroupConfigModel extends Model
{
    protected $table = 'activity_group_configs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = [
        'offering_id', 'assessment_id', 'learning_item_id', 'source_set_id', 'formation_mode', 'min_size', 'max_size',
        'signup_opens_at', 'signup_closes_at', 'ungrouped_policy', 'submission_style', 'grading_mode', 'use_average',
        'nonleader_can_rename', 'nonleader_can_vote', 'nonleader_can_leave', 'groups_visible', 'completion_mode',
        'frozen_at', 'created_by',
    ];
}

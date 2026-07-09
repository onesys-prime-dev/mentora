<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningCompletionModel extends Model
{
    protected $table = 'learning_completions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'learning_item_id',
        'student_user_id',
        'completed_at',
        'status',
    ];
}
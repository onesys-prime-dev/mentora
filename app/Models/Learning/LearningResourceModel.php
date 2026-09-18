<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningResourceModel extends Model
{
    protected $table = 'learning_resources';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    // No deleted_at column on this table — see CreateLearning*/CourseModules migrations.
    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'learning_item_id',
        'file_id',
        'title',
        'resource_type',
        'resource_url',
    ];
}
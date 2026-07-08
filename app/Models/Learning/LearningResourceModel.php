<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningResourceModel extends Model
{
    protected $table = 'learning_resources';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $useTimestamps = true;

    protected $allowedFields = [
        'learning_item_id',
        'file_id',
        'title',
        'resource_type',
        'resource_url',
    ];
}
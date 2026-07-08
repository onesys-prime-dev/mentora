<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningItemModel extends Model
{
    protected $table = 'learning_items';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $useTimestamps = true;

    protected $allowedFields = [
        'learning_unit_id',
        'title',
        'item_type',
        'content',
        'order_index',
        'status',
    ];
}
<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class LearningUnitModel extends Model
{
    protected $table = 'learning_units';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'module_id',
        'title',
        'description',
        'order_index',
        'status',
    ];
}
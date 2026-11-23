<?php

declare(strict_types=1);

namespace App\Models\Groups;

use CodeIgniter\Model;

/**
 * A group set: one way of dividing an offering's students into groups.
 */
class GroupSetModel extends Model
{
    protected $table = 'group_sets';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $allowedFields = ['offering_id', 'name', 'formation_mode', 'min_size', 'max_size', 'signup_opens_at', 'signup_closes_at', 'status', 'created_by'];
}

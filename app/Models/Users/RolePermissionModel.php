<?php

declare(strict_types=1);

namespace App\Models\Users;

use CodeIgniter\Model;

/**
 * Customisations to the default role/permission matrix (see the migration).
 * Read by PermissionMatrix; written by the roles page.
 */
class RolePermissionModel extends Model
{
    protected $table = 'role_permissions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = ['role', 'permission', 'granted'];
}

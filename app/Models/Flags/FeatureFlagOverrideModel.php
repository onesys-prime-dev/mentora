<?php

declare(strict_types=1);

namespace App\Models\Flags;

use CodeIgniter\Model;

/**
 * Changes made to feature flags: a global row per flag (institution_id NULL)
 * and an override per flag and institution. See the migration.
 */
class FeatureFlagOverrideModel extends Model
{
    protected $table = 'feature_flag_overrides';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = ['flag_key', 'institution_id', 'enabled', 'note', 'ends_on', 'created_by_user_id'];
}

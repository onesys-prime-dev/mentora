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

    protected $allowedFields = ['flag_key', 'institution_id', 'is_global', 'enabled', 'note', 'ends_on', 'created_by_user_id'];

    protected $beforeInsert = ['markScope'];

    protected $beforeUpdate = ['markScope'];

    /**
     * Keeps is_global in step with institution_id (see the migration): 1 for a
     * global row, NULL for an institution's. Callers never set it.
     *
     * @param array{data: array<string, mixed>} $event
     *
     * @return array{data: array<string, mixed>}
     */
    protected function markScope(array $event): array
    {
        if (array_key_exists('institution_id', $event['data'])) {
            $event['data']['is_global'] = $event['data']['institution_id'] === null ? 1 : null;
        }

        return $event;
    }
}

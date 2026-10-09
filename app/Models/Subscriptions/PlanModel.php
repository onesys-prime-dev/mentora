<?php

declare(strict_types=1);

namespace App\Models\Subscriptions;

use CodeIgniter\Model;

/**
 * Subscription plans. The checks that matter (name, caps, feature keys) are
 * made by PlanInput before anything reaches here; this model just stores.
 */
class PlanModel extends Model
{
    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $table = 'plans';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'name',
        'description',
        'features',
        'seat_limit',
        'storage_limit_mb',
        'ai_generation_limit',
        'status',
        'retired_at',
    ];

    /** Plans that can be chosen for a new subscription. A retired plan stays on the subscriptions that already have it. */
    public function selectable(): static
    {
        return $this->where('plans.status', self::ACTIVE);
    }
}

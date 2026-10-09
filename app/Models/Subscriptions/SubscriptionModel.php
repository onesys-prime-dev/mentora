<?php

declare(strict_types=1);

namespace App\Models\Subscriptions;

use CodeIgniter\Model;

/**
 * The current state of an institution's subscription: its plan and the dates
 * it runs between. The history of how it got there is SubscriptionRenewalModel.
 */
class SubscriptionModel extends Model
{
    protected $table = 'subscriptions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = ['institution_id', 'plan_id', 'start_date', 'end_date'];
}

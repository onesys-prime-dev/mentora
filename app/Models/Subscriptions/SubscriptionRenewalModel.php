<?php

declare(strict_types=1);

namespace App\Models\Subscriptions;

use App\Models\Concerns\AppendOnly;
use CodeIgniter\Model;

/**
 * The renewal history is append-only: each row is a record of something that
 * was agreed or paid, so rows can be added and read, never changed or
 * removed. A mistake is put right with a new row of kind `correction`.
 */
class SubscriptionRenewalModel extends Model
{
    use AppendOnly;

    public const START = 'start';

    public const RENEWAL = 'renewal';

    public const CORRECTION = 'correction';

    protected $table = 'subscription_renewals';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    // Only recorded_at exists, and the service sets it.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'subscription_id',
        'kind',
        'corrects_renewal_id',
        'plan_id',
        'period_start',
        'period_end',
        'amount',
        'currency',
        'reference',
        'notes',
        'recorded_by_user_id',
        'recorded_by_label',
        'recorded_at',
    ];

    protected function appendOnlyName(): string
    {
        return 'Renewal records';
    }
}

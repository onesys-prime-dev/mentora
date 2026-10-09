<?php

declare(strict_types=1);

namespace App\Models\Audit;

use App\Models\Concerns\AppendOnly;
use CodeIgniter\Model;

/**
 * The audit log is append-only: rows can be added and read, never changed or
 * removed. Every route to a change through this model is closed and throws,
 * so a bug or a careless future feature cannot quietly rewrite history.
 * (Old entries are archived, not deleted; see the retention setting.)
 */
class AuditLogModel extends Model
{
    use AppendOnly;

    protected $table = 'audit_logs';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    // Only created_at exists, and the logger sets it, so the model does not.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'actor_user_id',
        'actor_label',
        'acting_role',
        'impersonator_user_id',
        'action',
        'target_type',
        'target_id',
        'institution_id',
        'ip_address',
        'old_values',
        'new_values',
        'created_at',
    ];

    protected function appendOnlyName(): string
    {
        return 'Audit log entries';
    }
}

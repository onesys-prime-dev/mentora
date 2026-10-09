<?php

declare(strict_types=1);

namespace App\Models\Audit;

use CodeIgniter\Model;
use LogicException;

/**
 * The audit log is append-only: rows can be added and read, never changed or
 * removed. Every route to a change through this model is closed and throws,
 * so a bug or a careless future feature cannot quietly rewrite history.
 * (Old entries are archived, not deleted; see the retention setting.)
 */
class AuditLogModel extends Model
{
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

    /**
     * @param array<string, mixed>|object|null $row
     */
    public function update($id = null, $row = null): bool
    {
        throw $this->refuse('changed');
    }

    /** @param array<int, array<string, mixed>>|null $set */
    public function updateBatch(?array $set = null, ?string $index = null, int $batchSize = 100, bool $returnSQL = false)
    {
        throw $this->refuse('changed');
    }

    public function delete($id = null, bool $purge = false)
    {
        throw $this->refuse('deleted');
    }

    public function purgeDeleted()
    {
        throw $this->refuse('deleted');
    }

    /** @param array<string, mixed>|object|null $row */
    public function replace($row = null, bool $returnSQL = false)
    {
        throw $this->refuse('changed');
    }

    private function refuse(string $what): LogicException
    {
        return new LogicException("Audit log entries can never be {$what}.");
    }
}

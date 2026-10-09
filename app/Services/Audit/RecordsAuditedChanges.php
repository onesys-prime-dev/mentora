<?php

declare(strict_types=1);

namespace App\Services\Audit;

use CodeIgniter\Shield\Entities\User;
use Throwable;

/**
 * For services whose changes must never happen without a trace: make the
 * change, then call auditedChange() with how to take it back. If the audit
 * entry cannot be written the change is undone and the failure is rethrown.
 */
trait RecordsAuditedChanges
{
    /**
     * Writes the audit entry for a change that has just been made.
     *
     * @param callable(): mixed                                             $undo          puts the change back
     * @param array{old?: array<string, mixed>, new?: array<string, mixed>} $values        before and after, secrets are redacted by the logger
     * @param int|string                                                    $targetId
     * @param array<string, mixed>                                          $extraDetails more for the log entry, e.g. an actor_label for a change made by the system
     *
     * @throws Throwable whatever stopped the entry from being written, after $undo has run
     */
    protected function auditedChange(callable $undo, string $action, ?User $actor, string $targetType, $targetId, ?int $institutionId, array $values, array $extraDetails = []): void
    {
        try {
            service('auditLogger')->log($action, $actor ?? auth()->user(), $extraDetails + [
                'target_type'    => $targetType,
                'target_id'      => $targetId,
                'institution_id' => $institutionId,
                'old'            => $values['old'] ?? null,
                'new'            => $values['new'] ?? null,
            ]);
        } catch (Throwable $e) {
            $undo();

            throw $e;
        }
    }
}

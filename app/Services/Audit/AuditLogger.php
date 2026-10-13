<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Audit\AuditLogModel;
use App\Services\Auth\ActiveRoleService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Entities\User;

/**
 * Writes audit entries. Get the shared instance with service('auditLogger').
 *
 * `log()` records one thing that happened: who did it, as which role, to
 * what, in which institution, from which address, and (for a change) the old
 * and new values. Secrets in those values are redacted before they are
 * stored. Entries are never changed afterwards (see AuditLogModel).
 */
class AuditLogger
{
    /** Keys whose values are never stored, matched anywhere in the name. */
    private const SECRET_PATTERN = '/pass(word|phrase)?|secret|token|credential|api[_-]?key|private[_-]?key/i';

    /**
     * @param array{
     *     actor_label?: ?string,
     *     acting_role?: ?string,
     *     target_type?: ?string,
     *     target_id?: int|string|null,
     *     institution_id?: ?int,
     *     old?: ?array<string, mixed>,
     *     new?: ?array<string, mixed>,
     * } $details Everything is optional. When $actor is signed in, the label, the role and the
     *            institution are filled in from them unless given.
     *
     * @return int id of the new entry
     */
    public function log(string $action, ?User $actor = null, array $details = []): int
    {
        $institutionId = $details['institution_id'] ?? ($actor !== null ? $this->institutionOf($actor) : null);

        $model = new AuditLogModel();

        $model->insert([
            'actor_user_id'        => $actor?->id,
            'actor_label'          => $details['actor_label'] ?? ($actor !== null ? $this->labelOf($actor) : null),
            'acting_role'          => $details['acting_role'] ?? ($actor !== null ? $this->roleOf($actor) : null),
            'impersonator_user_id' => $this->impersonatorId(),
            'action'               => $action,
            'target_type'          => $details['target_type'] ?? null,
            'target_id'            => isset($details['target_id']) ? (string) $details['target_id'] : null,
            'institution_id'       => $institutionId,
            'ip_address'           => $this->ipAddress(),
            'old_values'           => $this->encode($details['old'] ?? null),
            'new_values'           => $this->encode($details['new'] ?? null),
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        return (int) $model->getInsertID();
    }

    /**
     * Replaces the value of every secret-looking key, at any depth.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_PATTERN, $key) === 1) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    /** @param array<array-key, mixed>|null $values */
    private function encode(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return json_encode(self::redact($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null;
    }

    private function labelOf(User $user): ?string
    {
        return $user->email ?: $user->username;
    }

    /**
     * The role they were acting as when this happened — not just any group
     * they hold, since a multi-role user's audit trail must say which hat
     * they had on. Callers that already know better (e.g. a system action
     * with no real actor) pass `acting_role` instead.
     */
    private function roleOf(User $user): ?string
    {
        $role = (new ActiveRoleService())->resolve($user);

        return $role !== '' ? $role : null;
    }

    private function institutionOf(User $user): ?int
    {
        $row = db_connect()->table('institution_users')->select('institution_id')->where('user_id', $user->id)->get()->getRowArray();

        return $row === null ? null : (int) $row['institution_id'];
    }

    /** The system administrator who is viewing as the actor, when impersonation is active. */
    private function impersonatorId(): ?int
    {
        $id = session('impersonator_id');

        return $id === null ? null : (int) $id;
    }

    /** Null outside a web request (spark commands, queue jobs). */
    private function ipAddress(): ?string
    {
        $request = service('request');

        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $ip = $request->getIPAddress();

        return $ip === '0.0.0.0' ? null : $ip;
    }
}

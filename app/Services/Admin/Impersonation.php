<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Users\UserProfileModel;
use App\Services\Institutions\InstitutionScope;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\AccountControl;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * A system administrator, or an institution's own administrator, viewing the
 * platform as another person (SA5, IA12).
 *
 * It is a view and nothing else. While it lasts the session IS the other
 * person (every page, filter and query already works from "who is signed in"),
 * and the ImpersonationFilter refuses every request that would change
 * something, so nothing the administrator does can write on that person's
 * behalf. The administrator's own identity is kept in the session and
 * restored on exit.
 *
 * Rules: a reason is required; it ends by itself after the platform setting
 * `impersonationMinutes`; one system administrator cannot be viewed as another
 * (or as themselves); the person must have an active account. Starting and
 * ending are in the audit log, and every entry written in between carries the
 * administrator as the impersonator (AuditLogger reads `impersonator_id`).
 *
 * An institution's administrator may view only as a member of their own
 * institution: never as an administrator of any kind, never as themselves, and
 * never as someone whose account is shared with another institution, because
 * the view would show that institution's data too. A person who does not
 * belong to the institution is answered as one who does not exist.
 *
 * Session keys: `impersonator_id` (the administrator) and `impersonation`
 * (target, reason, times and the role to restore).
 */
class Impersonation
{
    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    private const SESSION = 'impersonation';

    private const ADMIN_KEY = 'impersonator_id';

    /**
     * The view in progress, or null.
     *
     * @return array{admin_id: int, target_id: int, reason: string, started_at: int, expires_at: int, previous_role: ?string}|null
     */
    public static function current(): ?array
    {
        $admin = session(self::ADMIN_KEY);
        $state = session(self::SESSION);

        if (! is_numeric($admin) || ! is_array($state) || ! isset($state['target_id'], $state['expires_at'])) {
            return null;
        }

        return [
            'admin_id'      => (int) $admin,
            'target_id'     => (int) $state['target_id'],
            'reason'        => (string) ($state['reason'] ?? ''),
            'started_at'    => (int) ($state['started_at'] ?? 0),
            'expires_at'    => (int) $state['expires_at'],
            'previous_role' => isset($state['previous_role']) ? (string) $state['previous_role'] : null,
        ];
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    /** Whole minutes left (at least 0), or null when there is no view in progress. */
    public static function minutesLeft(?int $now = null): ?int
    {
        $state = self::current();

        return $state === null ? null : max(0, (int) ceil(($state['expires_at'] - ($now ?? time())) / 60));
    }

    /**
     * Starts viewing as someone.
     *
     * @throws InvalidArgumentException saying why not
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function start(User $admin, int $targetId, string $reason, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        if (self::active()) {
            throw new InvalidArgumentException('You are already viewing as someone. Exit that view first.');
        }

        $bySystem      = $admin->inGroup('admin') && $admin->can('impersonation.start');
        $byInstitution = ! $bySystem && $admin->inGroup('institutional_admin') && $admin->can('institution.impersonate');

        if (! $bySystem && ! $byInstitution) {
            throw new InvalidArgumentException('You are not allowed to view as another person.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException('Say why you are viewing as this person (' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters). It is kept in the audit log.');
        }

        if ((int) $admin->id === $targetId) {
            throw new InvalidArgumentException('You cannot view as yourself.');
        }

        if ($byInstitution && ! InstitutionScope::forInstitution($this->institutionOfAdministrator((int) $admin->id))->ownsPerson($targetId)) {
            throw new InvalidArgumentException('That person does not exist.');
        }

        /** @var User|null $target */
        $target = model(UserModel::class)->findById($targetId);

        if ($target === null) {
            throw new InvalidArgumentException('That person does not exist.');
        }

        if ($target->inGroup('admin')) {
            throw new InvalidArgumentException('You cannot view as another system administrator.');
        }

        if ($byInstitution && $target->inGroup('institutional_admin')) {
            throw new InvalidArgumentException('You cannot view as an administrator.');
        }

        if ($byInstitution && $this->institutionCount($targetId) > 1) {
            throw new InvalidArgumentException('That person also belongs to another institution, so their view cannot be shown.');
        }

        if (! $target->isActivated() || (new AccountControl())->isDeactivated($targetId)) {
            throw new InvalidArgumentException('That account is deactivated, so there is nothing to view.');
        }

        $groups = $target->getGroups() ?? [];

        if ($groups === []) {
            throw new InvalidArgumentException('That person has no role, so there is nothing to view.');
        }

        $minutes = (int) (new PlatformSettings())->get('impersonationMinutes');
        $expires = $now->modify("+{$minutes} minutes");

        service('auditLogger')->log('impersonation.started', $admin, [
            'target_type'    => 'user',
            'target_id'      => $targetId,
            'institution_id' => $this->institutionOf($targetId),
            'new'            => ['reason' => $reason, 'expires_at' => $expires->format('Y-m-d H:i:s'), 'minutes' => $minutes],
        ]);

        $previousRole = session('active_role');

        session()->set([
            self::ADMIN_KEY => (int) $admin->id,
            self::SESSION   => [
                'target_id'     => $targetId,
                'reason'        => $reason,
                'started_at'    => $now->getTimestamp(),
                'expires_at'    => $expires->getTimestamp(),
                'previous_role' => is_string($previousRole) ? $previousRole : null,
            ],
            'user'          => ['id' => $targetId],
            'active_role'   => $this->roleFor($targetId, $groups),
        ]);
    }

    /**
     * Ends the view and puts the administrator back.
     *
     * @param string $how stopped (they chose to), expired, or why else
     *
     * @return int|null the person who was being viewed, or null when there was no view
     */
    public function stop(string $how = 'stopped', ?DateTimeImmutable $now = null): ?int
    {
        $state = self::current();

        if ($state === null) {
            return null;
        }

        $now ??= new DateTimeImmutable();

        $session = session();
        $session->set('user', ['id' => $state['admin_id']]);

        if ($state['previous_role'] !== null) {
            $session->set('active_role', $state['previous_role']);
        } else {
            $session->remove('active_role');
        }

        $session->remove([self::ADMIN_KEY, self::SESSION]);

        // The administrator is back, so the entry is theirs. It must not stop them getting out.
        try {
            /** @var User|null $admin */
            $admin = model(UserModel::class)->findById($state['admin_id']);

            service('auditLogger')->log('impersonation.ended', $admin, [
                'target_type'    => 'user',
                'target_id'      => $state['target_id'],
                'institution_id' => $this->institutionOf($state['target_id']),
                'new'            => [
                    'ended'           => $how,
                    'reason'          => $state['reason'],
                    'minutes_watched' => max(0, (int) round(($now->getTimestamp() - $state['started_at']) / 60)),
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Could not record the end of an impersonation: ' . $e->getMessage());
        }

        return $state['target_id'];
    }

    /**
     * The role to show: the one they last used, else the default.
     *
     * @param list<string> $groups
     */
    private function roleFor(int $userId, array $groups): string
    {
        $saved = ((new UserProfileModel())->find($userId) ?? [])['active_role'] ?? null;

        if (is_string($saved) && in_array($saved, $groups, true)) {
            return $saved;
        }

        return \App\Services\Auth\ActiveRoleService::preferredRole($groups);
    }

    /**
     * Where the viewer goes once the view has ended: the person's page for a
     * system administrator, the people list for an institution's administrator.
     *
     * @param array{admin_id: int, target_id: int} $state
     */
    public static function returnPath(array $state): string
    {
        /** @var User|null $viewer */
        $viewer = model(UserModel::class)->findById($state['admin_id']);

        return $viewer !== null && $viewer->inGroup('admin') ? 'admin/users/' . $state['target_id'] : 'institution/people';
    }

    private function institutionOfAdministrator(int $userId): int
    {
        $institutionId = (new PlanLimits())->institutionOf($userId);

        if ($institutionId === null) {
            throw new InvalidArgumentException('You are not allowed to view as another person.');
        }

        return $institutionId;
    }

    private function institutionCount(int $userId): int
    {
        return db_connect()->table('institution_users')->where('user_id', $userId)->countAllResults();
    }

    /** The one institution a person belongs to, for the audit entry (null when none or several). */
    private function institutionOf(int $userId): ?int
    {
        $rows = db_connect()->table('institution_users')->select('institution_id')->where('user_id', $userId)->get()->getResultArray();

        return count($rows) === 1 ? (int) $rows[0]['institution_id'] : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\Academic\InstitutionModel;
use App\Models\Users\UserProfileModel;
use App\Services\Concerns\InTransaction;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\InvitationInput;
use App\Services\Institutions\InvitationService;
use App\Services\Sis\SisOwnership;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What a system administrator can do to people (SA5): invite someone (the way
 * an account is created), edit, deactivate and reactivate, end their sessions,
 * change their roles and which institutions they belong to, and do the
 * deactivation in bulk.
 *
 * Rules that are enforced here, not in the pages:
 *  - A system administrator account is created only with `php spark
 *    admin:create`, and its roles are not changed from the screen. Nobody can
 *    be made one, or unmade one, here.
 *  - You cannot deactivate yourself, and the last active system administrator
 *    cannot be deactivated, so the platform is never left without one.
 *  - A student takes a seat: inviting one, reactivating one, or adding the
 *    student role is refused when the plan's seats are all in use.
 *  - A person always has at least one role.
 *
 * Every change is recorded in the audit log in the same transaction, so it
 * does not happen if it cannot be recorded. Every refusal is an
 * InvalidArgumentException whose message is fit to show.
 */
class UserAdminService
{
    use InTransaction;

    /** The roles an administrator may give someone from the screen. */
    public const ASSIGNABLE_ROLES = ['institutional_admin', 'dean', 'chairperson', 'instructor', 'student', 'parent'];

    private UserDirectory $directory;

    private AccountControl $accounts;

    public function __construct(?UserDirectory $directory = null, ?AccountControl $accounts = null)
    {
        $this->directory = $directory ?? new UserDirectory();
        $this->accounts  = $accounts ?? new AccountControl();
    }

    /**
     * One user with roles and institutions.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function find(int $userId): array
    {
        return $this->directory->find($userId) ?? throw new InvalidArgumentException('That user does not exist.');
    }

    /*
    |--------------------------------------------------------------------------
    | Creating
    |--------------------------------------------------------------------------
    */

    /**
     * Creates an account the way every other one is made: an invitation, so
     * the person chooses their own password. The account exists once they do.
     *
     * @param array<string, mixed> $input first_name, last_name, email
     *
     * @return array{invitation_id: int, email: string, expires_at: string, email_error: ?string}
     *
     * @throws InvalidArgumentException
     */
    public function invite(int $institutionId, string $role, array $input, ?User $actor = null, ?DateTimeImmutable $now = null): array
    {
        if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
            throw new InvalidArgumentException('Choose a role for the new person.');
        }

        $institution = (new InstitutionModel())->find($institutionId)
            ?? throw new InvalidArgumentException('Choose the institution the person belongs to.');

        if ($institution['status'] === InstitutionModel::PENDING) {
            throw new InvalidArgumentException('Finish onboarding that institution before inviting people to it.');
        }

        return (new InvitationService())->invite($institutionId, $input, $actor, $now, $role);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    /**
     * Changes a person's name and sign-in email.
     *
     * @param array<string, mixed> $input first_name, last_name, email
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function update(int $userId, array $input, ?User $actor = null): void
    {
        $user   = $this->find($userId);
        $person = InvitationInput::person($input);

        $taken = db_connect()->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $person['email'])
            ->where('user_id !=', $userId)
            ->countAllResults();

        if ($taken > 0) {
            throw new InvalidArgumentException("An account with {$person['email']} already exists.");
        }

        $old = ['first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'email' => $user['email']];

        if ($old === $person) {
            return;
        }

        if ((new SisOwnership())->managesPerson($userId)) {
            throw new InvalidArgumentException('This person is managed by the school\'s student information system. Change their name and email there; the next sync brings it here.');
        }

        $this->inTransaction(function () use ($userId, $person, $old, $actor, $user): void {
            $db    = db_connect();
            $stamp = date('Y-m-d H:i:s');

            $db->table('auth_identities')->where('user_id', $userId)->where('type', 'email_password')->update(['secret' => $person['email'], 'updated_at' => $stamp]);

            $profiles = new UserProfileModel();

            if ($profiles->find($userId) === null) {
                $saved = $profiles->insert(['user_id' => $userId, 'first_name' => $person['first_name'], 'last_name' => $person['last_name']]);
            } else {
                $saved = $profiles->update($userId, ['first_name' => $person['first_name'], 'last_name' => $person['last_name']]);
            }

            if ($saved === false) {
                throw new InvalidArgumentException(implode(' ', $profiles->errors()) ?: 'The name could not be saved.');
            }

            $this->audit('user.updated', $actor, $userId, $user, ['old' => $old, 'new' => $person]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Switching an account on and off
    |--------------------------------------------------------------------------
    */

    /**
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function deactivate(int $userId, ?User $actor = null, ?DateTimeImmutable $now = null): void
    {
        $user  = $this->find($userId);
        $actor ??= auth()->user();

        if ($actor !== null && (int) $actor->id === $userId) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        if ((int) $user['active'] === 0 && $user['deactivated_at'] !== null) {
            throw new InvalidArgumentException('That account is already deactivated.');
        }

        if (in_array('admin', $user['roles'], true) && (int) $user['active'] === 1 && $this->directory->activeAdministrators() <= 1) {
            throw new InvalidArgumentException('That is the last active system administrator. Another one must be active before this account can be deactivated.');
        }

        $this->inTransaction(function () use ($userId, $actor, $user, $now): void {
            $this->accounts->deactivate($userId, $now);

            // An account that is off cannot decide anything: the deanship ends and waiting requests move up.
            (new DeanAssignmentService())->endForPerson($userId, $actor);

            $this->audit('user.deactivated', $actor, $userId, $user, ['old' => ['active' => (int) $user['active']], 'new' => ['active' => 0]]);
        });
    }

    /**
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function reactivate(int $userId, ?User $actor = null, ?DateTimeImmutable $now = null): void
    {
        $user = $this->find($userId);

        if ((int) $user['active'] === 1 && $user['deactivated_at'] === null) {
            throw new InvalidArgumentException('That account is already active.');
        }

        // A student takes a seat again.
        if (in_array('student', $user['roles'], true)) {
            foreach ($user['institutions'] as $institution) {
                if (($blocked = (new PlanLimits())->seatBlock((int) $institution['id'])) !== null) {
                    throw new InvalidArgumentException($blocked);
                }
            }
        }

        $this->inTransaction(function () use ($userId, $actor, $user, $now): void {
            $this->accounts->reactivate($userId, $now);

            $this->audit('user.reactivated', $actor, $userId, $user, ['old' => ['active' => (int) $user['active']], 'new' => ['active' => 1]]);
        });
    }

    /**
     * Ends every session the person has.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function signOut(int $userId, ?User $actor = null, ?DateTimeImmutable $now = null): void
    {
        $user = $this->find($userId);

        $this->inTransaction(function () use ($userId, $actor, $user, $now): void {
            $this->accounts->signOutEverywhere($userId, $now);

            $this->audit('user.signed_out', $actor, $userId, $user, []);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Roles and institutions
    |--------------------------------------------------------------------------
    */

    /**
     * Replaces a person's roles.
     *
     * @param list<string> $roles
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written; nothing is changed then
     */
    public function setRoles(int $userId, array $roles, ?User $actor = null): void
    {
        $user  = $this->find($userId);
        $roles = array_values(array_unique($roles));

        if (in_array('admin', $user['roles'], true)) {
            throw new InvalidArgumentException('A system administrator\'s roles are not changed here. System administrators are created and managed with php spark.');
        }

        if ($roles === []) {
            throw new InvalidArgumentException('Choose at least one role: a person with no role cannot use the site.');
        }

        foreach ($roles as $role) {
            if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
                throw new InvalidArgumentException($role === 'admin'
                    ? 'Nobody is made a system administrator here. System administrators are created with php spark admin:create.'
                    : 'That is not a role.');
            }
        }

        $before = $user['roles'];

        $added = array_diff($roles, $before);

        if (in_array('student', $added, true)) {
            foreach ($user['institutions'] as $institution) {
                if (($blocked = (new PlanLimits())->seatBlock((int) $institution['id'])) !== null) {
                    throw new InvalidArgumentException($blocked);
                }
            }
        }

        $sortedBefore = $before;
        $sortedAfter  = $roles;
        sort($sortedBefore);
        sort($sortedAfter);

        if ($sortedBefore === $sortedAfter) {
            return;
        }

        $this->inTransaction(function () use ($userId, $roles, $before, $actor, $user): void {
            /** @var User $entity */
            $entity = model(UserModel::class)->findById($userId);
            $entity->syncGroups(...$roles);

            // A dean who is no longer a dean is no longer in charge of a department; their requests move up.
            if (in_array('dean', $before, true) && ! in_array('dean', $roles, true)) {
                (new DeanAssignmentService())->endForPerson($userId, $actor);
            }

            $this->audit('user.roles_changed', $actor, $userId, $user, ['old' => ['roles' => $before], 'new' => ['roles' => $roles]]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | In bulk
    |--------------------------------------------------------------------------
    */

    /**
     * Deactivates or reactivates several people. Each one is checked on its
     * own, so one refusal (yourself, the last administrator, no seat) does not
     * stop the rest.
     *
     * @param 'deactivate'|'reactivate' $action
     * @param list<int>                 $ids
     *
     * @return array{done: list<int>, skipped: array<int, string>} who it worked for, and who it did not and why
     *
     * @throws InvalidArgumentException for an unknown action or nobody chosen
     */
    public function bulk(string $action, array $ids, ?User $actor = null, ?DateTimeImmutable $now = null): array
    {
        if (! in_array($action, ['deactivate', 'reactivate'], true)) {
            throw new InvalidArgumentException('That is not something that can be done to several people at once.');
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            throw new InvalidArgumentException('Choose at least one person first.');
        }

        $result = ['done' => [], 'skipped' => []];

        foreach ($ids as $id) {
            try {
                $action === 'deactivate' ? $this->deactivate($id, $actor, $now) : $this->reactivate($id, $actor, $now);

                $result['done'][] = $id;
            } catch (InvalidArgumentException $e) {
                $result['skipped'][$id] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                                          $user   the user as read by the directory
     * @param array{old?: array<string, mixed>, new?: array<string, mixed>} $values
     */
    private function audit(string $action, ?User $actor, int $userId, array $user, array $values, ?int $institutionId = null): void
    {
        $institutions = $user['institutions'] ?? [];

        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'user',
            'target_id'      => $userId,
            'institution_id' => $institutionId ?? (count($institutions) === 1 ? (int) $institutions[0]['id'] : null),
            'old'            => $values['old'] ?? null,
            'new'            => $values['new'] ?? null,
        ]);
    }
}

<?php

namespace App\Services\Auth;

use App\Models\Assignments\DeanAssignmentModel;
use App\Models\Users\UserProfileModel;
use CodeIgniter\Shield\Entities\User;

/**
 * A multi-role user (e.g. instructor + chairperson) has exactly one role
 * "active" at a time — what decides which dashboard they land on and which
 * UI they see. Never trust the session alone: it's always re-checked against
 * the user's real Shield groups, so a role revoked mid-session can't linger.
 */
class ActiveRoleService
{
    protected UserProfileModel $profileModel;

    public function __construct()
    {
        $this->profileModel = new UserProfileModel();
    }

    /**
     * The role this request should act as. Session is the fast path; a saved
     * `user_profiles.active_role` is the fallback so the choice survives a
     * fresh login (a new session). Either way the result is re-validated
     * against the user's current groups before it's trusted or persisted.
     */
    public function resolve(User $user): string
    {
        $groups = $this->usableGroups($user);

        if ($groups === []) {
            return '';
        }

        $sessionRole = session('active_role');

        if (is_string($sessionRole) && in_array($sessionRole, $groups, true)) {
            return $sessionRole;
        }

        $savedRole = ($this->profileModel->find($user->id) ?? [])['active_role'] ?? null;

        $role = (is_string($savedRole) && in_array($savedRole, $groups, true))
            ? $savedRole
            : $this->defaultRole($groups);

        $this->activate($user, $role);

        return $role;
    }

    /** Switches to $role only if the user actually holds it. Persists to session + profile. */
    public function switchTo(User $user, string $role): bool
    {
        if (! in_array($role, $this->usableGroups($user), true)) {
            return false;
        }

        $this->activate($user, $role);

        return true;
    }

    /**
     * The highest role held opens first (dean, then chairperson); otherwise
     * their only or first group.
     *
     * @param list<string> $groups
     */
    public static function preferredRole(array $groups): string
    {
        foreach (['dean', 'chairperson'] as $role) {
            if (in_array($role, $groups, true)) {
                return $role;
            }
        }

        return $groups[0];
    }

    protected function defaultRole(array $groups): string
    {
        return self::preferredRole($groups);
    }

    /**
     * The groups the user can act as right now. A dean without an active
     * assignment cannot act as dean while they hold another role; a dean
     * with nothing else keeps it, so they land on the no-assignment page.
     *
     * @return list<string>
     */
    protected function usableGroups(User $user): array
    {
        $groups = array_values(array_filter($user->getGroups() ?? [], 'is_string'));

        if (! in_array('dean', $groups, true)) {
            return $groups;
        }

        $others = array_values(array_diff($groups, ['dean']));

        if ($others === [] || (new DeanAssignmentModel())->departmentOfDean((int) $user->id) !== null) {
            return $groups;
        }

        return $others;
    }

    protected function activate(User $user, string $role): void
    {
        session()->set('active_role', $role);

        $this->profileModel->update($user->id, ['active_role' => $role]);
    }
}

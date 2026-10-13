<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Users\PasswordResetService;
use App\Services\Users\UserAdminService;
use App\Services\Users\UserDirectory;
use App\Services\Users\UserFilters;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Generator;
use InvalidArgumentException;

/**
 * An institution's people, as its institutional administrator keeps them:
 * the list, invitations, editing, switching accounts on and off, password
 * links, roles, and taking someone out of the institution.
 *
 * It is a front for the system administrator's UserAdminService with the
 * rules of this area added:
 *  - Only people of the administrator's own institution are reachable (404
 *    otherwise); system administrators are never among them.
 *  - A person who also belongs to another institution has an account that is
 *    not this administrator's to change: name, email, status, roles and
 *    sessions are left alone. They can be removed from this institution.
 *  - The institution always keeps one active institutional administrator:
 *    the last one cannot be deactivated, demoted or removed, and nobody
 *    takes the role from themselves.
 *  - Ending someone's chairperson role, deactivating them, or removing them
 *    ends their chairperson assignments here (history is kept).
 *  - Seats are checked wherever a student account is added or brought back
 *    (UserAdminService and InvitationService do that).
 *  - What is shown never names another institution.
 */
class PeopleService
{
    public const PER_PAGE = 25;

    public const ROLES = UserAdminService::ASSIGNABLE_ROLES;

    private UserAdminService $users;

    private UserDirectory $directory;

    public function __construct(?UserAdminService $users = null, ?UserDirectory $directory = null)
    {
        $this->directory = $directory ?? new UserDirectory();
        $this->users     = $users ?? new UserAdminService($this->directory);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The list's filters, scoped to the institution and without the system
     * administrator role.
     *
     * @param array<string, mixed> $input usually the query string
     */
    public function filters(int $institutionId, array $input): UserFilters
    {
        $f = UserFilters::fromInput($input);

        return new UserFilters(
            q: $f->q,
            institutionId: $institutionId,
            role: $f->role !== 'admin' ? $f->role : null,
            status: $f->status,
            sort: $f->sort,
            dir: $f->dir,
            page: $f->page,
            perPage: self::PER_PAGE,
        );
    }

    /**
     * One page of people.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, filters: UserFilters}
     */
    public function list(int $institutionId, array $input, int $actorId): array
    {
        $filters = $this->filters($institutionId, $input);
        $result  = $this->directory->search($filters);
        $pages   = max(1, (int) ceil($result['total'] / $filters->perPage));

        if ($filters->page > $pages) {
            $filters = new UserFilters($filters->q, $institutionId, $filters->role, $filters->status, $filters->sort, $filters->dir, $pages, $filters->perPage);
            $result  = $this->directory->search($filters);
        }

        return ['rows' => $this->shape($institutionId, $result['rows'], $actorId), 'total' => $result['total'], 'pages' => $pages, 'filters' => $filters];
    }

    /**
     * Everyone matching the filters, for the CSV, naming only this institution.
     *
     * @param array<string, mixed> $input
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function each(int $institutionId, array $input, int $max): Generator
    {
        foreach ($this->directory->each($this->filters($institutionId, $input), $max) as $row) {
            yield $this->forExport($institutionId, $row);
        }
    }

    /**
     * The chosen people (those of this institution), for the CSV.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function byIds(int $institutionId, array $ids): array
    {
        $scope = InstitutionScope::forInstitution($institutionId);
        $own   = array_values(array_filter($ids, static fn (int $id): bool => $scope->ownsPerson($id)));

        return array_map(fn (array $row): array => $this->forExport($institutionId, $row), $this->directory->byIds($own));
    }

    /**
     * Invitations that were sent and not yet answered or that lapsed.
     *
     * @return list<array<string, mixed>>
     */
    public function invitations(int $institutionId): array
    {
        $rows = (new InvitationService())->forInstitution($institutionId, null, 100);

        return array_values(array_filter($rows, static fn (array $row): bool => in_array($row['status'], [InvitationStatus::PENDING, InvitationStatus::EXPIRED], true)));
    }

    /** How many invitations are open, for the tab. */
    public function openInvitations(int $institutionId): int
    {
        return count(array_filter($this->invitations($institutionId), static fn (array $row): bool => $row['status'] === InvitationStatus::PENDING));
    }

    /** Active institutional administrators of the institution. */
    public function activeAdministrators(int $institutionId): int
    {
        return (int) db_connect()->table('institution_users iu')
            ->select('COUNT(DISTINCT u.id) AS total', false)
            ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL AND u.active = 1', 'inner', false)
            ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'institutional_admin'", 'inner', false)
            ->where('iu.institution_id', $institutionId)
            ->get()->getRowArray()['total'];
    }

    /*
    |--------------------------------------------------------------------------
    | Inviting
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input first_name, last_name, email
     *
     * @return array{invitation_id: int, email: string, expires_at: string, email_error: ?string}
     *
     * @throws StructureException
     */
    public function invite(int $institutionId, string $role, array $input, ?User $actor = null): array
    {
        if (! in_array($role, self::ROLES, true)) {
            throw StructureException::field('role', 'Choose a role for the new person.');
        }

        $this->person($input);

        try {
            return (new InvitationService())->invite($institutionId, $input, $actor, null, $role);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }
    }

    /**
     * @return array{invitation_id: int, email: string, expires_at: string, email_error: ?string}
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function resend(int $institutionId, int $invitationId, ?User $actor = null): array
    {
        $this->ownInvitation($institutionId, $invitationId);

        try {
            return (new InvitationService())->resend($institutionId, $invitationId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }
    }

    /**
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function revoke(int $institutionId, int $invitationId, ?User $actor = null): void
    {
        $this->ownInvitation($institutionId, $invitationId);

        try {
            (new InvitationService())->revoke($institutionId, $invitationId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Changing a person
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $input first_name, last_name, email
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function update(int $institutionId, int $userId, array $input, ?User $actor = null): void
    {
        $person = $this->member($institutionId, $userId);
        $this->accountIsOurs($person);
        $this->person($input);

        try {
            $this->users->update($userId, $input, $actor);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();

            throw str_contains($message, 'already exists') ? StructureException::field('email', $message) : $this->asRefusal($e);
        }
    }

    /**
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function deactivate(int $institutionId, int $userId, ?User $actor = null): void
    {
        $person = $this->member($institutionId, $userId);
        $this->accountIsOurs($person);
        $this->mustNotBeLastAdministrator($institutionId, $person, 'deactivated');

        try {
            $this->users->deactivate($userId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }

        $this->endChairAssignments($institutionId, $userId, $actor);
    }

    /**
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function reactivate(int $institutionId, int $userId, ?User $actor = null): void
    {
        $person = $this->member($institutionId, $userId);
        $this->accountIsOurs($person);

        try {
            $this->users->reactivate($userId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }
    }

    /**
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function signOut(int $institutionId, int $userId, ?User $actor = null): void
    {
        $person = $this->member($institutionId, $userId);
        $this->accountIsOurs($person);

        try {
            $this->users->signOut($userId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }
    }

    /**
     * Emails the person a link to choose a new password.
     *
     * @return array{email: string, expires_at: string, email_error: ?string}
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function resetPassword(int $institutionId, int $userId, ?User $actor = null): array
    {
        $person = $this->member($institutionId, $userId);
        $this->accountIsOurs($person);

        try {
            return (new PasswordResetService())->send($userId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }
    }

    /**
     * Replaces the person's roles with the given ones.
     *
     * @param list<string> $roles
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function setRoles(int $institutionId, int $userId, array $roles, ?User $actor = null): void
    {
        $person = $this->member($institutionId, $userId);
        $this->accountIsOurs($person);

        $roles = array_values(array_unique(array_map('strval', $roles)));

        if ($roles === []) {
            throw StructureException::field('roles', 'Choose at least one role: a person with no role cannot use the site.');
        }

        foreach ($roles as $role) {
            if (! in_array($role, self::ROLES, true)) {
                throw StructureException::field('roles', 'That is not a role you can give.');
            }
        }

        $wasAdministrator = in_array('institutional_admin', $person['roles'], true);

        if ($wasAdministrator && ! in_array('institutional_admin', $roles, true)) {
            if ($actor !== null && (int) $actor->id === $userId) {
                throw StructureException::field('roles', 'You cannot take the administrator role from yourself. Ask another administrator to do it.');
            }

            $this->mustNotBeLastAdministrator($institutionId, $person, 'demoted');
        }

        try {
            $this->users->setRoles($userId, $roles, $actor);
        } catch (InvalidArgumentException $e) {
            throw StructureException::field('roles', $e->getMessage());
        }

        if (in_array('chairperson', $person['roles'], true) && ! in_array('chairperson', $roles, true)) {
            $this->endChairAssignments($institutionId, $userId, $actor);
        }
    }

    /**
     * Takes someone who also belongs elsewhere out of this institution. They
     * keep their account and their other membership.
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function removeFromInstitution(int $institutionId, int $userId, ?User $actor = null): void
    {
        $person = $this->member($institutionId, $userId);

        if (count($person['institutions']) < 2) {
            throw new StructureException('This person belongs only to your institution, so there is nowhere for them to go. Deactivate the account instead.');
        }

        $this->mustNotBeLastAdministrator($institutionId, $person, 'removed');

        try {
            $this->users->removeMembership($userId, $institutionId, $actor);
        } catch (InvalidArgumentException $e) {
            throw $this->asRefusal($e);
        }

        $this->endChairAssignments($institutionId, $userId, $actor);
    }

    /**
     * Deactivates or reactivates several people. Each is checked on its own.
     *
     * @param 'deactivate'|'reactivate' $action
     * @param list<int>                 $ids
     *
     * @return array{done: int, skipped: list<array{name: string, reason: string}>}
     *
     * @throws StructureException
     */
    public function bulk(int $institutionId, string $action, array $ids, ?User $actor = null): array
    {
        if (! in_array($action, ['deactivate', 'reactivate'], true)) {
            throw new StructureException('That is not something that can be done to several people at once.');
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            throw new StructureException('Choose at least one person first.');
        }

        $done    = 0;
        $skipped = [];

        foreach ($ids as $id) {
            try {
                $action === 'deactivate' ? $this->deactivate($institutionId, $id, $actor) : $this->reactivate($institutionId, $id, $actor);
                $done++;
            } catch (PageNotFoundException) {
                $skipped[] = ['name' => "Person {$id}", 'reason' => 'is not one of your people'];
            } catch (StructureException $e) {
                $skipped[] = ['name' => $this->nameOf($id), 'reason' => $e->getMessage()];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The person, if they belong to this institution (and are not a system
     * administrator): anything else is a 404.
     *
     * @return array<string, mixed>
     *
     * @throws PageNotFoundException
     */
    private function member(int $institutionId, int $userId): array
    {
        InstitutionScope::forInstitution($institutionId)->require('person', $userId);

        $person = $this->directory->find($userId);

        if ($person === null || in_array('admin', $person['roles'], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $person;
    }

    /**
     * @param array<string, mixed> $person
     *
     * @throws StructureException
     */
    private function accountIsOurs(array $person): void
    {
        if (count($person['institutions']) > 1) {
            throw new StructureException($this->displayName($person) . ' also belongs to another institution, so their account is not yours to change. You can remove them from your institution instead.');
        }
    }

    /**
     * @param array<string, mixed> $person
     *
     * @throws StructureException
     */
    private function mustNotBeLastAdministrator(int $institutionId, array $person, string $what): void
    {
        $isAdministrator = in_array('institutional_admin', $person['roles'], true) && (int) $person['active'] === 1;

        if ($isAdministrator && $this->activeAdministrators($institutionId) <= 1) {
            throw new StructureException($this->displayName($person) . " is the last active administrator of your institution, so they cannot be {$what}. Make someone else an administrator first.");
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws StructureException
     */
    private function person(array $input): void
    {
        try {
            InvitationInput::person($input);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();

            $field = match (true) {
                str_starts_with($message, 'The first name') => 'first_name',
                str_starts_with($message, 'The last name')  => 'last_name',
                default                                     => 'email',
            };

            throw StructureException::field($field, $message);
        }
    }

    private function asRefusal(InvalidArgumentException $e): StructureException
    {
        return $e instanceof StructureException ? $e : new StructureException($e->getMessage());
    }

    /**
     * @throws PageNotFoundException
     */
    private function ownInvitation(int $institutionId, int $invitationId): void
    {
        $found = db_connect()->table('institution_invitations')->where(['id' => $invitationId, 'institution_id' => $institutionId])->countAllResults();

        if ($found === 0) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function endChairAssignments(int $institutionId, int $userId, ?User $actor): void
    {
        $ids = db_connect()->table('chairperson_assignments ca')
            ->select('ca.id')
            ->join('sub_departments sd', 'sd.id = ca.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('ca.chairperson_user_id', $userId)
            ->where('ca.status', 'active')
            ->where('d.institution_id', $institutionId)
            ->get()->getResultArray();

        $service = new ChairAssignmentService();

        foreach ($ids as $row) {
            $service->revoke($institutionId, (int) $row['id'], $actor);
        }
    }

    /**
     * Rows for the page: names, labels and flags, never another institution.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function shape(int $institutionId, array $rows, int $actorId): array
    {
        $managed   = $this->sisManaged(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $admins    = $this->activeAdministrators($institutionId);
        $shaped    = [];

        foreach ($rows as $r) {
            $id        = (int) $r['id'];
            $active    = (int) $r['active'] === 1 && $r['deactivated_at'] === null;
            $isAdmin   = in_array('institutional_admin', $r['roles'], true);
            $elsewhere = count($r['institutions']) > 1;

            $shaped[] = [
                'id'             => $id,
                'name'           => $this->displayName($r),
                'first_name'     => (string) ($r['first_name'] ?? ''),
                'last_name'      => (string) ($r['last_name'] ?? ''),
                'email'          => (string) ($r['email'] ?? ''),
                'roles'          => array_values(array_diff($r['roles'], ['admin'])),
                'active'         => $active,
                'last_active'    => $r['last_active'],
                'elsewhere'      => $elsewhere,
                'sis'            => in_array($id, $managed, true),
                'self'           => $id === $actorId,
                // What view-as accepts: a live account of this institution alone that is not an administrator's.
                'viewable'       => $active && $id !== $actorId && ! $isAdmin && ! $elsewhere,
                'last_admin'     => $isAdmin && $active && $admins <= 1,
            ];
        }

        return $shaped;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function forExport(int $institutionId, array $row): array
    {
        $row['institutions'] = array_values(array_filter($row['institutions'], static fn (array $i): bool => (int) $i['id'] === $institutionId));
        $row['roles']        = array_values(array_diff($row['roles'], ['admin']));

        return $row;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sisManaged(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = db_connect()->table('sis_links')
            ->select('sis_links.local_id')
            ->join('sis_connections', 'sis_connections.institution_id = sis_links.institution_id')
            ->where('sis_links.entity', 'person')
            ->where('sis_connections.enabled', 1)
            ->whereIn('sis_links.local_id', $ids)
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['local_id'], $rows);
    }

    /** @param array<string, mixed> $person */
    private function displayName(array $person): string
    {
        $name = trim(((string) ($person['first_name'] ?? '')) . ' ' . ((string) ($person['last_name'] ?? '')));

        return $name !== '' ? $name : (string) ($person['email'] ?? $person['username'] ?? 'This person');
    }

    private function nameOf(int $userId): string
    {
        $person = $this->directory->find($userId);

        return $person === null ? "Person {$userId}" : $this->displayName($person);
    }
}

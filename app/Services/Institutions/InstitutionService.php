<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Academic\InstitutionModel;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\RecordsAuditedChanges;
use App\Services\Subscriptions\SubscriptionService;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What a system administrator can do to an institution (SA4): create, edit,
 * suspend, delete and restore, plus the list. Every action is checked here,
 * recorded in the audit log, and undone if it cannot be recorded, so a
 * customer's state never changes without a trace.
 *
 * Reactivating a past institution is not here: it happens by renewing the
 * subscription. Purging a deleted one is a separate, later step.
 *
 * Every refusal is an InvalidArgumentException whose message is fit to show.
 */
class InstitutionService
{
    use RecordsAuditedChanges;

    /** Letters, numbers, dashes and underscores; 2 to 50 characters. */
    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{1,49}$/';

    /**
     * One page of institutions matching the filters.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(InstitutionFilters $filters): array
    {
        $model = (new InstitutionModel())
            ->select('institutions.*, plans.name AS plan_name')
            ->join('subscriptions', 'subscriptions.institution_id = institutions.id', 'left')
            ->join('plans', 'plans.id = subscriptions.plan_id', 'left');

        if ($filters->planId !== null) {
            $model->where('subscriptions.plan_id', $filters->planId);
        }

        if ($filters->status === 'deleted') {
            $model->onlyDeleted();
        } elseif ($filters->status !== null) {
            $model->where('institutions.status', $filters->status);
        }

        if ($filters->q !== null) {
            $like = db_connect()->escapeLikeString($filters->q);

            $model->groupStart()
                ->like('institutions.name', $like, 'both', true)
                ->orLike('institutions.code', $like, 'both', true)
                ->groupEnd();
        }

        $total = $model->countAllResults(false);

        $rows = $model
            ->orderBy('institutions.' . $filters->sort, $filters->dir)
            ->orderBy('institutions.id', $filters->dir)
            ->findAll($filters->perPage, ($filters->page - 1) * $filters->perPage);

        return ['rows' => $rows, 'total' => $total];
    }

    /** One institution, deleted or not, or null. */
    public function find(int $id, bool $includeDeleted = false): ?array
    {
        $model = new InstitutionModel();

        return $includeDeleted ? $model->withDeleted()->find($id) : $model->find($id);
    }

    /**
     * Creates an institution. It starts `pending`: it becomes usable when
     * onboarding finishes.
     *
     * @return int the new institution's id
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function create(string $name, string $code, ?User $actor = null): int
    {
        $name = trim($name);
        $code = strtoupper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('The code must be 2 to 50 letters, numbers, dashes or underscores.');
        }

        $model = new InstitutionModel();
        $id    = $model->insert(['name' => $name, 'code' => $code, 'status' => InstitutionModel::PENDING]);

        if ($id === false) {
            throw new InvalidArgumentException(implode(' ', $model->errors()));
        }

        $id = (int) $id;

        $this->recorded(
            static fn () => (new InstitutionModel())->delete($id, true),
            'institution.created',
            $actor,
            $id,
            ['new' => ['name' => $name, 'code' => $code, 'status' => InstitutionModel::PENDING]],
        );

        return $id;
    }

    /**
     * Renames an institution. Its code never changes.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function rename(int $id, string $name, ?User $actor = null): void
    {
        $institution = $this->existing($id);
        $name        = trim($name);

        if ($name === $institution['name']) {
            return;
        }

        $model = new InstitutionModel();

        if (! $model->update($id, ['name' => $name])) {
            throw new InvalidArgumentException(implode(' ', $model->errors()) ?: 'The name could not be saved.');
        }

        $this->recorded(
            static fn () => (new InstitutionModel())->update($id, ['name' => $institution['name']]),
            'institution.updated',
            $actor,
            $id,
            ['old' => ['name' => $institution['name']], 'new' => ['name' => $name]],
        );
    }

    /**
     * Moves an active or read-only institution to `past`: data kept, read-only,
     * renewable.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function suspend(int $id, ?User $actor = null): void
    {
        $institution = $this->existing($id);
        $before      = $institution['status'];

        if (! in_array($before, [InstitutionModel::ACTIVE, InstitutionModel::READ_ONLY], true)) {
            throw new InvalidArgumentException('Only an active or read-only institution can be suspended.');
        }

        (new InstitutionModel())->update($id, ['status' => InstitutionModel::PAST]);

        $this->recorded(
            static fn () => (new InstitutionModel())->update($id, ['status' => $before]),
            'institution.suspended',
            $actor,
            $id,
            ['old' => ['status' => $before], 'new' => ['status' => InstitutionModel::PAST]],
        );
    }

    /**
     * Deletes an institution (soft: it can be restored until it is purged).
     * The administrator must type its exact name, and an active institution
     * must be suspended first.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function delete(int $id, string $typedName, ?User $actor = null): void
    {
        $institution = $this->existing($id);

        if ($institution['status'] === InstitutionModel::ACTIVE) {
            throw new InvalidArgumentException('This institution is active. Suspend it before deleting it.');
        }

        $subscription = (new SubscriptionService())->forInstitution($id);

        if ($subscription !== null && (new SubscriptionService())->isRunning($id)) {
            throw new InvalidArgumentException("This institution has a subscription that runs until {$subscription['end_date']}. It can be deleted once that has ended.");
        }

        if (trim($typedName) !== $institution['name']) {
            throw new InvalidArgumentException('Type the institution\'s exact name to confirm the deletion.');
        }

        (new InstitutionModel())->delete($id);

        $this->recorded(
            static fn () => (new InstitutionModel())->restore($id),
            'institution.deleted',
            $actor,
            $id,
            ['old' => ['name' => $institution['name'], 'status' => $institution['status']]],
        );
    }

    /**
     * Brings a deleted institution back with the status it had.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function restore(int $id, ?User $actor = null): void
    {
        $institution = $this->find($id, true);

        if ($institution === null || $institution['deleted_at'] === null) {
            throw new InvalidArgumentException('That institution is not deleted.');
        }

        (new InstitutionModel())->restore($id);

        $this->recorded(
            static fn () => (new InstitutionModel())->delete($id),
            'institution.restored',
            $actor,
            $id,
            ['new' => ['name' => $institution['name'], 'status' => $institution['status']]],
        );
    }

    /**
     * Permanently removes a deleted institution and everything under it
     * (terms, departments, programs, courses, offerings, enrolments, work and
     * grades: the database cascades from the institution row).
     *
     * User accounts are NOT removed: they are not part of an institution's
     * tree. How many are left without any institution is written to the audit
     * entry so they can be dealt with in user management.
     *
     * The audit entry is written first, so nothing irreversible happens
     * without a trace; if the removal then fails, a second entry says so.
     * The audit entry outlives the institution (it has no foreign keys).
     *
     * @param string|null $typedName when given (a person is purging), must be the institution's exact name
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry or the removal
     */
    public function purge(int $id, ?string $typedName = null, ?User $actor = null, string $actorLabel = ''): void
    {
        $institution = $this->find($id, true);

        if ($institution === null || $institution['deleted_at'] === null) {
            throw new InvalidArgumentException('Only a deleted institution can be purged.');
        }

        if ($typedName !== null && trim($typedName) !== $institution['name']) {
            throw new InvalidArgumentException('Type the institution\'s exact name to confirm the purge.');
        }

        $details = [
            'target_type'    => 'institution',
            'target_id'      => $id,
            'institution_id' => $id,
            'old'            => array_merge(
                ['name' => $institution['name'], 'code' => $institution['code'], 'status' => $institution['status'], 'deleted_at' => $institution['deleted_at']],
                $this->contents($id),
            ),
        ];

        if ($actorLabel !== '') {
            $details['actor_label'] = $actorLabel;
        }

        $audit = service('auditLogger');
        $who   = $actor ?? auth()->user();

        $audit->log('institution.purged', $who, $details);

        try {
            (new InstitutionModel())->delete($id, true);
        } catch (Throwable $e) {
            $audit->log('institution.purge_failed', $who, array_merge($details, ['old' => null, 'new' => ['reason' => $e->getMessage()]]));

            throw $e;
        }
    }

    /**
     * Purges every deleted institution whose retention period is over.
     *
     * @return array{purged: list<int>, failed: array<int, string>} ids purged, and ids that failed with why
     */
    public function purgeExpired(DateTimeImmutable $now, bool $dryRun = false): array
    {
        $cutoff = PurgePolicy::cutoff($now, $this->retentionDays())->format('Y-m-d H:i:s');

        $due = (new InstitutionModel())->onlyDeleted()->where('institutions.deleted_at <=', $cutoff)->orderBy('institutions.deleted_at')->findAll();

        $result = ['purged' => [], 'failed' => []];

        foreach ($due as $institution) {
            $id = (int) $institution['id'];

            if ($dryRun) {
                $result['purged'][] = $id;

                continue;
            }

            try {
                $this->purge($id, null, null, 'system (scheduled purge)');
                $result['purged'][] = $id;
            } catch (Throwable $e) {
                $result['failed'][$id] = $e->getMessage();
            }
        }

        return $result;
    }

    /** How many days a deleted institution can be restored, from the platform setting. */
    public function retentionDays(): int
    {
        return (int) (new PlatformSettings())->get('deletedInstitutionRetentionDays');
    }

    /**
     * Past institutions (suspended or lapsed): kept, read-only, renewable.
     *
     * @return list<array<string, mixed>>
     */
    public function pastInstitutions(int $limit = 500): array
    {
        return (new InstitutionModel())->past()->orderBy('institutions.name')->findAll($limit);
    }

    /**
     * Deleted institutions with the day each is purged, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function deletedInstitutions(DateTimeImmutable $now, int $limit = 500): array
    {
        $days = $this->retentionDays();
        $rows = (new InstitutionModel())->onlyDeleted()->orderBy('institutions.deleted_at')->findAll($limit);

        foreach ($rows as &$row) {
            $row['purge_on']  = PurgePolicy::dueAt($row['deleted_at'], $days)->format('Y-m-d');
            $row['days_left'] = PurgePolicy::daysLeft($row['deleted_at'], $now, $days);
        }

        return $rows;
    }

    /**
     * What a purge removes, and what it leaves behind.
     *
     * @return array{departments: int, programs: int, courses: int, members: int, members_left_without_an_institution: int}
     */
    public function contents(int $institutionId): array
    {
        $db = db_connect();

        $departments = $db->table('departments')->where('institution_id', $institutionId)->countAllResults();

        $programs = $db->table('programs')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $institutionId)
            ->countAllResults();

        $courses = $db->table('courses')
            ->join('programs', 'programs.id = courses.program_id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $institutionId)
            ->countAllResults();

        $members = $db->table('institution_users')->where('institution_id', $institutionId)->countAllResults();

        // Members who belong to no other institution: still there as accounts, but with nowhere to go.
        $alone = $db->table('institution_users')
            ->where('institution_id', $institutionId)
            ->where('user_id NOT IN (SELECT user_id FROM institution_users WHERE institution_id <> ' . $institutionId . ')', null, false)
            ->countAllResults();

        return [
            'departments'                         => $departments,
            'programs'                            => $programs,
            'courses'                             => $courses,
            'members'                             => $members,
            'members_left_without_an_institution' => $alone,
        ];
    }

    /**
     * The people who belong to an institution: how many of each role, and the
     * first `$limit` of them with their name, email and roles.
     *
     * @return array{total: int, perRole: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function members(int $institutionId, int $limit = 100): array
    {
        $db = db_connect();

        $rows = $db->table('institution_users')
            ->select('users.id, users.username, users.active, user_profiles.first_name, user_profiles.last_name')
            ->select("(SELECT secret FROM auth_identities WHERE auth_identities.user_id = users.id AND auth_identities.type = 'email_password' LIMIT 1) AS email", false)
            ->join('users', 'users.id = institution_users.user_id')
            ->join('user_profiles', 'user_profiles.user_id = users.id', 'left')
            ->where('institution_users.institution_id', $institutionId)
            ->where('users.deleted_at IS NULL', null, false)
            ->orderBy('user_profiles.last_name')
            ->orderBy('users.id')
            ->limit($limit)
            ->get()->getResultArray();

        $groups = [];

        if ($rows !== []) {
            foreach ($db->table('auth_groups_users')->select('user_id, group')->whereIn('user_id', array_column($rows, 'id'))->get()->getResultArray() as $link) {
                $groups[(int) $link['user_id']][] = $link['group'];
            }
        }

        foreach ($rows as &$row) {
            $row['groups'] = $groups[(int) $row['id']] ?? [];
        }

        $perRole = [];

        foreach ($db->table('institution_users')
            ->select('auth_groups_users.group, COUNT(DISTINCT institution_users.user_id) AS people')
            ->join('auth_groups_users', 'auth_groups_users.user_id = institution_users.user_id')
            ->where('institution_users.institution_id', $institutionId)
            ->groupBy('auth_groups_users.group')
            ->get()->getResultArray() as $count) {
            $perRole[$count['group']] = (int) $count['people'];
        }

        ksort($perRole);

        return [
            'total'   => $db->table('institution_users')->where('institution_id', $institutionId)->countAllResults(),
            'perRole' => $perRole,
            'rows'    => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when there is no such (undeleted) institution
     */
    private function existing(int $id): array
    {
        return $this->find($id) ?? throw new InvalidArgumentException('That institution does not exist.');
    }

    /**
     * Records an institution change in the audit log; see RecordsAuditedChanges.
     *
     * @param callable(): mixed                                             $undo
     * @param array{old?: array<string, mixed>, new?: array<string, mixed>} $values
     */
    private function recorded(callable $undo, string $action, ?User $actor, int $institutionId, array $values): void
    {
        $this->auditedChange($undo, $action, $actor, 'institution', $institutionId, $institutionId, $values);
    }
}

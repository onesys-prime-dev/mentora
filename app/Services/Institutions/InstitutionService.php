<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Academic\InstitutionModel;
use CodeIgniter\Shield\Entities\User;
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
    /** Letters, numbers, dashes and underscores; 2 to 50 characters. */
    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{1,49}$/';

    /**
     * One page of institutions matching the filters.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(InstitutionFilters $filters): array
    {
        $model = new InstitutionModel();

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
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when there is no such (undeleted) institution
     */
    private function existing(int $id): array
    {
        return $this->find($id) ?? throw new InvalidArgumentException('That institution does not exist.');
    }

    /**
     * Writes the audit entry for a change that has just been made. If that
     * fails, $undo puts the change back and the failure is rethrown: nothing
     * changes without a trace.
     *
     * @param callable(): mixed                            $undo
     * @param array{old?: array<string, mixed>, new?: array<string, mixed>} $values
     */
    private function recorded(callable $undo, string $action, ?User $actor, int $institutionId, array $values): void
    {
        try {
            service('auditLogger')->log($action, $actor ?? auth()->user(), [
                'target_type'    => 'institution',
                'target_id'      => $institutionId,
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

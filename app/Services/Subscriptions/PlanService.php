<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Subscriptions\PlanModel;
use App\Services\Audit\RecordsAuditedChanges;
use CodeIgniter\Shield\Entities\User;
use InvalidArgumentException;
use Throwable;

/**
 * What a system administrator can do to subscription plans (SA13): list,
 * create, edit, retire and reinstate. A plan is never deleted, because
 * institutions already on it keep it; retiring only stops it being offered
 * for new subscriptions.
 *
 * Every change is checked here, recorded in the audit log, and undone if it
 * cannot be recorded. Every refusal is an InvalidArgumentException whose
 * message is fit to show.
 */
class PlanService
{
    use RecordsAuditedChanges;

    /**
     * All plans, active first, then by name. Each has `features` as a list.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $status = null): array
    {
        $model = new PlanModel();

        if ($status === PlanModel::ACTIVE || $status === PlanModel::RETIRED) {
            $model->where('plans.status', $status);
        }

        return array_map(
            self::withFeatureList(...),
            $model->orderBy("plans.status = 'retired'", 'asc', false)->orderBy('plans.name', 'asc')->findAll(),
        );
    }

    /**
     * The plans that can be chosen for a new subscription.
     *
     * @return list<array<string, mixed>>
     */
    public function selectable(): array
    {
        return array_map(self::withFeatureList(...), (new PlanModel())->selectable()->orderBy('plans.name')->findAll());
    }

    /** One plan with `features` as a list, or null. */
    public function find(int $id): ?array
    {
        $plan = (new PlanModel())->find($id);

        return $plan === null ? null : self::withFeatureList($plan);
    }

    /**
     * @param array<string, mixed> $input a form post
     *
     * @return int the new plan's id
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function create(array $input, ?User $actor = null): int
    {
        $values = PlanInput::parse($input);

        $this->assertNameFree($values['name']);

        $model = new PlanModel();
        $id    = $model->insert(self::stored($values) + ['status' => PlanModel::ACTIVE]);

        if ($id === false) {
            throw new InvalidArgumentException(implode(' ', $model->errors()) ?: 'The plan could not be saved.');
        }

        $id = (int) $id;

        $this->auditedChange(
            static fn () => (new PlanModel())->delete($id),
            'plan.created',
            $actor,
            'plan',
            $id,
            null,
            ['new' => $values],
        );

        return $id;
    }

    /**
     * Changes a plan. This changes what every institution on the plan gets,
     * so the audit entry holds the whole before and after. A retired plan
     * must be reinstated first.
     *
     * @param array<string, mixed> $input a form post
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function update(int $id, array $input, ?User $actor = null): void
    {
        $plan = $this->existing($id);

        if ($plan['status'] === PlanModel::RETIRED) {
            throw new InvalidArgumentException('A retired plan cannot be edited. Reinstate it first.');
        }

        $values = PlanInput::parse($input);

        $this->assertNameFree($values['name'], $id);

        $before = self::comparable($plan);

        if ($before === $values) {
            return;
        }

        $original = self::stored($before);

        (new PlanModel())->update($id, self::stored($values));

        $this->auditedChange(
            static fn () => (new PlanModel())->update($id, $original),
            'plan.updated',
            $actor,
            'plan',
            $id,
            null,
            ['old' => $before, 'new' => $values],
        );
    }

    /**
     * Stops a plan being offered for new subscriptions. Institutions already
     * on it keep it.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function retire(int $id, ?User $actor = null): void
    {
        $plan = $this->existing($id);

        if ($plan['status'] === PlanModel::RETIRED) {
            throw new InvalidArgumentException('That plan is already retired.');
        }

        (new PlanModel())->update($id, ['status' => PlanModel::RETIRED, 'retired_at' => date('Y-m-d H:i:s')]);

        $this->auditedChange(
            static fn () => (new PlanModel())->update($id, ['status' => PlanModel::ACTIVE, 'retired_at' => null]),
            'plan.retired',
            $actor,
            'plan',
            $id,
            null,
            ['old' => ['name' => $plan['name'], 'status' => PlanModel::ACTIVE], 'new' => ['name' => $plan['name'], 'status' => PlanModel::RETIRED]],
        );
    }

    /**
     * Offers a retired plan again.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function reinstate(int $id, ?User $actor = null): void
    {
        $plan = $this->existing($id);

        if ($plan['status'] !== PlanModel::RETIRED) {
            throw new InvalidArgumentException('That plan is not retired.');
        }

        $retiredAt = $plan['retired_at'];

        (new PlanModel())->update($id, ['status' => PlanModel::ACTIVE, 'retired_at' => null]);

        $this->auditedChange(
            static fn () => (new PlanModel())->update($id, ['status' => PlanModel::RETIRED, 'retired_at' => $retiredAt]),
            'plan.reinstated',
            $actor,
            'plan',
            $id,
            null,
            ['old' => ['name' => $plan['name'], 'status' => PlanModel::RETIRED], 'new' => ['name' => $plan['name'], 'status' => PlanModel::ACTIVE]],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function existing(int $id): array
    {
        return $this->find($id) ?? throw new InvalidArgumentException('That plan does not exist.');
    }

    /**
     * A name is unique whatever its case. (The database key is case-insensitive
     * too; checking here gives a clear message instead of a database error.)
     *
     * @throws InvalidArgumentException
     */
    private function assertNameFree(string $name, ?int $exceptId = null): void
    {
        $model = (new PlanModel())->where('name', $name);

        if ($exceptId !== null) {
            $model->where('id !=', $exceptId);
        }

        if ($model->countAllResults() > 0) {
            throw new InvalidArgumentException("There is already a plan called \"{$name}\".");
        }
    }

    /**
     * A plan row with `features` decoded to a list.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>
     */
    private static function withFeatureList(array $plan): array
    {
        $decoded = json_decode((string) ($plan['features'] ?? ''), true);

        $plan['features'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];

        return $plan;
    }

    /**
     * Just the editable fields of a plan, in the shape PlanInput produces.
     *
     * @param array<string, mixed> $plan
     *
     * @return array{name: string, description: ?string, features: list<string>, seat_limit: ?int, storage_limit_mb: ?int, ai_generation_limit: ?int}
     */
    private static function comparable(array $plan): array
    {
        $int = static fn ($value): ?int => $value === null ? null : (int) $value;

        return [
            'name'                => (string) $plan['name'],
            'description'         => $plan['description'] === null ? null : (string) $plan['description'],
            'features'            => $plan['features'],
            'seat_limit'          => $int($plan['seat_limit']),
            'storage_limit_mb'    => $int($plan['storage_limit_mb']),
            'ai_generation_limit' => $int($plan['ai_generation_limit']),
        ];
    }

    /**
     * The values as stored: features as JSON.
     *
     * @param array{name: string, description: ?string, features: list<string>, seat_limit: ?int, storage_limit_mb: ?int, ai_generation_limit: ?int} $values
     *
     * @return array<string, mixed>
     */
    private static function stored(array $values): array
    {
        $values['features'] = json_encode($values['features'], JSON_UNESCAPED_SLASHES);

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Flags;

use App\Models\Academic\InstitutionModel;
use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Audit\RecordsAuditedChanges;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What a system administrator can do to feature flags (SA7): set a flag for
 * everyone, or for one institution.
 *
 * A setting is one of three states:
 *
 *   default  no row; the flag follows its plan grants and the default in code
 *   on       globally: on for everyone by default. For an institution: on for it
 *   off      globally: the KILL SWITCH, off for everyone whatever else says.
 *            For an institution: off for it, even if its plan grants the flag
 *
 * Turning a flag on or off needs a note saying why, and may carry a last day
 * after which it stops applying. Every change is checked here, recorded in the
 * audit log, and undone if it cannot be recorded. Every refusal is an
 * InvalidArgumentException whose message is fit to show.
 */
class FeatureFlagAdmin
{
    use RecordsAuditedChanges;

    public const DEFAULT = 'default';

    public const ON = 'on';

    public const OFF = 'off';

    public const NOTE_MAX = 500;

    /**
     * Sets a flag for everyone.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     *
     * @return bool false when nothing changed
     */
    public function setGlobal(string $key, string $state, ?string $note, ?string $endsOn, ?User $actor = null): bool
    {
        return $this->set($key, null, $state, $note, $endsOn, $actor);
    }

    /**
     * Sets a flag for one institution.
     *
     * @throws InvalidArgumentException
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     *
     * @return bool false when nothing changed
     */
    public function setForInstitution(int $institutionId, string $key, string $state, ?string $note, ?string $endsOn, ?User $actor = null): bool
    {
        if ((new InstitutionModel())->find($institutionId) === null) {
            throw new InvalidArgumentException('That institution does not exist.');
        }

        return $this->set($key, $institutionId, $state, $note, $endsOn, $actor);
    }

    private function set(string $key, ?int $institutionId, string $state, ?string $note, ?string $endsOn, ?User $actor): bool
    {
        if (! (new FeatureFlags())->exists($key)) {
            throw new InvalidArgumentException('That feature flag does not exist.');
        }

        if (! in_array($state, [self::DEFAULT, self::ON, self::OFF], true)) {
            throw new InvalidArgumentException('Choose default, on or off.');
        }

        [$note, $endsOn] = $state === self::DEFAULT ? [null, null] : $this->details($note, $endsOn, $state);

        $model    = new FeatureFlagOverrideModel();
        $existing = $this->find($model, $key, $institutionId);

        $before = $this->describe($existing);
        $after  = ['state' => $state, 'note' => $note, 'ends_on' => $endsOn];

        if ($before === $after) {
            return false;
        }

        $by = ($actor ?? auth()->user())?->id;

        // Make the change.
        if ($state === self::DEFAULT) {
            $model->delete($existing['id']);
        } elseif ($existing === null) {
            $model->insert(['flag_key' => $key, 'institution_id' => $institutionId, 'enabled' => $state === self::ON ? 1 : 0, 'note' => $note, 'ends_on' => $endsOn, 'created_by_user_id' => $by]);
        } else {
            $model->update($existing['id'], ['enabled' => $state === self::ON ? 1 : 0, 'note' => $note, 'ends_on' => $endsOn, 'created_by_user_id' => $by]);
        }

        $this->auditedChange(
            fn () => $this->restore($key, $institutionId, $existing),
            $institutionId === null ? 'flag.global_changed' : 'flag.override_changed',
            $actor,
            'feature_flag',
            $key,
            $institutionId,
            ['old' => $before, 'new' => $after],
        );

        return true;
    }

    /**
     * The note and last day, checked. A note is required whenever a flag is
     * turned on or off.
     *
     * @return array{string, ?string}
     *
     * @throws InvalidArgumentException
     */
    private function details(?string $note, ?string $endsOn, string $state): array
    {
        $note = $note === null ? '' : trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Say why: a note is needed when a flag is turned ' . $state . '.');
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidArgumentException('The note can be at most ' . self::NOTE_MAX . ' characters.');
        }

        $endsOn = $endsOn === null ? '' : trim($endsOn);

        if ($endsOn === '') {
            return [$note, null];
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);

        if ($date === false || $date->format('Y-m-d') !== $endsOn) {
            throw new InvalidArgumentException('The last day must be a real date.');
        }

        if ($endsOn < date('Y-m-d')) {
            throw new InvalidArgumentException('The last day cannot be in the past.');
        }

        return [$note, $endsOn];
    }

    /** @return array<string, mixed>|null */
    private function find(FeatureFlagOverrideModel $model, string $key, ?int $institutionId): ?array
    {
        $query = $model->where('flag_key', $key);

        $institutionId === null ? $query->where('institution_id IS NULL', null, false) : $query->where('institution_id', $institutionId);

        return $query->first();
    }

    /**
     * A row as state, note and last day, for comparing and for the audit entry.
     *
     * @param array<string, mixed>|null $row
     *
     * @return array{state: string, note: ?string, ends_on: ?string}
     */
    private function describe(?array $row): array
    {
        if ($row === null) {
            return ['state' => self::DEFAULT, 'note' => null, 'ends_on' => null];
        }

        return ['state' => (int) $row['enabled'] === 1 ? self::ON : self::OFF, 'note' => $row['note'], 'ends_on' => $row['ends_on']];
    }

    /**
     * Puts a flag back as it was before a change that could not be recorded.
     *
     * @param array<string, mixed>|null $before the row before the change, or null if there was none
     */
    private function restore(string $key, ?int $institutionId, ?array $before): void
    {
        $model   = new FeatureFlagOverrideModel();
        $current = $this->find($model, $key, $institutionId);

        if ($current !== null) {
            $model->delete($current['id']);
        }

        if ($before !== null) {
            $model->insert([
                'flag_key'           => $before['flag_key'],
                'institution_id'     => $before['institution_id'],
                'enabled'            => $before['enabled'],
                'note'               => $before['note'],
                'ends_on'            => $before['ends_on'],
                'created_by_user_id' => $before['created_by_user_id'],
            ]);
        }
    }
}

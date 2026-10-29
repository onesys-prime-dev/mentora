<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Exceptions\PlanLimitExceeded;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;

/**
 * What an institution's plan allows, how much of it is used, and whether
 * there is room for more (SA13).
 *
 * Three limits, each optional on the plan (empty = unlimited):
 *   seats    active student accounts
 *   storage  total size of the files its people have uploaded
 *   ai       AI question generations since the current term began
 *
 * At 90% of a limit it is a warning; once it is used up, anything that would
 * add to it is refused. Existing data is never removed. An institution with
 * no subscription has no limits. A user who belongs to no institution (a
 * system administrator) is never limited.
 *
 * The *Block methods answer with a message to show, or null when there is
 * room, for code that reports problems as values. assertCanStore() throws
 * PlanLimitExceeded instead, for the many places that upload files.
 */
class PlanLimits
{
    public const SEATS = 'seats';

    public const STORAGE = 'storage';

    public const AI = 'ai';

    private const BYTES_PER_MB = 1048576;

    /**
     * The plan's limits for the institution.
     *
     * @return array{plan: ?string, seats: ?int, storage_mb: ?int, ai: ?int}
     */
    public function limitsFor(int $institutionId): array
    {
        $row = db_connect()->table('subscriptions')
            ->select('plans.name, plans.seat_limit, plans.storage_limit_mb, plans.ai_generation_limit')
            ->join('plans', 'plans.id = subscriptions.plan_id')
            ->where('subscriptions.institution_id', $institutionId)
            ->get()->getRowArray();

        if ($row === null) {
            return ['plan' => null, 'seats' => null, 'storage_mb' => null, 'ai' => null];
        }

        $int = static fn ($value): ?int => $value === null ? null : (int) $value;

        return [
            'plan'       => (string) $row['name'],
            'seats'      => $int($row['seat_limit']),
            'storage_mb' => $int($row['storage_limit_mb']),
            'ai'         => $int($row['ai_generation_limit']),
        ];
    }

    /**
     * What is used now.
     *
     * @return array{seats: int, storage_bytes: int, ai: int}
     */
    public function usage(int $institutionId, ?DateTimeImmutable $now = null): array
    {
        $db = db_connect();

        $seats = (int) $db->table('users')
            ->select('COUNT(DISTINCT users.id) AS total', false)
            ->join('auth_groups_users', "auth_groups_users.user_id = users.id AND auth_groups_users.group = 'student'")
            ->join('institution_users', 'institution_users.user_id = users.id')
            ->where('institution_users.institution_id', $institutionId)
            ->where('users.active', 1)
            ->get()->getRowArray()['total'];

        $storage = (int) $db->table('files')
            ->select('COALESCE(SUM(files.file_size), 0) AS total', false)
            ->join('institution_users', 'institution_users.user_id = files.owner_user_id')
            ->where('institution_users.institution_id', $institutionId)
            ->where('files.status !=', 'archived')
            ->get()->getRowArray()['total'];

        // A finished batch counts the questions it made, a failed or cancelled one included (it can fail part-way,
        // and what it made can still be accepted); one still running holds what it asked for (QA AMB-ASM-10).
        $ai = (int) $db->table('ai_generation_batches')
            ->select("COALESCE(SUM(CASE WHEN ai_generation_batches.status IN ('completed', 'failed') THEN ai_generation_batches.generated_count ELSE ai_generation_batches.requested_count END), 0) AS total", false)
            ->join('courses', 'courses.id = ai_generation_batches.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('departments.institution_id', $institutionId)
            ->where('ai_generation_batches.created_at >=', $this->aiWindowStart($institutionId, $now ?? new DateTimeImmutable()) . ' 00:00:00')
            ->get()->getRowArray()['total'];

        return ['seats' => $seats, 'storage_bytes' => $storage, 'ai' => $ai];
    }

    /**
     * Each limit with what is used, how full it is, and its state. Storage is in MB.
     *
     * @return array{plan: ?string, seats: array<string, mixed>, storage: array<string, mixed>, ai: array<string, mixed>}
     */
    public function report(int $institutionId, ?DateTimeImmutable $now = null): array
    {
        $limits = $this->limitsFor($institutionId);
        $usage  = $this->usage($institutionId, $now);

        $storageUsedMb = (int) ceil($usage['storage_bytes'] / self::BYTES_PER_MB);

        return [
            'plan'    => $limits['plan'],
            'seats'   => self::line($usage['seats'], $limits['seats']),
            'storage' => self::line($usage['storage_bytes'], $limits['storage_mb'] === null ? null : $limits['storage_mb'] * self::BYTES_PER_MB) + ['used_mb' => $storageUsedMb, 'limit_mb' => $limits['storage_mb']],
            'ai'      => self::line($usage['ai'], $limits['ai']),
        ];
    }

    /** The institution a user belongs to (the first, if several), or null. */
    public function institutionOf(int $userId): ?int
    {
        $row = db_connect()->table('institution_users')
            ->select('institution_users.institution_id')
            ->join('institutions', 'institutions.id = institution_users.institution_id')
            ->where('institution_users.user_id', $userId)
            ->where('institutions.deleted_at IS NULL', null, false)
            ->orderBy('institution_users.id')
            ->get()->getRowArray();

        return $row === null ? null : (int) $row['institution_id'];
    }

    /** A message if adding $adding student accounts would go beyond the plan's seats, else null. */
    public function seatBlock(int $institutionId, int $adding = 1): ?string
    {
        $limit = $this->limitsFor($institutionId);

        if (! PlanLimitState::wouldExceed($this->usage($institutionId)['seats'], $adding, $limit['seats'])) {
            return null;
        }

        return sprintf('All %s student seats on the %s plan are in use. New student accounts cannot be added until the plan is changed.', number_format((int) $limit['seats']), $limit['plan']);
    }

    /** A message if storing $bytes more would go beyond the user's institution's storage, else null. */
    public function storageBlock(?User $user, int $bytes): ?string
    {
        $institutionId = $user === null ? null : $this->institutionOf((int) $user->id);

        if ($institutionId === null) {
            return null;
        }

        $limit = $this->limitsFor($institutionId);

        if ($limit['storage_mb'] === null) {
            return null;
        }

        $used = $this->usage($institutionId)['storage_bytes'];

        if (! PlanLimitState::wouldExceed($used, $bytes, $limit['storage_mb'] * self::BYTES_PER_MB)) {
            return null;
        }

        return sprintf('Your institution has used its storage allowance (%s of %s MB on the %s plan). Delete some files or ask your institution to change its plan.', number_format((int) ceil($used / self::BYTES_PER_MB)), number_format($limit['storage_mb']), $limit['plan']);
    }

    /** A message if generating $count more questions would go beyond the user's institution's allowance this term, else null. */
    public function generationBlock(?User $user, int $count): ?string
    {
        $institutionId = $user === null ? null : $this->institutionOf((int) $user->id);

        if ($institutionId === null) {
            return null;
        }

        $limit = $this->limitsFor($institutionId);
        $used  = $this->usage($institutionId)['ai'];

        if (! PlanLimitState::wouldExceed($used, $count, $limit['ai'])) {
            return null;
        }

        $left = max(0, (int) $limit['ai'] - $used);

        return $left === 0
            ? sprintf('Your institution has used all %s AI question generations for this term on the %s plan.', number_format((int) $limit['ai']), $limit['plan'])
            : sprintf('Your institution has %s AI question generation%s left this term on the %s plan; this request asks for %s.', number_format($left), $left === 1 ? '' : 's', $limit['plan'], number_format($count));
    }

    /**
     * @throws PlanLimitExceeded when there is no room for $bytes more
     */
    public function assertCanStore(?User $user, int $bytes): void
    {
        $message = $this->storageBlock($user, $bytes);

        if ($message !== null) {
            throw new PlanLimitExceeded($message, self::STORAGE);
        }
    }

    /**
     * The start of the window AI generations are counted in: the day the
     * current term began (the term that contains the day, else the one that
     * began most recently, else the earliest upcoming one). An institution
     * with no terms counts from the start of the calendar year.
     */
    public function aiWindowStart(int $institutionId, DateTimeImmutable $now): string
    {
        $today = $now->format('Y-m-d');
        $terms = db_connect()->table('academic_terms');

        $current = (clone $terms)->where('institution_id', $institutionId)->where('start_date <=', $today)->where('end_date >=', $today)->orderBy('start_date', 'desc')->get()->getRowArray();

        if ($current === null) {
            $current = db_connect()->table('academic_terms')->where('institution_id', $institutionId)->where('start_date <=', $today)->orderBy('start_date', 'desc')->get()->getRowArray();
        }

        if ($current === null) {
            $current = db_connect()->table('academic_terms')->where('institution_id', $institutionId)->orderBy('start_date', 'asc')->get()->getRowArray();
        }

        return $current === null ? $now->format('Y') . '-01-01' : (string) $current['start_date'];
    }

    /**
     * @return array{used: int, limit: ?int, percent: ?int, state: string}
     */
    private static function line(int $used, ?int $limit): array
    {
        return ['used' => $used, 'limit' => $limit, 'percent' => PlanLimitState::percent($used, $limit), 'state' => PlanLimitState::of($used, $limit)];
    }
}

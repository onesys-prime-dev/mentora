<?php

declare(strict_types=1);

namespace App\Services\Audit;

use DateTimeImmutable;

/**
 * The audit log list's filters, sort and paging, read safely from a query
 * string. Anything that is not valid (a bad date, an unknown sort column, a
 * page of -3) is dropped or replaced by its default instead of failing, so a
 * hand-edited address can never produce an error or an unexpected query.
 */
final class AuditLogFilters
{
    public const SORTABLE = ['created_at', 'action', 'actor_label', 'acting_role', 'institution_id'];

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private const TEXT_MAX = 100;

    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $actor = null,
        public readonly ?int $institutionId = null,
        public readonly ?string $action = null,
        public readonly ?string $targetType = null,
        public readonly ?string $q = null,
        public readonly string $sort = 'created_at',
        public readonly string $dir = 'desc',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        // A repeated parameter (?sort[]=x) arrives as an array; only plain text counts.
        $sort = is_string($input['sort'] ?? null) ? $input['sort'] : '';
        $dir  = is_string($input['dir'] ?? null) ? strtolower($input['dir']) : '';

        return new self(
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null),
            actor: self::text($input['actor'] ?? null),
            institutionId: self::positiveInt($input['institution'] ?? null),
            action: self::text($input['action'] ?? null),
            targetType: self::text($input['target_type'] ?? null),
            q: self::text($input['q'] ?? null),
            sort: in_array($sort, self::SORTABLE, true) ? $sort : 'created_at',
            dir: $dir === 'asc' ? 'asc' : 'desc',
            page: self::positiveInt($input['page'] ?? null) ?? 1,
            perPage: min(self::MAX_PER_PAGE, max(1, self::positiveInt($input['per_page'] ?? null) ?? self::DEFAULT_PER_PAGE)),
        );
    }

    /** First moment included by the "from" date, or null. */
    public function fromTimestamp(): ?string
    {
        return $this->from === null ? null : $this->from . ' 00:00:00';
    }

    /** First moment NOT included: the start of the day after the "to" date, so the whole last day counts. */
    public function untilTimestamp(): ?string
    {
        if ($this->to === null) {
            return null;
        }

        return (new DateTimeImmutable($this->to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * The filters as query-string parameters, leaving out anything at its
     * default, with $override applied on top. For links that keep the filters
     * (sort headers, paging, export).
     *
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = [
            'from'        => $this->from,
            'to'          => $this->to,
            'actor'       => $this->actor,
            'institution' => $this->institutionId,
            'action'      => $this->action,
            'target_type' => $this->targetType,
            'q'           => $this->q,
            'sort'        => $this->sort !== 'created_at' ? $this->sort : null,
            'dir'         => $this->dir !== 'desc' ? $this->dir : null,
            'page'        => $this->page > 1 ? $this->page : null,
            'per_page'    => $this->perPage !== self::DEFAULT_PER_PAGE ? $this->perPage : null,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when any filter (not sort or paging) narrows the list. */
    public function isFiltered(): bool
    {
        return $this->from !== null || $this->to !== null || $this->actor !== null || $this->institutionId !== null
            || $this->action !== null || $this->targetType !== null || $this->q !== null;
    }

    /** @param mixed $value */
    private static function date($value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @param mixed $value */
    private static function text($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::TEXT_MAX);
    }

    /** @param mixed $value */
    private static function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * The institutions list's search, status filter, sort and paging, read safely
 * from a query string: anything invalid is dropped or replaced by its
 * default, so a hand-edited address can never fail or reach a column that is
 * not listed here.
 */
final class InstitutionFilters
{
    /** Shown in the status filter, besides "all". `deleted` is the soft-deleted ones. */
    public const STATUSES = ['pending', 'active', 'read_only', 'past', 'deleted'];

    public const SORTABLE = ['name', 'code', 'status', 'created_at'];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    private const TEXT_MAX = 100;

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?string $status = null,
        public readonly string $sort = 'name',
        public readonly string $dir = 'asc',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        $status = $input['status'] ?? null;
        $sort   = $input['sort'] ?? null;
        $dir    = $input['dir'] ?? null;

        return new self(
            q: self::text($input['q'] ?? null),
            status: is_string($status) && in_array($status, self::STATUSES, true) ? $status : null,
            sort: is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : 'name',
            dir: is_string($dir) && strtolower($dir) === 'desc' ? 'desc' : 'asc',
            page: self::positiveInt($input['page'] ?? null) ?? 1,
            perPage: min(self::MAX_PER_PAGE, max(1, self::positiveInt($input['per_page'] ?? null) ?? self::DEFAULT_PER_PAGE)),
        );
    }

    /**
     * The filters as query-string parameters, leaving out defaults, with
     * $override applied on top. For links that keep the filters.
     *
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = [
            'q'        => $this->q,
            'status'   => $this->status,
            'sort'     => $this->sort !== 'name' ? $this->sort : null,
            'dir'      => $this->dir !== 'asc' ? $this->dir : null,
            'page'     => $this->page > 1 ? $this->page : null,
            'per_page' => $this->perPage !== self::DEFAULT_PER_PAGE ? $this->perPage : null,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when a search or status filter narrows the list (sort and paging do not). */
    public function isFiltered(): bool
    {
        return $this->q !== null || $this->status !== null;
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

<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * The dean Courses page's filters, sort and paging, read safely from a query
 * string. Anything not valid (an unknown sort column, a page of -3) is dropped
 * or replaced by its default instead of failing, so a hand-edited address can
 * never produce an error or an unexpected query.
 */
final class DeanCourseFilters
{
    public const SORTABLE = ['name', 'instructor', 'enrollment', 'recent_activity'];

    public const STATUSES = ['active', 'completed', 'archived'];

    public const DEFAULT_PER_PAGE = 12;

    public const MAX_PER_PAGE = 60;

    private const TEXT_MAX = 100;

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?int $termId = null,
        public readonly ?int $subDepartmentId = null,
        public readonly ?int $programId = null,
        public readonly ?int $instructorId = null,
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
        $sort   = is_string($input['sort'] ?? null) ? $input['sort'] : '';
        $dir    = is_string($input['dir'] ?? null) ? strtolower($input['dir']) : '';
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';

        return new self(
            q: self::text($input['q'] ?? null),
            termId: self::positiveInt($input['term'] ?? null),
            subDepartmentId: self::positiveInt($input['sub_department'] ?? null),
            programId: self::positiveInt($input['program'] ?? null),
            instructorId: self::positiveInt($input['instructor'] ?? null),
            status: in_array($status, self::STATUSES, true) ? $status : null,
            sort: in_array($sort, self::SORTABLE, true) ? $sort : 'name',
            dir: $dir === 'desc' ? 'desc' : 'asc',
            page: self::positiveInt($input['page'] ?? null) ?? 1,
            perPage: min(self::MAX_PER_PAGE, max(1, self::positiveInt($input['per_page'] ?? null) ?? self::DEFAULT_PER_PAGE)),
        );
    }

    /**
     * The filters as query-string parameters, leaving out anything at its
     * default, with $override applied on top. For links that keep the
     * filters (sort links, paging).
     *
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = [
            'q'              => $this->q,
            'term'           => $this->termId,
            'sub_department' => $this->subDepartmentId,
            'program'        => $this->programId,
            'instructor'     => $this->instructorId,
            'status'         => $this->status,
            'sort'           => $this->sort !== 'name' ? $this->sort : null,
            'dir'            => $this->dir !== 'asc' ? $this->dir : null,
            'page'           => $this->page > 1 ? $this->page : null,
            'per_page'       => $this->perPage !== self::DEFAULT_PER_PAGE ? $this->perPage : null,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when any filter (not sort or paging) narrows the list. */
    public function isFiltered(): bool
    {
        return $this->q !== null || $this->termId !== null || $this->subDepartmentId !== null
            || $this->programId !== null || $this->instructorId !== null || $this->status !== null;
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

<?php

declare(strict_types=1);

namespace App\Services\Users;

/**
 * The users list's search, filters, sort and paging, read safely from a query
 * string: anything invalid is dropped or replaced by its default, so a
 * hand-edited address can never fail or reach a column that is not listed here.
 */
final class UserFilters
{
    public const STATUSES = ['active', 'deactivated'];

    /** Role names a filter may use (the Shield groups). */
    public const ROLES = ['admin', 'institutional_admin', 'chairperson', 'instructor', 'student', 'parent'];

    /** What each role is called on screen. */
    public const ROLE_LABELS = [
        'admin'               => 'System administrator',
        'institutional_admin' => 'Institutional administrator',
        'chairperson'         => 'Chairperson',
        'instructor'          => 'Instructor',
        'student'             => 'Student',
        'parent'              => 'Parent',
    ];

    public const SORTABLE = ['name', 'email', 'last_active', 'created_at'];

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private const TEXT_MAX = 100;

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?int $institutionId = null,
        public readonly ?string $role = null,
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
        $role   = $input['role'] ?? null;
        $status = $input['status'] ?? null;
        $sort   = $input['sort'] ?? null;
        $dir    = $input['dir'] ?? null;

        return new self(
            q: self::text($input['q'] ?? null),
            institutionId: self::positiveInt($input['institution'] ?? null),
            role: is_string($role) && in_array($role, self::ROLES, true) ? $role : null,
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
            'q'           => $this->q,
            'institution' => $this->institutionId,
            'role'        => $this->role,
            'status'      => $this->status,
            'sort'        => $this->sort !== 'name' ? $this->sort : null,
            'dir'         => $this->dir !== 'asc' ? $this->dir : null,
            'page'        => $this->page > 1 ? $this->page : null,
            'per_page'    => $this->perPage !== self::DEFAULT_PER_PAGE ? $this->perPage : null,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when a search or filter narrows the list (sort and paging do not). */
    public function isFiltered(): bool
    {
        return $this->q !== null || $this->institutionId !== null || $this->role !== null || $this->status !== null;
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

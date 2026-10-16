<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * Monitor Department's filters, sort and comparison choice, read safely from
 * a query string. The term and date range are Monitor Program's own
 * (MonitorProgramFilters); the department page adds a sub-department, a
 * program, which grouping the comparison table shows, and its sort. Anything
 * not valid is dropped or replaced by its default, so a hand-edited address
 * can never produce an error or an unexpected query.
 */
final class MonitorDepartmentFilters
{
    public const COMPARE = ['sub_department', 'program'];

    public const SORTABLE = ['name', 'students', 'completion', 'average_grade', 'grading_progress', 'turnaround', 'at_risk', 'last_activity'];

    public function __construct(
        public readonly MonitorProgramFilters $base = new MonitorProgramFilters(),
        public readonly ?int $subDepartmentId = null,
        public readonly ?int $programId = null,
        public readonly string $compare = 'sub_department',
        public readonly string $sort = 'name',
        public readonly string $dir = 'asc',
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        $compare = is_string($input['compare'] ?? null) ? $input['compare'] : '';
        $sort    = is_string($input['sort'] ?? null) ? $input['sort'] : '';
        $dir     = is_string($input['dir'] ?? null) ? strtolower($input['dir']) : '';

        return new self(
            base: MonitorProgramFilters::fromInput($input),
            subDepartmentId: self::positiveInt($input['sub_department'] ?? null),
            programId: self::positiveInt($input['program'] ?? null),
            compare: in_array($compare, self::COMPARE, true) ? $compare : 'sub_department',
            sort: in_array($sort, self::SORTABLE, true) ? $sort : 'name',
            dir: $dir === 'desc' ? 'desc' : 'asc',
        );
    }

    /**
     * The filters as query-string parameters, leaving out anything at its
     * default, with $override applied on top. For links that keep the filters.
     *
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = $this->base->toQuery() + [
            'sub_department' => $this->subDepartmentId,
            'program'        => $this->programId,
            'compare'        => $this->compare !== 'sub_department' ? $this->compare : null,
            'sort'           => $this->sort !== 'name' ? $this->sort : null,
            'dir'            => $this->dir !== 'asc' ? $this->dir : null,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when any filter (not the comparison or sort) narrows the result. */
    public function isFiltered(): bool
    {
        return $this->base->isFiltered() || $this->subDepartmentId !== null || $this->programId !== null;
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

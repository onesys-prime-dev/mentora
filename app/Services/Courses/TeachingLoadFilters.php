<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * The dean's teaching-load filters and sort, read safely from a query string: a term, a sub-department
 * and which column the table is ordered by. Anything not valid is dropped or replaced by its default, so
 * a hand-edited address can never produce an error or reach beyond the department.
 */
final class TeachingLoadFilters
{
    public const SORTABLE = ['name', 'sections', 'students', 'units', 'ungraded', 'turnaround'];

    public function __construct(
        public readonly ?int $termId = null,
        public readonly ?int $subDepartmentId = null,
        public readonly string $sort = 'units',
        public readonly string $dir = 'desc',
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        $sort = is_string($input['sort'] ?? null) ? $input['sort'] : '';
        $dir  = is_string($input['dir'] ?? null) ? strtolower($input['dir']) : '';
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'units';

        return new self(
            termId: self::positiveInt($input['term'] ?? null),
            subDepartmentId: self::positiveInt($input['sub_department'] ?? null),
            sort: $sort,
            // Without a direction, a name reads A to Z and every number reads biggest first.
            dir: in_array($dir, ['asc', 'desc'], true) ? $dir : ($sort === 'name' ? 'asc' : 'desc'),
        );
    }

    /**
     * The filters as query-string parameters, leaving out anything at its default, with $override on top.
     *
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = [
            'term'           => $this->termId,
            'sub_department' => $this->subDepartmentId,
            'sort'           => $this->sort === 'units' ? null : $this->sort,
            'dir'            => $this->dir === ($this->sort === 'name' ? 'asc' : 'desc') ? null : $this->dir,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when a term or sub-department narrows the list (the sort does not). */
    public function isFiltered(): bool
    {
        return $this->termId !== null || $this->subDepartmentId !== null;
    }

    /** @param mixed $value */
    private static function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value > 0 ? (int) $value : null;
    }
}

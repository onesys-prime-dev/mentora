<?php

declare(strict_types=1);

namespace App\Services\Courses;

use DateTimeImmutable;

/**
 * The dean's chairperson-review filters, read safely from a query string: which chairperson,
 * which sub-department, a date range and whether to show what is still to review, what has been
 * reviewed or both. Anything not valid is dropped, so a hand-edited address can never fail or
 * widen the result.
 */
final class ChairpersonReviewFilters
{
    public const VIEWS = ['unreviewed', 'reviewed', 'all'];

    public function __construct(
        public readonly ?int $chairpersonId = null,
        public readonly ?int $subDepartmentId = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly string $view = 'unreviewed',
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        $view = is_string($input['view'] ?? null) ? $input['view'] : '';

        return new self(
            chairpersonId: self::positiveInt($input['chairperson'] ?? null),
            subDepartmentId: self::positiveInt($input['sub_department'] ?? null),
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null),
            view: in_array($view, self::VIEWS, true) ? $view : 'unreviewed',
        );
    }

    public function fromTimestamp(): ?string
    {
        return $this->from === null ? null : $this->from . ' 00:00:00';
    }

    /** First moment NOT included: the start of the day after "to", so the whole last day counts. */
    public function untilTimestamp(): ?string
    {
        return $this->to === null ? null : (new DateTimeImmutable($this->to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
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
            'chairperson'    => $this->chairpersonId,
            'sub_department' => $this->subDepartmentId,
            'from'           => $this->from,
            'to'             => $this->to,
            'view'           => $this->view === 'unreviewed' ? null : $this->view,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when something other than the view narrows the list. */
    public function isFiltered(): bool
    {
        return $this->chairpersonId !== null || $this->subDepartmentId !== null || $this->from !== null || $this->to !== null;
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
    private static function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value > 0 ? (int) $value : null;
    }
}

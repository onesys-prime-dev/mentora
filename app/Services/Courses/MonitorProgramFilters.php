<?php

declare(strict_types=1);

namespace App\Services\Courses;

use DateTimeImmutable;

/**
 * Monitor Program's filters, read safely from a query string. Anything not
 * valid (a bad date, an unowned term) is dropped instead of failing, so a
 * hand-edited address can never produce an error or an unexpected query.
 */
final class MonitorProgramFilters
{
    public function __construct(
        public readonly ?int $termId = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        return new self(
            termId: self::positiveInt($input['term'] ?? null),
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null),
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
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = [
            'term' => $this->termId,
            'from' => $this->from,
            'to'   => $this->to,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when any filter narrows the result. */
    public function isFiltered(): bool
    {
        return $this->termId !== null || $this->from !== null || $this->to !== null;
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

        if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}

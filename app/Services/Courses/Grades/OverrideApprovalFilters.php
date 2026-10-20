<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

/**
 * The dean's Approvals list: which tab (waiting for the dean, or already decided) and what
 * narrows it, read safely from a query string. Anything not valid is dropped, so a
 * hand-edited address can never produce an error or an unexpected query.
 */
final class OverrideApprovalFilters
{
    public const VIEWS = ['pending', 'decided'];

    private const TEXT_MAX = 100;

    public function __construct(
        public readonly string $view = 'pending',
        public readonly ?string $q = null,
        public readonly ?int $programId = null,
        public readonly ?int $requesterId = null,
    ) {
    }

    /** @param array<string, mixed> $input usually $request->getGet() */
    public static function fromInput(array $input): self
    {
        $view = is_string($input['view'] ?? null) ? $input['view'] : '';
        $q    = is_string($input['q'] ?? null) ? trim($input['q']) : '';

        return new self(
            view: in_array($view, self::VIEWS, true) ? $view : 'pending',
            q: $q === '' ? null : mb_substr($q, 0, self::TEXT_MAX),
            programId: self::positiveInt($input['program'] ?? null),
            requesterId: self::positiveInt($input['requested_by'] ?? null),
        );
    }

    /**
     * @param array<string, int|string|null> $override
     *
     * @return array<string, int|string>
     */
    public function toQuery(array $override = []): array
    {
        $query = [
            'view'         => $this->view !== 'pending' ? $this->view : null,
            'q'            => $this->q,
            'program'      => $this->programId,
            'requested_by' => $this->requesterId,
        ];

        return array_filter(array_merge($query, $override), static fn ($value) => $value !== null && $value !== '');
    }

    /** True when something narrows the list (the tab does not count). */
    public function isFiltered(): bool
    {
        return $this->q !== null || $this->programId !== null || $this->requesterId !== null;
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

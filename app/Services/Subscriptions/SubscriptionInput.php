<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reads and checks what the subscription forms send: the plan, the period,
 * and the reference-only payment details. Pure; anything not acceptable is an
 * InvalidArgumentException whose message is fit to show.
 */
final class SubscriptionInput
{
    public const REFERENCE_MAX = 100;

    public const NOTES_MAX = 1000;

    /** A period longer than this is almost certainly a typo in a year. */
    public const MAX_YEARS = 10;

    public const DEFAULT_CURRENCY = 'PHP';

    /**
     * A new term: the plan and the dates, plus the payment details.
     *
     * @param array<string, mixed> $input a form post
     *
     * @return array{plan_id: int, period_start: string, period_end: string, amount: ?string, currency: string, reference: ?string, notes: ?string}
     *
     * @throws InvalidArgumentException
     */
    public static function term(array $input): array
    {
        $start = self::date($input['period_start'] ?? null, 'The start date');
        $end   = self::date($input['period_end'] ?? null, 'The end date');

        if ($end <= $start) {
            throw new InvalidArgumentException('The end date must be after the start date.');
        }

        if ((new DateTimeImmutable($start))->modify('+' . self::MAX_YEARS . ' years') < new DateTimeImmutable($end)) {
            throw new InvalidArgumentException('A subscription period can be at most ' . self::MAX_YEARS . ' years long. Check the dates.');
        }

        return ['plan_id' => self::planId($input['plan_id'] ?? null), 'period_start' => $start, 'period_end' => $end] + self::payment($input);
    }

    /**
     * Just the payment details (what a correction can change).
     *
     * @param array<string, mixed> $input a form post
     *
     * @return array{amount: ?string, currency: string, reference: ?string, notes: ?string}
     *
     * @throws InvalidArgumentException
     */
    public static function payment(array $input): array
    {
        return [
            'amount'    => self::amount($input['amount'] ?? null),
            'currency'  => self::currency($input['currency'] ?? null),
            'reference' => self::text($input['reference'] ?? null, 'The reference', self::REFERENCE_MAX),
            'notes'     => self::text($input['notes'] ?? null, 'The notes', self::NOTES_MAX),
        ];
    }

    /** @param mixed $value */
    private static function planId($value): int
    {
        if ((is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) || is_int($value)) {
            $id = (int) $value;

            if ($id > 0) {
                return $id;
            }
        }

        throw new InvalidArgumentException('Choose a plan.');
    }

    /** @param mixed $value */
    private static function date($value, string $label): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }

        throw new InvalidArgumentException("{$label} must be a real date.");
    }

    /**
     * Blank means "not recorded". Otherwise a plain amount with up to two
     * decimals, returned with exactly two so it compares and stores evenly.
     *
     * @param mixed $value
     */
    private static function amount($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('The amount must be a number, or empty if it is not recorded.');
        }

        $text = trim(str_replace(',', '', (string) $value));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text) !== 1) {
            throw new InvalidArgumentException('The amount must be a number with at most two decimals, or empty if it is not recorded.');
        }

        return number_format((float) $text, 2, '.', '');
    }

    /** @param mixed $value */
    private static function currency($value): string
    {
        if ($value === null) {
            return self::DEFAULT_CURRENCY;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The currency must be a three-letter code such as PHP.');
        }

        $code = strtoupper(trim($value));

        if ($code === '') {
            return self::DEFAULT_CURRENCY;
        }

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException('The currency must be a three-letter code such as PHP.');
        }

        return $code;
    }

    /** @param mixed $value */
    private static function text($value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be text.");
        }

        $text = trim($value);

        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException("{$label} can be at most {$max} characters.");
        }

        return $text === '' ? null : $text;
    }
}

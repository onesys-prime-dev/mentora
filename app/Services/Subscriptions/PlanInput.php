<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use InvalidArgumentException;

/**
 * Reads and checks what a plan form sends: the name, a description, the
 * feature keys the plan grants, and three optional caps. Pure; anything not
 * acceptable is an InvalidArgumentException whose message is fit to show.
 */
final class PlanInput
{
    public const NAME_MIN = 3;

    public const NAME_MAX = 100;

    public const DESCRIPTION_MAX = 1000;

    public const MAX_FEATURES = 50;

    /** Lower-case letters, numbers, dots, dashes and underscores; starts with a letter. */
    private const FEATURE_PATTERN = '/^[a-z][a-z0-9._-]{1,63}$/';

    /** Highest cap accepted for each limit (a sanity bound, not a business rule). */
    private const LIMIT_MAX = [
        'seat_limit'          => 10_000_000,
        'storage_limit_mb'    => 100_000_000,
        'ai_generation_limit' => 10_000_000,
    ];

    private const LIMIT_LABELS = [
        'seat_limit'          => 'Student seats',
        'storage_limit_mb'    => 'Storage (MB)',
        'ai_generation_limit' => 'AI generations per term',
    ];

    /**
     * @param array<string, mixed> $input a form post
     *
     * @return array{name: string, description: ?string, features: list<string>, seat_limit: ?int, storage_limit_mb: ?int, ai_generation_limit: ?int}
     *
     * @throws InvalidArgumentException
     */
    public static function parse(array $input): array
    {
        $parsed = [
            'name'        => self::name($input['name'] ?? null),
            'description' => self::description($input['description'] ?? null),
            'features'    => self::features($input['features'] ?? null),
        ];

        foreach (self::LIMIT_LABELS as $field => $label) {
            $parsed[$field] = self::limit($input[$field] ?? null, $label, self::LIMIT_MAX[$field]);
        }

        return $parsed;
    }

    /** @param mixed $value */
    private static function name($value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException(sprintf('The plan name must be %d to %d characters.', self::NAME_MIN, self::NAME_MAX));
        }

        return $name;
    }

    /** @param mixed $value */
    private static function description($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The description must be text.');
        }

        $text = trim($value);

        if (mb_strlen($text) > self::DESCRIPTION_MAX) {
            throw new InvalidArgumentException(sprintf('The description can be at most %d characters.', self::DESCRIPTION_MAX));
        }

        return $text === '' ? null : $text;
    }

    /**
     * Feature keys, separated by commas, spaces or new lines. Lower-cased,
     * de-duplicated and sorted so the same set is always stored the same way.
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function features($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Features must be a list of keys.');
        }

        $keys = preg_split('/[\s,]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY);
        $keys = array_values(array_unique($keys === false ? [] : $keys));

        foreach ($keys as $key) {
            if (preg_match(self::FEATURE_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException("\"{$key}\" is not a valid feature key. Use lower-case letters, numbers, dots, dashes and underscores.");
            }
        }

        if (count($keys) > self::MAX_FEATURES) {
            throw new InvalidArgumentException(sprintf('A plan can grant at most %d features.', self::MAX_FEATURES));
        }

        sort($keys);

        return $keys;
    }

    /**
     * A whole number from 1 up, or nothing (blank) for unlimited.
     *
     * @param mixed $value
     */
    private static function limit($value, string $label, int $max): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$label} must be a whole number, or empty for unlimited.");
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}$/', $text) !== 1 || (int) $text < 1 || (int) $text > $max) {
            throw new InvalidArgumentException(sprintf('%s must be a whole number from 1 to %s, or empty for unlimited.', $label, number_format($max)));
        }

        return (int) $text;
    }
}

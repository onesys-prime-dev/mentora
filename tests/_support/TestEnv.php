<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Sets a setting for a test, whatever the local .env holds (audit decision 14.1).
 *
 * CodeIgniter's env() reads $_ENV first, then $_SERVER, then getenv(); a .env
 * value sits in all three. A test that set only one of them was beaten by a
 * developer's .env, so set() and unset() change all three, and restore() puts
 * back what was there. Call restore() in tearDown.
 *
 * @internal
 */
final class TestEnv
{
    /** @var array<string, array{env: string|null, server: string|null, getenv: string|false}> the first value seen, by name */
    private static array $saved = [];

    public static function set(string $name, string $value): void
    {
        self::remember($name);

        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    public static function unset(string $name): void
    {
        self::remember($name);

        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    public static function restore(): void
    {
        foreach (self::$saved as $name => $was) {
            if ($was['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $was['env'];
            }

            if ($was['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $was['server'];
            }

            putenv($was['getenv'] === false ? $name : "{$name}={$was['getenv']}");
        }

        self::$saved = [];
    }

    private static function remember(string $name): void
    {
        if (array_key_exists($name, self::$saved)) {
            return;
        }

        self::$saved[$name] = [
            'env'    => isset($_ENV[$name]) ? (string) $_ENV[$name] : null,
            'server' => isset($_SERVER[$name]) ? (string) $_SERVER[$name] : null,
            'getenv' => getenv($name),
        ];
    }
}

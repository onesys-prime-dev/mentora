<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use InvalidArgumentException;

/**
 * Reads and checks who is being invited: first name, last name, email. Pure;
 * anything not acceptable is an InvalidArgumentException whose message is fit
 * to show. (Whether an account with that email already exists needs the
 * database and is checked by InvitationService.)
 */
final class InvitationInput
{
    public const NAME_MIN = 2;

    public const NAME_MAX = 100;

    public const EMAIL_MAX = 254;

    /**
     * @param array<string, mixed> $input a form post: first_name, last_name, email
     *
     * @return array{first_name: string, last_name: string, email: string}
     *
     * @throws InvalidArgumentException
     */
    public static function person(array $input): array
    {
        return [
            'first_name' => self::name($input['first_name'] ?? null, 'The first name'),
            'last_name'  => self::name($input['last_name'] ?? null, 'The last name'),
            'email'      => self::email($input['email'] ?? null),
        ];
    }

    /** @param mixed $value */
    private static function name($value, string $label): string
    {
        $name = is_string($value) ? trim($value) : '';
        $size = mb_strlen($name);

        if ($size < self::NAME_MIN || $size > self::NAME_MAX) {
            throw new InvalidArgumentException("{$label} must be " . self::NAME_MIN . ' to ' . self::NAME_MAX . ' characters.');
        }

        // No line breaks or control characters: the name is printed in an email.
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw new InvalidArgumentException("{$label} contains characters that are not allowed.");
        }

        return $name;
    }

    /** @param mixed $value */
    private static function email($value): string
    {
        $email = is_string($value) ? strtolower(trim($value)) : '';

        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address for the administrator.');
        }

        return $email;
    }
}

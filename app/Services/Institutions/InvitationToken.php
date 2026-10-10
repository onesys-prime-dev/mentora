<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * The secret in an invitation link. It is 256 random bits, shown to the
 * invitee once (in the email) and never stored: the database keeps only its
 * SHA-256 hash, so reading the table does not let anyone accept an invitation.
 */
final class InvitationToken
{
    /** Length of a token: 32 bytes as unpadded base64url. */
    public const LENGTH = 43;

    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether the text has the shape of a token, so nonsense never reaches the database. */
    public static function looksValid(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{' . self::LENGTH . '}$/', $token) === 1;
    }
}

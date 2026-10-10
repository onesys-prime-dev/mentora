<?php

declare(strict_types=1);

namespace App\Services\Users;

/**
 * Turns user rows (as UserDirectory returns them) into CSV for a spreadsheet.
 *
 * A cell that starts with = + - @ or a tab or carriage return would be run as
 * a formula by a spreadsheet, so an attacker-chosen name could do harm when an
 * administrator opens the file. Such cells get a leading apostrophe.
 */
final class UserExport
{
    /** @return list<string> */
    public static function header(): array
    {
        return ['ID', 'Name', 'Email', 'Username', 'Roles', 'Institutions', 'Status', 'Last active', 'Created'];
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return list<string>
     */
    public static function row(array $user): array
    {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        $roles = array_map(
            static fn (string $role): string => UserFilters::ROLE_LABELS[$role] ?? $role,
            $user['roles'] ?? [],
        );

        $institutions = array_map(static fn (array $institution): string => (string) $institution['name'], $user['institutions'] ?? []);

        return array_map(self::safeCell(...), [
            (string) $user['id'],
            $name,
            (string) ($user['email'] ?? ''),
            (string) ($user['username'] ?? ''),
            implode(', ', $roles),
            implode(', ', $institutions),
            (int) $user['active'] === 1 && ($user['deactivated_at'] ?? null) === null ? 'Active' : 'Deactivated',
            (string) ($user['last_active'] ?? ''),
            (string) ($user['created_at'] ?? ''),
        ]);
    }

    /** @param mixed $value */
    public static function safeCell($value): string
    {
        $text = (string) $value;

        return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }

    /**
     * The whole CSV for the given rows.
     *
     * @param iterable<array<string, mixed>> $users
     *
     * @return array{csv: string, rows: int}
     */
    public static function csv(iterable $users): array
    {
        $stream = fopen('php://temp', 'r+');
        $rows   = 0;

        fputcsv($stream, self::header());

        foreach ($users as $user) {
            fputcsv($stream, self::row($user));
            $rows++;
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return ['csv' => $csv, 'rows' => $rows];
    }
}

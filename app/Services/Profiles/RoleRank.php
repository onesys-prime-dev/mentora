<?php

declare(strict_types=1);

namespace App\Services\Profiles;

/** Profile role order (D12). Roles outside it (system admin) are not profile roles and are dropped. */
final class RoleRank
{
    public const ORDER = ['dean', 'chairperson', 'instructor', 'institutional_admin', 'student', 'parent'];

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public static function sort(array $roles): array
    {
        return array_values(array_filter(self::ORDER, static fn (string $r): bool => in_array($r, $roles, true)));
    }

    /** @param list<string> $roles */
    public static function highest(array $roles): ?string
    {
        return self::sort($roles)[0] ?? null;
    }
}

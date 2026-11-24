<?php

declare(strict_types=1);

namespace App\Services\Profiles;

/** Name, initials and colour for avatars, many people at once. Task 1.4's JS mirrors COLOURS. */
final class AvatarData
{
    /** Each has at least 4.5:1 contrast against white text (Tailwind 700 shades). */
    public const COLOURS = ['#B91C1C', '#C2410C', '#A16207', '#15803D', '#0F766E', '#1D4ED8', '#6D28D9', '#BE185D'];

    /**
     * @param list<int> $userIds
     *
     * @return array<int, array{id:int, name:string, initials:string, colour:string, photo_url:?string}>
     */
    public function forMany(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return [];
        }

        $rows = db_connect()->table('user_profiles')
            ->select('user_id, first_name, last_name, preferred_first_name')
            ->whereIn('user_id', $ids)->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $id    = (int) $r['user_id'];
            $first = trim((string) ($r['preferred_first_name'] ?: $r['first_name']));
            $last  = trim((string) $r['last_name']);

            $out[$id] = [
                'id'        => $id,
                'name'      => trim($first . ' ' . $last),
                'initials'  => mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)),
                'colour'    => self::COLOURS[$id % 8],
                'photo_url' => null,
            ];
        }

        return $out;
    }
}

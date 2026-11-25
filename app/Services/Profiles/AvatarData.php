<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use CodeIgniter\Shield\Entities\User;

/** Name, initials and colour for avatars, many people at once. Task 1.4's JS mirrors COLOURS. */
final class AvatarData
{
    /** Each has at least 4.5:1 contrast against white text (Tailwind 700 shades). */
    public const COLOURS = ['#B91C1C', '#C2410C', '#A16207', '#15803D', '#0F766E', '#1D4ED8', '#6D28D9', '#BE185D'];

    /**
     * Warning: this does not check the viewer may see these people. Use it only for the viewer
     * themself or for subjects ProfilePolicy has already cleared; anything else goes through forViewer().
     *
     * photo_url is the 64 px photo, photo_url_lg the 256 px one (components/avatar picks by size);
     * both are null without a photo or while the person's institution has photos off.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{id:int, name:string, initials:string, colour:string, photo_url:?string, photo_url_lg:?string}>
     */
    public function forMany(array $userIds): array
    {
        return $this->rows($userIds, null);
    }

    /**
     * @param list<int>             $userIds
     * @param array<int, mixed>|null $public ids whose public profile fields (preferred name, photo) may be used; null = all
     *
     * @return array<int, array{id:int, name:string, initials:string, colour:string, photo_url:?string, photo_url_lg:?string}>
     */
    private function rows(array $userIds, ?array $public): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return [];
        }

        $rows = db_connect()->table('user_profiles up')
            ->select('up.user_id, up.first_name, up.last_name, up.preferred_first_name, up.photo_file_id, ' . ProfilePhotoService::ENABLED_SQL . ' AS photos_on', false)
            ->whereIn('up.user_id', $ids)->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $id    = (int) $r['user_id'];
            // Someone the viewer may not see keeps the legal name the page prints anyway, and no photo (FR2).
            $shown = $public === null || isset($public[$id]);
            $first = trim((string) (($shown ? $r['preferred_first_name'] : null) ?: $r['first_name']));
            $last  = trim((string) $r['last_name']);

            $out[$id] = [
                'id'        => $id,
                'name'      => trim($first . ' ' . $last),
                'initials'  => mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)),
                'colour'    => self::COLOURS[$id % 8],
                'photo_url'    => $shown ? ProfilePhotoService::urlFor($id, $r['photo_file_id'], $r['photos_on'], 64) : null,
                'photo_url_lg' => $shown ? ProfilePhotoService::urlFor($id, $r['photo_file_id'], $r['photos_on'], 256) : null,
            ];
        }

        return $out;
    }

    /**
     * forMany() plus whether each avatar may open the person's card for this viewer (FR2), for a page's
     * whole list of people at once. People without a profile row are left out.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{person: array{id:int, name:string, initials:string, colour:string, photo_url:?string, photo_url_lg:?string}, interactive: bool}>
     */
    public function forViewer(User $viewer, array $userIds): array
    {
        $visible = array_flip((new ProfileContextResolver())->visibleIds($viewer, $userIds));

        return array_map(static fn (array $person): array => ['person' => $person, 'interactive' => isset($visible[$person['id']])], $this->rows($userIds, $visible));
    }

    /**
     * A list re-sorted by the name it shows, once names are gated, so a preferred name does not sit out of order.
     * Stable (usort keeps ties in their order), case-insensitive, natural ("No2" before "No10").
     *
     * @template T of array<string, mixed>
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    public static function sortByName(array $rows, string $key = 'name'): array
    {
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) $a[$key], (string) $b[$key]));

        return $rows;
    }

    /**
     * forViewer() in the shape resources/js/shared/avatar.js draws, for JSON lists (Messages, groups).
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{id:int, name:string, initials:string, colour:string, photoUrl:?string, photoUrlLg:?string, interactive:bool}>
     */
    public function forViewerJson(User $viewer, array $userIds): array
    {
        return array_map(static fn (array $a): array => [
            'id' => $a['person']['id'], 'name' => $a['person']['name'], 'initials' => $a['person']['initials'], 'colour' => $a['person']['colour'],
            'photoUrl' => $a['person']['photo_url'], 'photoUrlLg' => $a['person']['photo_url_lg'], 'interactive' => $a['interactive'],
        ], $this->forViewer($viewer, $userIds));
    }
}

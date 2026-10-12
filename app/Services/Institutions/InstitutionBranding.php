<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Entities\User;

/**
 * What the app shell needs to look like an institution: the custom
 * properties for its colours and its logo and name, for the signed-in person.
 *
 * Only people who belong to an institution get it. System administrators
 * belong to none, so the platform's own colours stay for the vendor's area.
 * Worked out once per request.
 */
class InstitutionBranding
{
    /** @var array<string, array{css: string, logoUrl: ?string, name: string}|null> */
    private static array $memo = [];

    /**
     * @return array{css: string, logoUrl: ?string, name: string}|null null when there is nothing to show
     */
    public function forUser(?User $user): ?array
    {
        if ($user === null || $user->inGroup('admin')) {
            return null;
        }

        $userId = (int) $user->id;

        // Per person and per request: a long-running process (a test run, a worker) must not
        // carry one request's answer into the next.
        $key = $userId . ':' . spl_object_id(service('request'));

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        self::$memo = [];

        $institutionId = (new PlanLimits())->institutionOf($userId);
        $row           = $institutionId === null ? null : db_connect()->table('institutions')
            ->select('name, logo_file_id, brand_primary, brand_accent')
            ->where('id', $institutionId)->get()->getRowArray();

        if ($row === null) {
            return self::$memo[$key] = null;
        }

        $css  = BrandColours::css($row['brand_primary'], $row['brand_accent']);
        $logo = $row['logo_file_id'] === null ? null : site_url('files/' . (int) $row['logo_file_id']);

        return self::$memo[$key] = ($css === '' && $logo === null) ? null : ['css' => $css, 'logoUrl' => $logo, 'name' => (string) $row['name']];
    }

    /** For tests and long-running workers: forget what was worked out. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}

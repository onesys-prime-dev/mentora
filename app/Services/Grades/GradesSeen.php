<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Models\Grades\GradesSeenModel;
use App\Services\Admin\Impersonation;

/**
 * What a viewer last saw of a student's grades, so a course whose grade has moved
 * since can be badged "Changed" once.
 *
 * A grade is compared by its signature, a hash of the figures the page shows. A
 * first look is never a change. Viewing as someone remembers nothing: the person
 * must still see the badge when they open the page themselves.
 */
final class GradesSeen
{
    /** @param array{percentage: ?float, band: ?string, tag: string, finalized: bool, signed_off: bool} $row */
    public static function signature(array $row): string
    {
        $percentage = $row['percentage'] === null ? '' : (string) round((float) $row['percentage'], 2);

        return hash('sha256', implode('|', [
            $percentage,
            $row['band'] ?? '',
            $row['tag'],
            $row['finalized'] ? '1' : '0',
            $row['signed_off'] ? '1' : '0',
        ]));
    }

    /**
     * The signature of a course row as the Grades page shows it: an unpublished row shows only its chip (a
     * withdrawn one keeps its Withdrawn tag), so a grade published since the last look is a change too. The page
     * and the tile badge both compare this one.
     *
     * @param array{percentage: ?float, band: ?string, tag: string, finalized: bool, signed_off: bool, published: bool, withdrawn: bool} $row
     */
    public static function shownSignature(array $row): string
    {
        return self::signature($row['published'] || $row['withdrawn'] ? $row : ['tag' => 'Not published yet'] + $row);
    }

    /**
     * True for an offering only when a signature was stored and the current one differs.
     *
     * @param array<int, string> $signatures offering id => current signature
     *
     * @return array<int, bool>
     */
    public function changed(int $viewerId, int $studentId, array $signatures): array
    {
        if ($signatures === []) {
            return [];
        }

        $seen    = model(GradesSeenModel::class)->signaturesFor($viewerId, $studentId, array_keys($signatures));
        $changed = [];

        foreach ($signatures as $offeringId => $signature) {
            $changed[$offeringId] = isset($seen[$offeringId]) && $seen[$offeringId] !== $signature;
        }

        return $changed;
    }

    /**
     * Stores the signatures as seen now, in one statement.
     *
     * @param array<int, string> $signatures offering id => signature
     */
    public function remember(int $viewerId, int $studentId, array $signatures): void
    {
        if ($signatures === [] || Impersonation::active()) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($signatures as $offeringId => $signature) {
            $rows[] = [
                'viewer_user_id'  => $viewerId,
                'student_user_id' => $studentId,
                'offering_id'     => $offeringId,
                'signature'       => $signature,
                'seen_at'         => $now,
            ];
        }

        model(GradesSeenModel::class)->upsertSeen($rows);
    }
}

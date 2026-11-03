<?php

declare(strict_types=1);

namespace App\Services\Grades;

use App\Services\Courses\Grades\GradeFinalizationService;
use CodeIgniter\Shield\Entities\User;

/**
 * Finalize ready sections: every section card that reads "ready" is finalized through the existing
 * finalization, one by one, and each one that is not is named with why.
 *
 * The cards come from InstructorGradesOverview::sections, so they are only the sections this person
 * teaches. The course filters do not run outside api/courses, so the checks they make are repeated here
 * per section (spec §13): a section that is not active is skipped, and the full checklist runs again
 * right before finalizing, because a card can be stale by then (new work handed in, a request opened).
 * GradeFinalizationService::finalize runs readiness() itself and refuses with its first blocker, so that
 * check and the finalizing are one step. Whether the person may manage grading at all is the caller's
 * check, made once for the whole request.
 */
final class ReadySectionFinalizer
{
    public const CLOSED = 'This section is closed.';

    /**
     * @param list<array{offering_id: int, course_code: string, section_code: string, state: string, offering_status: string}> $cards
     *
     * @return array{done: list<array{offering_id: int, label: string}>, skipped: list<array{label: string, reason: string}>}
     */
    public function finalize(User $user, array $cards): array
    {
        $finalization = new GradeFinalizationService();
        $done         = [];
        $skipped      = [];

        foreach ($cards as $card) {
            if ($card['state'] !== 'ready') {
                continue;
            }

            $label = $card['course_code'] . '-' . $card['section_code'];

            if ($card['offering_status'] !== 'active') {
                $skipped[] = ['label' => $label, 'reason' => self::CLOSED];

                continue;
            }

            $result = $finalization->finalize($user, (int) $card['offering_id']);

            if ($result['success']) {
                $done[] = ['offering_id' => (int) $card['offering_id'], 'label' => $label];
            } else {
                $skipped[] = ['label' => $label, 'reason' => (string) $result['message']];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }
}

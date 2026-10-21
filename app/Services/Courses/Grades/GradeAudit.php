<?php

namespace App\Services\Courses\Grades;

use App\Services\Audit\AuditLogger;
use CodeIgniter\Shield\Entities\User;

/**
 * The gradebook's audit trail: who changed what, when, in which section, and
 * in which role (so a chairperson's edit says so). Final-grade overrides also
 * keep their own per-student history (grade_history); everything else that
 * can move a grade is recorded here as `grades.*` audit entries.
 */
class GradeAudit
{
    /**
     * @param array{old?: ?array<string, mixed>, new?: ?array<string, mixed>, target_type?: string, target_id?: int|string|null} $details
     */
    public static function record(string $action, int $offeringId, array $details = [], ?User $actor = null): void
    {
        $actor ??= auth()->user();

        (new AuditLogger())->log($action, $actor, $details + [
            'target_type' => 'course_offering',
            'target_id' => $offeringId,
        ]);
    }
}

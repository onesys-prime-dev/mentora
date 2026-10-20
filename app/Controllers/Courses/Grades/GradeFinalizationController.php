<?php

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeFinalizationService;

/**
 * Finalizing a section's grades: what is left to do first, the finalize
 * itself, and reopening with a reason.
 */
class GradeFinalizationController extends BaseController
{
    protected GradeFinalizationService $finalization;

    public function __construct()
    {
        $this->finalization = new GradeFinalizationService();
    }

    public function show(int $offeringId)
    {
        return $this->response->setJSON([
            'success' => true,
            'current' => $this->finalization->current($offeringId),
            'readiness' => $this->finalization->readiness($offeringId),
        ]);
    }

    public function finalize(int $offeringId)
    {
        return $this->response->setJSON(
            $this->finalization->finalize(auth()->user(), $offeringId)
        );
    }

    public function reopen(int $offeringId)
    {
        $payload = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->finalization->reopen(auth()->user(), $offeringId, (string) ($payload['reason'] ?? ''))
        );
    }
}

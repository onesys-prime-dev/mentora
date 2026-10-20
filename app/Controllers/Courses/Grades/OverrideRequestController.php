<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeOverrideRequestService;

/**
 * The requester's two moves on a grade-override request in their course: edit
 * one that was sent back (and send it again), or withdraw an open one. Both
 * answer JSON for the gradebook, and the service checks the request is theirs
 * and belongs to the offering in the address.
 */
class OverrideRequestController extends BaseController
{
    public function update(int $offeringId, int $requestId)
    {
        $payload = $this->request->getJSON(true) ?? [];

        $percentage = array_key_exists('percentage', $payload) && $payload['percentage'] !== null && $payload['percentage'] !== ''
            ? (float) $payload['percentage']
            : null;

        return $this->response->setJSON(
            (new GradeOverrideRequestService())->resubmit(auth()->user(), $offeringId, $requestId, $percentage, (string) ($payload['reason'] ?? ''))
        );
    }

    public function withdraw(int $offeringId, int $requestId)
    {
        return $this->response->setJSON(
            (new GradeOverrideRequestService())->withdraw(auth()->user(), $offeringId, $requestId)
        );
    }
}

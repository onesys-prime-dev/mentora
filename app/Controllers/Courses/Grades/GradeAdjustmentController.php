<?php

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeAdjustmentService;

/**
 * Grading on a curve: the curves in force and the items one can go on, a
 * preview, applying, and taking one off.
 */
class GradeAdjustmentController extends BaseController
{
    protected GradeAdjustmentService $curves;

    public function __construct()
    {
        $this->curves = new GradeAdjustmentService();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->request->getJSON(true) ?? [];
    }

    public function show(int $offeringId)
    {
        return $this->response->setJSON(['success' => true] + $this->curves->describe($offeringId));
    }

    public function preview(int $offeringId)
    {
        $data = $this->payload();

        return $this->response->setJSON($this->curves->preview(
            $offeringId,
            (string) ($data['scope'] ?? ''),
            isset($data['item_id']) && $data['item_id'] !== '' ? (int) $data['item_id'] : null,
            (string) ($data['method'] ?? ''),
            (array) ($data['input'] ?? [])
        ));
    }

    public function apply(int $offeringId)
    {
        $data = $this->payload();

        return $this->response->setJSON($this->curves->apply(
            auth()->user(),
            $offeringId,
            (string) ($data['scope'] ?? ''),
            isset($data['item_id']) && $data['item_id'] !== '' ? (int) $data['item_id'] : null,
            (string) ($data['method'] ?? ''),
            (array) ($data['input'] ?? []),
            (string) ($data['note'] ?? '')
        ));
    }

    public function undo(int $offeringId, int $adjustmentId)
    {
        return $this->response->setJSON($this->curves->undo(auth()->user(), $offeringId, $adjustmentId));
    }
}

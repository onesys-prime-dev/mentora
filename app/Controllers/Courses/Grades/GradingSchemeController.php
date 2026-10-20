<?php

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeAudit;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\GradingSchemeService;

/**
 * Instructor-only: the section's grading scheme editor.
 */
class GradingSchemeController extends BaseController
{
    protected GradingSchemeService $schemeService;

    public function __construct()
    {
        $this->schemeService = new GradingSchemeService();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->request->getJSON(true) ?? [];
    }

    /**
     * Sends a service result back, recording the change in the grade audit
     * trail when it went through.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $details what was changed
     */
    private function audited(string $action, int $offeringId, array $result, array $details = [])
    {
        if (!empty($result['success'])) {
            GradeAudit::record($action, $offeringId, ['new' => $details]);
        }

        return $this->response->setJSON($result);
    }

    public function show(int $offeringId)
    {
        $data = $this->schemeService->describe($offeringId);

        return $this->response->setJSON([
            'success' => true,
            'html' => view('pages/courses/features/grades/scheme-editor', $data),
            'scheme' => $data['scheme'],
            'total_weight' => $data['total_weight'],
        ]);
    }

    public function update(int $offeringId)
    {
        $data = $this->payload();

        return $this->audited('grades.scheme_updated', $offeringId, $this->schemeService->updateScheme(auth()->user(), $offeringId, $data), $data);
    }

    public function storeCategory(int $offeringId)
    {
        $data = $this->payload();

        return $this->audited('grades.category_created', $offeringId, $this->schemeService->createCategory($offeringId, $data), $data);
    }

    public function updateCategory(int $offeringId, int $categoryId)
    {
        $data = $this->payload();

        return $this->audited('grades.category_updated', $offeringId, $this->schemeService->updateCategory($offeringId, $categoryId, $data), ['category_id' => $categoryId] + $data);
    }

    public function deleteCategory(int $offeringId, int $categoryId)
    {
        return $this->audited('grades.category_deleted', $offeringId, $this->schemeService->deleteCategory($offeringId, $categoryId), ['category_id' => $categoryId]);
    }

    public function storeItem(int $offeringId, int $categoryId)
    {
        $data = $this->payload();

        return $this->audited('grades.item_created', $offeringId, $this->schemeService->createManualItem(auth()->user(), $offeringId, $categoryId, $data), ['category_id' => $categoryId] + $data);
    }

    public function updateItem(int $offeringId, int $itemId)
    {
        $data = $this->payload();

        return $this->audited('grades.item_updated', $offeringId, $this->schemeService->updateItem(auth()->user(), $offeringId, $itemId, $data), ['item_id' => $itemId] + $data);
    }

    public function deleteItem(int $offeringId, int $itemId)
    {
        return $this->audited('grades.item_deleted', $offeringId, $this->schemeService->deleteItem(auth()->user(), $offeringId, $itemId), ['item_id' => $itemId]);
    }

    public function resyncItem(int $offeringId, int $itemId)
    {
        return $this->audited('grades.item_resynced', $offeringId, $this->schemeService->resyncItem(auth()->user(), $offeringId, $itemId), ['item_id' => $itemId]);
    }

    public function recalculate(int $offeringId)
    {
        // Recorded by the service, with how many students were recalculated.
        return $this->response->setJSON(
            $this->schemeService->recalculate($offeringId)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Grading periods
    |--------------------------------------------------------------------------
    */

    public function storePeriod(int $offeringId)
    {
        $data = $this->payload();

        return $this->audited('grades.period_created', $offeringId, $this->schemeService->createPeriod($offeringId, $data), $data);
    }

    public function updatePeriod(int $offeringId, int $periodId)
    {
        $data = $this->payload();

        return $this->audited('grades.period_updated', $offeringId, $this->schemeService->updatePeriod($offeringId, $periodId, $data), ['period_id' => $periodId] + $data);
    }

    public function deletePeriod(int $offeringId, int $periodId)
    {
        return $this->audited('grades.period_deleted', $offeringId, $this->schemeService->deletePeriod($offeringId, $periodId), ['period_id' => $periodId]);
    }

    /**
     * The gradebook grid: manual scores + final grades, with override.
     */
    public function roster(int $offeringId)
    {
        $data = $this->schemeService->roster($offeringId);

        // How a change to a final grade is made here (a request, or at once) and where each open request stands.
        $requests = new GradeOverrideRequestService();
        $isRequest = $requests->appliesTo($offeringId);

        $data['overrideMode']  = $isRequest ? 'request' : 'direct';
        $data['overrideRoute'] = $isRequest ? $requests->routeText(auth()->user(), $offeringId) : '';
        $data['openRequests']  = $isRequest ? $requests->openByStudent($offeringId) : [];

        return $this->response->setJSON([
            'success' => true,
            'html' => view('pages/courses/features/grades/roster', $data),
        ]);
    }

    public function updateManualScore(int $offeringId, int $itemId, int $studentId)
    {
        $payload = $this->payload();

        $score = array_key_exists('score', $payload) && $payload['score'] !== null && $payload['score'] !== ''
            ? (float) $payload['score']
            : null;

        // Recorded by the service, with the previous and the new score.
        return $this->response->setJSON(
            $this->schemeService->updateManualScore(auth()->user(), $offeringId, $itemId, $studentId, $score)
        );
    }

    /** Marks a student's grade Incomplete (INC) or takes it off. */
    public function setIncomplete(int $offeringId, int $studentId)
    {
        $payload = $this->payload();

        $incomplete = !empty($payload['incomplete']);

        $until = isset($payload['until']) && $payload['until'] !== '' ? (string) $payload['until'] : null;

        // Recorded by the engine (grades.incomplete_set / grades.incomplete_cleared).
        return $this->response->setJSON(
            $this->schemeService->setIncomplete(auth()->user(), $offeringId, $studentId, $incomplete, (string) ($payload['reason'] ?? ''), $until)
        );
    }

    public function overrideGrade(int $offeringId, int $studentId)
    {
        $payload = $this->payload();

        $percentage = array_key_exists('percentage', $payload) && $payload['percentage'] !== null && $payload['percentage'] !== ''
            ? (float) $payload['percentage']
            : null;

        // Recorded by the engine (audit entry and the per-student grade history).
        // Where the institution has deans an override is a request that climbs the chain and the
        // grade stays as calculated until it is approved; elsewhere it applies at once, as it always did.
        $requests = new GradeOverrideRequestService();

        if ($requests->appliesTo($offeringId)) {
            return $this->response->setJSON(
                $requests->request(auth()->user(), $offeringId, $studentId, $percentage, (string) ($payload['reason'] ?? ''))
            );
        }

        return $this->response->setJSON(
            $this->schemeService->overrideGrade(auth()->user(), $offeringId, $studentId, $percentage, (string) ($payload['reason'] ?? ''))
        );
    }
}

<?php

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
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
        return $this->response->setJSON(
            $this->schemeService->updateScheme(auth()->user(), $offeringId, $this->request->getJSON(true) ?? [])
        );
    }

    public function storeCategory(int $offeringId)
    {
        return $this->response->setJSON(
            $this->schemeService->createCategory($offeringId, $this->request->getJSON(true) ?? [])
        );
    }

    public function updateCategory(int $offeringId, int $categoryId)
    {
        return $this->response->setJSON(
            $this->schemeService->updateCategory($offeringId, $categoryId, $this->request->getJSON(true) ?? [])
        );
    }

    public function deleteCategory(int $offeringId, int $categoryId)
    {
        return $this->response->setJSON(
            $this->schemeService->deleteCategory($offeringId, $categoryId)
        );
    }

    public function storeItem(int $offeringId, int $categoryId)
    {
        return $this->response->setJSON(
            $this->schemeService->createManualItem(auth()->user(), $offeringId, $categoryId, $this->request->getJSON(true) ?? [])
        );
    }

    public function updateItem(int $offeringId, int $itemId)
    {
        return $this->response->setJSON(
            $this->schemeService->updateItem(auth()->user(), $offeringId, $itemId, $this->request->getJSON(true) ?? [])
        );
    }

    public function deleteItem(int $offeringId, int $itemId)
    {
        return $this->response->setJSON(
            $this->schemeService->deleteItem(auth()->user(), $offeringId, $itemId)
        );
    }

    public function resyncItem(int $offeringId, int $itemId)
    {
        return $this->response->setJSON(
            $this->schemeService->resyncItem(auth()->user(), $offeringId, $itemId)
        );
    }

    public function recalculate(int $offeringId)
    {
        return $this->response->setJSON(
            $this->schemeService->recalculate($offeringId)
        );
    }

    /**
     * The gradebook grid: manual scores + final grades, with override.
     */
    public function roster(int $offeringId)
    {
        $data = $this->schemeService->roster($offeringId);

        return $this->response->setJSON([
            'success' => true,
            'html' => view('pages/courses/features/grades/roster', $data),
        ]);
    }

    public function updateManualScore(int $offeringId, int $itemId, int $studentId)
    {
        $payload = $this->request->getJSON(true) ?? [];

        $score = array_key_exists('score', $payload) && $payload['score'] !== null && $payload['score'] !== ''
            ? (float) $payload['score']
            : null;

        return $this->response->setJSON(
            $this->schemeService->updateManualScore(auth()->user(), $offeringId, $itemId, $studentId, $score)
        );
    }

    public function overrideGrade(int $offeringId, int $studentId)
    {
        $payload = $this->request->getJSON(true) ?? [];

        $percentage = array_key_exists('percentage', $payload) && $payload['percentage'] !== null && $payload['percentage'] !== ''
            ? (float) $payload['percentage']
            : null;

        return $this->response->setJSON(
            $this->schemeService->overrideGrade(auth()->user(), $offeringId, $studentId, $percentage, (string) ($payload['reason'] ?? ''))
        );
    }
}

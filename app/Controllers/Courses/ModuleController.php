<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\LearningProgressService;
use App\Services\Courses\ModuleService;
use CodeIgniter\Exceptions\PageNotFoundException;

class ModuleController extends BaseController
{
    protected ModuleService $moduleService;
    protected LearningProgressService $progressService;

    public function __construct()
    {
        $this->moduleService = new ModuleService();
        $this->progressService = new LearningProgressService();
    }

    public function show(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->moduleService->show(
                $user,
                $offeringId
            )
        );
    }

    /** Raw module/unit list for a picker (see ModuleService::listForPicker). */
    public function picker(int $offeringId)
    {
        $user = auth()->user();

        return $this->response->setJSON([
            'success' => true,
            'modules' => $this->moduleService->listForPicker($user, $offeringId),
        ]);
    }

    public function viewLearningItem(
        int $offeringId,
        int $learningItemId
    ) {
        $user = auth()->user();

        $result = $this->moduleService->viewLearningItem(
            $user,
            $offeringId,
            $learningItemId
        );

        // Locked for this student: refused, with the reason.
        if (!empty($result['locked'])) {
            $this->response->setStatusCode(403);
        }

        return $this->response->setJSON($result);
    }

    public function viewLearningItemEditor(
        int $offeringId,
        int $learningItemId
    ) {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->moduleService->viewLearningItemEditor(
                $user,
                $offeringId,
                $learningItemId
            )
        );
    }

    public function storeModule(int $offeringId)
    {
        return $this->response->setJSON(

            $this->moduleService->storeModule(
                auth()->user(),
                $offeringId,
                $this->request
            )

        );
    }

    public function updateModule(
        int $offeringId,
        int $moduleId
    ) {
        return $this->response->setJSON(

            $this->moduleService->updateModule(
                auth()->user(),
                $offeringId,
                $moduleId,
                $this->request
            )

        );
    }

    public function updateModuleStatus(
        int $offeringId,
        int $moduleId
    ) {

        $payload =
            $this->request->getJSON(true);

        return $this->response->setJSON(

            $this->moduleService
                ->updateModuleStatus(

                    auth()->user(),

                    $offeringId,

                    $moduleId,

                    $payload['status'] ?? 'draft'

                )

        );

    }

    public function deleteModule(
        int $offeringId,
        int $moduleId
    ) {

        return $this->response->setJSON(

            $this->moduleService->deleteModule(

                auth()->user(),

                $offeringId,

                $moduleId

            )

        );

    }

    public function reorderModules(
        int $offeringId
    ) {

        $user =
            auth()->user();

        $payload =
            $this->request->getJSON(true);

        return $this->response->setJSON(

            $this->moduleService
                ->reorderModules(

                    $user,

                    $offeringId,

                    $payload['order'] ?? []

                )

        );

    }

    public function storeLearningUnit(
        int $offeringId,
        int $moduleId
    )
    {
        return $this->response->setJSON(

            $this->moduleService->storeLearningUnit(

                auth()->user(),

                $offeringId,

                $moduleId,

                $this->request

            )

        );
    }

    public function updateLearningUnit(
        int $offeringId,
        int $moduleId,
        int $learningUnitId
    ) {
        return $this->response->setJSON(

            $this->moduleService->updateLearningUnit(
                auth()->user(),
                $offeringId,
                $moduleId,
                $learningUnitId,
                $this->request
            )

        );
    }

    public function updateLearningUnitStatus(
        int $offeringId,
        int $learningUnitId
    ) {

        $user =
            auth()->user();

        $payload =
            $this->request->getJSON(true);

        return $this->response->setJSON(

            $this->moduleService
                ->updateLearningUnitStatus(

                    $user,

                    $offeringId,

                    $learningUnitId,

                    $payload

                )

        );

    }

    public function deleteLearningUnit(
        int $offeringId,
        int $moduleId,
        int $learningUnitId
    ) {

        return $this->response->setJSON(

            $this->moduleService->deleteLearningUnit(

                auth()->user(),

                $offeringId,

                $moduleId,

                $learningUnitId

            )

        );

    }

    public function reorderLearningUnits(
        int $offeringId
    ) {

        $user =
            auth()->user();

        $payload =
            $this->request->getJSON(true);

        return $this->response->setJSON(

            $this->moduleService
                ->reorderLearningUnits(

                    $user,

                    $offeringId,

                    $payload['learning_units'] ?? []

                )

        );

    }

    public function storeLearningItem(
        int $offeringId,
        int $moduleId,
        int $learningUnitId
    ) {
        return $this->response->setJSON(

            $this->moduleService->storeLearningItem(

                auth()->user(),

                $offeringId,

                $moduleId,

                $learningUnitId,

                $this->request

            )

        );
    }

    public function updateLearningItem(
        int $offeringId,
        int $learningItemId
    ) {

        $user =
            auth()->user();

        $payload =
            $this->request->getJSON(true);

        return $this->response->setJSON(

            $this->moduleService
                ->updateLearningItem(

                    $user,

                    $offeringId,

                    $learningItemId,

                    $payload

                )

        );

    }

    public function deleteLearningItem(
        int $offeringId,
        int $learningItemId
    ) {

        $user =
            auth()->user();

        return $this->response->setJSON(

            $this->moduleService
                ->deleteLearningItem(

                    $user,

                    $offeringId,

                    $learningItemId

                )

        );

    }

    public function updateVideoItem(
        int $offeringId,
        int $learningItemId
    ) {

        $user = auth()->user();

        return $this->response->setJSON(

            $this->moduleService->updateVideoItem(

                $user,

                $offeringId,

                $learningItemId,

                $this->request

            )

        );

    }

    public function updatePdfItem(
        int $offeringId,
        int $learningItemId
    ) {

        return $this->response->setJSON(

            $this->moduleService->updatePdfItem(
                auth()->user(),
                $offeringId,
                $learningItemId,
                $this->request
            )

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Completion (student)
    |--------------------------------------------------------------------------
    */

    public function completeLearningItem(
        int $offeringId,
        int $learningItemId
    ) {

        $user = auth()->user();

        $result = $this->progressService->markComplete($user, $offeringId, $learningItemId);

        // The footer's Continue target: is it still locked now? (It can sit
        // in another unit, which unlockedItemIds doesn't cover.)
        $nextItemId = (int) ($this->request->getJSON(true)['next_item_id'] ?? 0);

        if (!empty($result['success']) && $nextItemId > 0) {

            $nextLock = $this->progressService->lockFor($user, $nextItemId);

            $result['nextLocked'] = $nextLock !== null;
            $result['nextLockHint'] = $nextLock
                ? preg_replace('/ first\.$/u', ' to continue.', $nextLock['message'])
                : '';

        }

        return $this->response->setJSON($result);

    }

    public function saveLearningItemProgress(
        int $offeringId,
        int $learningItemId
    ) {

        $payload = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->progressService->saveVideoProgress(
                auth()->user(),
                $offeringId,
                $learningItemId,
                (int) ($payload['percent'] ?? 0)
            )
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Dependencies (instructor)
    |--------------------------------------------------------------------------
    */

    public function learningItemDependencies(
        int $offeringId,
        int $learningItemId
    ) {

        $options = $this->progressService->dependencyOptions($offeringId, $learningItemId);

        if (! $options) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($options);

    }

    public function saveLearningItemDependencies(
        int $offeringId,
        int $learningItemId
    ) {

        $payload = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->progressService->saveDependencies(
                $offeringId,
                $learningItemId,
                (string) ($payload['mode'] ?? 'none'),
                (array) ($payload['prerequisite_ids'] ?? [])
            )
        );

    }

    public function reorderLearningItems(
        int $offeringId
    ) {

        $user =
            auth()->user();

        $payload =
            $this->request->getJSON(true);

        return $this->response->setJSON(

            $this->moduleService
                ->reorderLearningItems(

                    $user,

                    $offeringId,

                    $payload['items'] ?? []

                )

        );

    }
}


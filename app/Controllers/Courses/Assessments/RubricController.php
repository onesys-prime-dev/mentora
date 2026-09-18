<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\RubricService;

class RubricController extends BaseController
{
    protected RubricService $rubricService;

    public function __construct()
    {
        $this->rubricService = new RubricService();
    }

    public function index(int $assessmentId)
    {
        return $this->response->setJSON(
            $this->rubricService->listRubrics($assessmentId)
        );
    }

    public function show(int $rubricId)
    {
        return $this->response->setJSON(
            $this->rubricService->getRubric($rubricId)
        );
    }

    public function store(int $assessmentId)
    {
        $user = auth()->user();

        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->rubricService->createRubric(
                $user,
                $assessmentId,
                $payload
            )
        );
    }

    public function select(int $assessmentId, int $rubricId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->rubricService->selectRubric(
                $user,
                $assessmentId,
                $rubricId
            )
        );
    }

    public function update(int $rubricId)
    {
        $user = auth()->user();

        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->rubricService->updateRubric(
                $user,
                $rubricId,
                $payload
            )
        );
    }

    public function destroy(int $rubricId)
    {
        return $this->response->setJSON(
            $this->rubricService->deleteRubric($rubricId)
        );
    }

    public function storeCriterion(int $rubricId)
    {
        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->rubricService->createCriterion(
                $rubricId,
                $payload
            )
        );
    }

    public function updateCriterion(int $criterionId)
    {
        $payload = $this->request->getJSON(true);

        return $this->response->setJSON(
            $this->rubricService->updateCriterion(
                $criterionId,
                $payload
            )
        );
    }

    public function destroyCriterion(int $criterionId)
    {
        return $this->response->setJSON(
            $this->rubricService->deleteCriterion($criterionId)
        );
    }
}

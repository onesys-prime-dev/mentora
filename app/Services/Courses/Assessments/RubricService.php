<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentRubricModel;
use App\Models\Assessments\AssessmentRubricCriteriaModel;

class RubricService
{
    protected AssessmentModel $assessmentModel;
    protected AssessmentRubricModel $rubricModel;
    protected AssessmentRubricCriteriaModel $criteriaModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->rubricModel = new AssessmentRubricModel();
        $this->criteriaModel = new AssessmentRubricCriteriaModel();
    }

    /*
    |--------------------------------------------------------------------------
    | List / Show
    |--------------------------------------------------------------------------
    */

    public function listRubrics(int $assessmentId): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.',
            ];
        }

        $rubrics = $this->rubricModel->listForCourse(
            $assessment['course_id'],
            $assessmentId
        );

        return [
            'success' => true,
            'rubrics' => $rubrics,
        ];
    }

    public function getRubric(int $rubricId): array
    {
        $rubric = $this->rubricModel->getWithCriteria($rubricId);

        if (!$rubric) {
            return [
                'success' => false,
                'message' => 'Rubric not found.',
            ];
        }

        return [
            'success' => true,
            'rubric' => $rubric,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Create / Select / Update / Delete Rubric
    |--------------------------------------------------------------------------
    */

    public function createRubric(User $user, int $assessmentId, array $data): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.',
            ];
        }

        $this->replaceAssessmentRubric($assessmentId);

        $criteria = $data['criteria'] ?? [];

        $totalPoints = array_sum(
            array_map(
                fn ($criterion) => (float) ($criterion['max_points'] ?? 0),
                $criteria
            )
        );

        $now = date('Y-m-d H:i:s');

        $this->rubricModel->insert([
            'question_id' => null,
            'assessment_id' => $assessmentId,
            'title' => $data['title'] ?? 'Untitled Rubric',
            'description' => $data['description'] ?? null,
            'total_points' => $totalPoints,
            'created_by' => $user->id,
            'created_at' => $now,
            'status' => 'Active',
        ]);

        $rubricId = $this->rubricModel->getInsertID();

        $order = 1;

        foreach ($criteria as $criterion) {

            $this->criteriaModel->insert([
                'rubric_id' => $rubricId,
                'criteria_title' => $criterion['title'] ?? '',
                'description' => $criterion['description'] ?? null,
                'max_points' => $criterion['max_points'] ?? 0,
                'order_index' => $order++,
                'created_at' => $now,
            ]);

        }

        return [
            'success' => true,
            'message' => 'Rubric created successfully.',
            'rubric' => $this->rubricModel->getWithCriteria($rubricId),
        ];
    }

    public function selectRubric(User $user, int $assessmentId, int $sourceRubricId): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.',
            ];
        }

        $source = $this->rubricModel->getWithCriteria($sourceRubricId);

        if (!$source) {
            return [
                'success' => false,
                'message' => 'Rubric not found.',
            ];
        }

        $this->replaceAssessmentRubric($assessmentId);

        $now = date('Y-m-d H:i:s');

        $this->rubricModel->insert([
            'question_id' => null,
            'assessment_id' => $assessmentId,
            'title' => $source['title'],
            'description' => $source['description'],
            'total_points' => $source['total_points'],
            'created_by' => $user->id,
            'created_at' => $now,
            'status' => 'Active',
        ]);

        $rubricId = $this->rubricModel->getInsertID();

        foreach ($source['criteria'] as $criterion) {

            $this->criteriaModel->insert([
                'rubric_id' => $rubricId,
                'criteria_title' => $criterion['criteria_title'],
                'description' => $criterion['description'],
                'max_points' => $criterion['max_points'],
                'order_index' => $criterion['order_index'],
                'created_at' => $now,
            ]);

        }

        return [
            'success' => true,
            'message' => 'Rubric selected successfully.',
            'rubric' => $this->rubricModel->getWithCriteria($rubricId),
        ];
    }

    public function updateRubric(User $user, int $rubricId, array $data): array
    {
        $rubric = $this->rubricModel->find($rubricId);

        if (!$rubric) {
            return [
                'success' => false,
                'message' => 'Rubric not found.',
            ];
        }

        $this->rubricModel->update($rubricId, [
            'title' => $data['title'] ?? $rubric['title'],
            'description' => $data['description'] ?? $rubric['description'],
            'updated_by' => $user->id,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'success' => true,
            'message' => 'Rubric updated successfully.',
            'rubric' => $this->rubricModel->getWithCriteria($rubricId),
        ];
    }

    public function deleteRubric(int $rubricId): array
    {
        $rubric = $this->rubricModel->find($rubricId);

        if (!$rubric) {
            return [
                'success' => false,
                'message' => 'Rubric not found.',
            ];
        }

        $this->rubricModel->delete($rubricId);

        return [
            'success' => true,
            'message' => 'Rubric deleted successfully.',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Criteria
    |--------------------------------------------------------------------------
    */

    public function createCriterion(int $rubricId, array $data): array
    {
        $rubric = $this->rubricModel->find($rubricId);

        if (!$rubric) {
            return [
                'success' => false,
                'message' => 'Rubric not found.',
            ];
        }

        $order = $this->criteriaModel
            ->where('rubric_id', $rubricId)
            ->countAllResults();

        $this->criteriaModel->insert([
            'rubric_id' => $rubricId,
            'criteria_title' => $data['title'] ?? '',
            'description' => $data['description'] ?? null,
            'max_points' => $data['max_points'] ?? 0,
            'order_index' => $order + 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $criterionId = $this->criteriaModel->getInsertID();

        $this->rubricModel->recalculateTotal($rubricId);

        return [
            'success' => true,
            'message' => 'Criteria added successfully.',
            'criterion' => $this->criteriaModel->find($criterionId),
            'rubric' => $this->rubricModel->getWithCriteria($rubricId),
        ];
    }

    public function updateCriterion(int $criterionId, array $data): array
    {
        $criterion = $this->criteriaModel->find($criterionId);

        if (!$criterion) {
            return [
                'success' => false,
                'message' => 'Criteria not found.',
            ];
        }

        $this->criteriaModel->update($criterionId, [
            'criteria_title' => $data['title'] ?? $criterion['criteria_title'],
            'description' => $data['description'] ?? $criterion['description'],
            'max_points' => $data['max_points'] ?? $criterion['max_points'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->rubricModel->recalculateTotal($criterion['rubric_id']);

        return [
            'success' => true,
            'message' => 'Criteria updated successfully.',
            'criterion' => $this->criteriaModel->find($criterionId),
            'rubric' => $this->rubricModel->getWithCriteria($criterion['rubric_id']),
        ];
    }

    public function deleteCriterion(int $criterionId): array
    {
        $criterion = $this->criteriaModel->find($criterionId);

        if (!$criterion) {
            return [
                'success' => false,
                'message' => 'Criteria not found.',
            ];
        }

        $rubricId = $criterion['rubric_id'];

        $this->criteriaModel->delete($criterionId);

        $this->rubricModel->recalculateTotal($rubricId);

        return [
            'success' => true,
            'message' => 'Criteria deleted successfully.',
            'rubric' => $this->rubricModel->getWithCriteria($rubricId),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function replaceAssessmentRubric(int $assessmentId): void
    {
        $existing = $this->rubricModel->findByAssessment($assessmentId);

        if ($existing) {
            $this->rubricModel->delete($existing['id']);
        }
    }
}

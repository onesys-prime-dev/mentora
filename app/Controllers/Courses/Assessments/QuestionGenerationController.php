<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\AssessmentScopeService;
use App\Services\Courses\Assessments\QuestionGenerationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Courses\CourseOfferingModel;

class QuestionGenerationController extends BaseController
{
    protected QuestionGenerationService $generationService;
    protected CourseOfferingModel $courseOfferingModel;

    public function __construct()
    {
        $this->generationService = new QuestionGenerationService();
        $this->courseOfferingModel = new CourseOfferingModel();
    }

    /**
     * The only source is the learning unit the quiz sits under; the unit is
     * derived from the assessment, never read from the request. `type_mix`
     * and `learning_item_ids` arrive as JSON-encoded string fields since HTML
     * FormData can't nest objects.
     */
    public function start(int $offeringId)
    {
        $user = auth()->user();

        $post = $this->request->getPost();

                $offering = $this->courseOfferingModel->find($offeringId);

        // The assessment the questions are meant for comes from the form: it
        // must be one of this offering's own.
        if (
            ($post['assessment_id'] ?? '') !== ''
            && ! (new AssessmentScopeService())->assessmentBelongsTo((int) $post['assessment_id'], $offeringId)
        ) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }


        $data = [
            'assessment_id' => $post['assessment_id'] ?? null,
            // Derived from the URL's offering, never trusted from the client.
            'course_id' => (int) ($offering['course_id'] ?? 0),
            'offering_id' => $offeringId,
            'learning_item_ids' => $this->itemIds($post['learning_item_ids'] ?? null),
            'count' => (int) ($post['count'] ?? 10),
            'type_mix' => isset($post['type_mix']) ? json_decode($post['type_mix'], true) : null,
            'points_per_question' => isset($post['points_per_question']) && $post['points_per_question'] !== ''
                ? (float) $post['points_per_question']
                : null,
        ];

        return $this->response->setJSON(
            $this->generationService->startBatch($user, $data)
        );
    }

    /**
     * The chosen item ids from a JSON string or a repeated field; null when
     * the request names none (the service then uses every reading and PDF).
     *
     * @return list<int>|null
     */
    private function itemIds(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values(array_map('intval', $raw)) : null;
    }

    /**
     * A batch is its creator's, and belongs to a quiz of the offering in the
     * URL. Anything else answers 404, so an id from another teacher or another
     * course does not even confirm that it exists.
     */
    private function ownBatch(int $offeringId, int $batchId): void
    {
        if (! $this->generationService->ownsBatch(auth()->user(), $batchId, $offeringId)) {
            throw PageNotFoundException::forPageNotFound('Batch not found.');
        }
    }

    private function ownQuestion(int $offeringId, int $generatedQuestionId): void
    {
        if (! $this->generationService->ownsGeneratedQuestion(auth()->user(), $generatedQuestionId, $offeringId)) {
            throw PageNotFoundException::forPageNotFound('Question not found.');
        }
    }

    public function nextChunk(int $offeringId, int $batchId)
    {
        $this->ownBatch($offeringId, $batchId);

        return $this->response->setJSON(
            $this->generationService->nextChunk($batchId)
        );
    }

    public function show(int $offeringId, int $batchId)
    {
        $this->ownBatch($offeringId, $batchId);

        return $this->response->setJSON(
            $this->generationService->getBatch($batchId)
        );
    }

    public function cancel(int $offeringId, int $batchId)
    {
        $this->ownBatch($offeringId, $batchId);

        return $this->response->setJSON(
            $this->generationService->cancelBatch(auth()->user(), $batchId)
        );
    }

    /** The signed-in person's batch for this quiz that is still running or unreviewed, or null. */
    public function pending(int $offeringId, int $assessmentId)
    {
        if (! (new AssessmentScopeService())->assessmentBelongsTo($assessmentId, $offeringId)) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        return $this->response->setJSON([
            'success' => true,
            'pending' => $this->generationService->pendingBatch(auth()->user(), $assessmentId),
        ]);
    }

    public function updateQuestion(int $offeringId, int $generatedQuestionId)
    {
        $this->ownQuestion($offeringId, $generatedQuestionId);

        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->generationService->updateGeneratedQuestion($generatedQuestionId, $data)
        );
    }

    public function acceptQuestion(int $offeringId, int $generatedQuestionId)
    {
        $this->ownQuestion($offeringId, $generatedQuestionId);

        $user = auth()->user();

        return $this->response->setJSON(
            $this->generationService->acceptGeneratedQuestion($user, $generatedQuestionId)
        );
    }

    public function discardQuestion(int $offeringId, int $generatedQuestionId)
    {
        $this->ownQuestion($offeringId, $generatedQuestionId);

        return $this->response->setJSON(
            $this->generationService->discardGeneratedQuestion($generatedQuestionId)
        );
    }

    public function acceptAll(int $offeringId, int $batchId)
    {
        $this->ownBatch($offeringId, $batchId);

        $user = auth()->user();

        return $this->response->setJSON(
            $this->generationService->acceptAll($user, $batchId)
        );
    }
}

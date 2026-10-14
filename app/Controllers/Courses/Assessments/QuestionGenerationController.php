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
     * Always multipart/form-data (even when there's no file) so the same
     * endpoint handles topic / pasted-text / uploaded-file sources without
     * the frontend switching content types. `type_mix` arrives as a
     * JSON-encoded string field since HTML FormData can't nest objects.
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
            'source_type' => $post['source_type'] ?? 'topic',
            'topic' => $post['topic'] ?? null,
            'source_text' => $post['source_text'] ?? null,
            'count' => (int) ($post['count'] ?? 10),
            'type_mix' => isset($post['type_mix']) ? json_decode($post['type_mix'], true) : null,
            'points_per_question' => isset($post['points_per_question']) && $post['points_per_question'] !== ''
                ? (float) $post['points_per_question']
                : null,
        ];

        $file = $this->request->getFile('file');

        return $this->response->setJSON(
            $this->generationService->startBatch($user, $data, $file)
        );
    }

    public function nextChunk(int $batchId)
    {
        return $this->response->setJSON(
            $this->generationService->nextChunk($batchId)
        );
    }

    public function show(int $batchId)
    {
        return $this->response->setJSON(
            $this->generationService->getBatch($batchId)
        );
    }

    public function cancel(int $batchId)
    {
        return $this->response->setJSON(
            $this->generationService->cancelBatch(auth()->user(), $batchId)
        );
    }

    /** The signed-in person's batch for this quiz that is still running or unreviewed, or null. */
    public function pending(int $assessmentId)
    {
        return $this->response->setJSON([
            'success' => true,
            'pending' => $this->generationService->pendingBatch(auth()->user(), $assessmentId),
        ]);
    }

    public function updateQuestion(int $generatedQuestionId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->generationService->updateGeneratedQuestion($generatedQuestionId, $data)
        );
    }

    public function acceptQuestion(int $generatedQuestionId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->generationService->acceptGeneratedQuestion($user, $generatedQuestionId)
        );
    }

    public function discardQuestion(int $generatedQuestionId)
    {
        return $this->response->setJSON(
            $this->generationService->discardGeneratedQuestion($generatedQuestionId)
        );
    }

    public function acceptAll(int $batchId)
    {
        $user = auth()->user();

        return $this->response->setJSON(
            $this->generationService->acceptAll($user, $batchId)
        );
    }
}

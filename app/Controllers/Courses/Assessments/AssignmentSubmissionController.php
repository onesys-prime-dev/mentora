<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\AssignmentSubmissionService;

/**
 * Submitted files are downloaded through the shared files/{id} route
 * (FileController), not from here.
 */
class AssignmentSubmissionController extends BaseController
{
    protected AssignmentSubmissionService $submissionService;

    public function __construct()
    {
        $this->submissionService = new AssignmentSubmissionService();
    }

    /**
     * multipart/form-data: submission_text, submission_link, file — any
     * subset, limited to the assignment's allowed submission types.
     */
    public function submit(int $assessmentId)
    {
        $post = $this->request->getPost();

        $file = $this->request->getFile('file');

        return $this->response->setJSON(
            $this->submissionService->submit(auth()->user(), $assessmentId, $post ?? [], $file)
        );
    }
}

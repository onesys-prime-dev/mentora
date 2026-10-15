<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\QuestionExportService;
use App\Services\Courses\Assessments\QuestionImportService;

class QuestionImportExportController extends BaseController
{
    protected QuestionExportService $exportService;
    protected QuestionImportService $importService;

    public function __construct()
    {
        $this->exportService = new QuestionExportService();
        $this->importService = new QuestionImportService();
    }

    public function export(int $assessmentId)
    {
        // `docx` and `rtf` are the editable export that imports back in;
        // `test-pdf`, `test-docx` and `test-rtf` are the printable test with
        // its answer key at the end.
        $format = strtolower((string) $this->request->getGet('format'));

        if (str_starts_with($format, 'test-')) {
            $result = $this->exportService->exportPrintable($assessmentId, substr($format, 5));
        } else {
            $result = $format === 'rtf'
                ? $this->exportService->exportToRtf($assessmentId)
                : $this->exportService->exportToDocx($assessmentId);
        }

        if (!$result['success']) {
            $missing = ($result['message'] ?? '') === 'Assessment not found.';

            return $this->response->setJSON($result)->setStatusCode($missing ? 404 : 422);
        }

        return $this->response->download($result['file_path'], null)
            ->setFileName($result['filename']);
    }

    /**
     * Reads the uploaded file into a preview held on the server and returns
     * it with a token. The file itself is not kept: it is read from the
     * temporary upload and forgotten.
     */
    public function preview(int $assessmentId)
    {
        $file = $this->request->getFile('file');

        if (!$file || !$file->isValid()) {
            return $this->refuse('Choose a Word (.docx) or Rich Text (.rtf) file to import.');
        }

        $extension = strtolower($file->getClientExtension());

        if (!in_array($extension, QuestionImportService::EXTENSIONS, true)) {
            return $this->refuse('Only Word (.docx) and Rich Text (.rtf) files can be imported.');
        }

        if ($file->getSize() > QuestionImportService::MAX_BYTES) {
            return $this->refuse('This file is larger than ' . (QuestionImportService::MAX_BYTES / 1048576) . ' MB.');
        }

        $result = $this->importService->previewFile(auth()->user(), $assessmentId, $file->getRealPath() ?: $file->getTempName(), $extension);

        return $this->response->setJSON($result);
    }

    /**
     * Applies the stored preview with the teacher's choices: which items to
     * skip, which missing questions to remove. Nothing else comes from the
     * browser.
     */
    public function apply(int $assessmentId)
    {
        $body = $this->request->getJSON(true) ?? [];

        if (!is_array($body) || !is_string($body['token'] ?? null)) {
            return $this->refuse('This import has expired. Choose the file again.');
        }

        return $this->response->setJSON($this->importService->applyPreview(
            auth()->user(),
            $assessmentId,
            $body['token'],
            array_map('strval', (array) ($body['skip_keys'] ?? [])),
            array_map('intval', (array) ($body['remove_ids'] ?? [])),
            !empty($body['acknowledge_responses'])
        ));
    }

    public function undo(int $assessmentId)
    {
        $body = $this->request->getJSON(true) ?? [];

        if (!is_array($body) || !is_string($body['token'] ?? null)) {
            return $this->refuse('This import can no longer be undone.');
        }

        return $this->response->setJSON($this->importService->undoImport(auth()->user(), $assessmentId, $body['token']));
    }

    private function refuse(string $message)
    {
        return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
    }
}

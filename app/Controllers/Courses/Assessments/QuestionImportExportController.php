<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\QuestionExportService;
use App\Services\Courses\Assessments\QuestionImportService;

class QuestionImportExportController extends BaseController
{
    /** The most text accepted when questions are pasted rather than uploaded. */
    private const MAX_PASTE = 200000;

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

        if (in_array($format, ['aiken', 'gift', 'csv', 'xlsx', 'template-xlsx'], true)) {
            $result = $this->exportService->exportFormat($assessmentId, $format);
        } elseif (str_starts_with($format, 'test-')) {
            $result = $this->exportService->exportPrintable($assessmentId, substr($format, 5), [
                'versions'          => (int) $this->request->getGet('versions'),
                'shuffle_questions' => $this->request->getGet('shuffle_questions') === '1',
                'shuffle_choices'   => $this->request->getGet('shuffle_choices') === '1',
                'key_only'          => $this->request->getGet('key_only') === '1',
                'paper'             => (string) $this->request->getGet('paper'),
            ]);
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
        // Pasted text is read like a document.
        $pasted = $this->request->getPost('text');

        if (is_string($pasted) && trim($pasted) !== '') {
            if (mb_strlen($pasted) > self::MAX_PASTE) {
                return $this->refuse('That is more text than can be imported at once. Paste it in smaller parts.');
            }

            return $this->response->setJSON($this->importService->previewText(auth()->user(), $assessmentId, $pasted));
        }

        $file = $this->request->getFile('file');

        if (!$file || !$file->isValid()) {
            return $this->refuse('Choose a Word, Rich Text, Excel, CSV or text file, or paste your questions.');
        }

        $extension = strtolower($file->getClientExtension());

        if (!in_array($extension, QuestionImportService::EXTENSIONS, true)) {
            return $this->refuse('Only Word (.docx), Rich Text (.rtf), PDF, HTML, Excel (.xlsx), CSV (.csv), Aiken, GIFT or printed-exam text (.txt), Moodle XML and QTI packages (Blackboard, D2L, Sakai, WebCT) can be imported. TestGen .tst files are recognised but must be exported first.');
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
            !empty($body['acknowledge_responses']),
            is_array($body['overrides'] ?? null) ? $body['overrides'] : []
        ));
    }

    /** A small exam in the layout the importer reads, to start from. */
    public function sample(int $assessmentId)
    {
        return $this->response->download('quiz-import-sample.docx', $this->importService->sampleDocument());
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

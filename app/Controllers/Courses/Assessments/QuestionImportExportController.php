<?php

namespace App\Controllers\Courses\Assessments;

use App\Controllers\BaseController;
use App\Services\Courses\Assessments\QuestionExportService;
use App\Services\Courses\Assessments\QuestionImportService;
use App\Services\Storage\StorageService;
use App\Models\Files\FileModel;

class QuestionImportExportController extends BaseController
{
    protected QuestionExportService $exportService;
    protected QuestionImportService $importService;
    protected StorageService $storageService;
    protected FileModel $fileModel;

    public function __construct()
    {
        $this->exportService = new QuestionExportService();
        $this->importService = new QuestionImportService();
        $this->storageService = new StorageService();
        $this->fileModel = new FileModel();
    }

    public function export(int $assessmentId)
    {
        $format = strtolower((string) $this->request->getGet('format'));

        $result = $format === 'rtf'
            ? $this->exportService->exportToRtf($assessmentId)
            : $this->exportService->exportToDocx($assessmentId);

        if (!$result['success']) {
            return $this->response->setJSON($result)->setStatusCode(404);
        }

        return $this->response->download($result['file_path'], null)
            ->setFileName($result['filename']);
    }

    public function preview(int $assessmentId)
    {
        $file = $this->request->getFile('file');

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'A valid .docx file is required.',
            ]);
        }

        $user = auth()->user();

        $stored = $this->storageService->upload($file, 'question-imports');

        $this->fileModel->insert([
            'owner_user_id' => $user->id,
            'original_filename' => $stored['original_name'],
            'stored_filename' => $stored['filename'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['size'],
            'storage_key' => $stored['storage_key'],
            'status' => 'active',
        ]);

        $result = $this->importService->parseDocx(
            $assessmentId,
            $this->storageService->path($stored['storage_key'])
        );

        return $this->response->setJSON($result);
    }

    public function apply(int $assessmentId)
    {
        $user = auth()->user();

        $changes = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON(
            $this->importService->applyDiff($user, $assessmentId, $changes)
        );
    }
}

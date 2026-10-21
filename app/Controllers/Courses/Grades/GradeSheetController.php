<?php

namespace App\Controllers\Courses\Grades;

use App\Controllers\BaseController;
use App\Models\Files\FileModel;
use App\Services\Courses\Grades\GradeSheetExportService;
use App\Services\Courses\Grades\GradeSheetImportService;
use App\Services\Storage\StorageService;

/**
 * Instructor-only: the offline grade sheet. The app offers it as Excel (live
 * formulas, only the manual score cells editable); `?format=csv` still gives
 * plain values for scripts, and either can be imported (preview → apply), the
 * same flow as the quiz builder's question import.
 */
class GradeSheetController extends BaseController
{
    protected GradeSheetExportService $exportService;
    protected GradeSheetImportService $importService;
    protected StorageService $storageService;
    protected FileModel $fileModel;

    public function __construct()
    {
        $this->exportService = new GradeSheetExportService();
        $this->importService = new GradeSheetImportService();
        $this->storageService = new StorageService();
        $this->fileModel = new FileModel();
    }

    public function export(int $offeringId)
    {
        $format = strtolower((string) $this->request->getGet('format'));

        $result = $format === 'csv'
            ? $this->exportService->exportToCsv($offeringId)
            : $this->exportService->exportToXlsx($offeringId);

        if (!$result['success']) {
            return $this->response->setJSON($result)->setStatusCode(404);
        }

        return $this->response->download($result['file_path'], null)
            ->setFileName($result['filename']);
    }

    public function preview(int $offeringId)
    {
        $file = $this->request->getFile('file');

        $format = $file ? strtolower((string) $file->getClientExtension()) : '';

        if (!$file || !$file->isValid() || !in_array($format, GradeSheetImportService::FORMATS, true)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Upload the grade sheet as an .xlsx or .csv file.',
            ]);
        }

        $user = auth()->user();

        $stored = $this->storageService->upload($file, 'grade-imports');

        $this->fileModel->insert([
            'owner_user_id' => $user->id,
            'original_filename' => $stored['original_name'],
            'stored_filename' => $stored['filename'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['size'],
            'storage_key' => $stored['storage_key'],
            'status' => 'active',
        ]);

        return $this->response->setJSON(
            $this->importService->preview($offeringId, $this->storageService->path($stored['storage_key']), $format)
        );
    }

    public function apply(int $offeringId)
    {
        return $this->response->setJSON(
            $this->importService->apply(auth()->user(), $offeringId, $this->request->getJSON(true) ?? [])
        );
    }
}

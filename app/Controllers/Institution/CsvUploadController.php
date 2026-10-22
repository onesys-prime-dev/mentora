<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\CsvUploadService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\StructureException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bulk entry from CSV files: upload, preview, apply. The routes decide who
 * may (people.manage, the password for applying, and not while the
 * institution is read-only); the rules live in CsvUploadService.
 */
class CsvUploadController extends BaseController
{
    private const BASE = 'institution/people/upload';

    private CsvUploadService $uploads;

    public function __construct()
    {
        $this->uploads = new CsvUploadService();
    }

    /** Step 1: choose the files. */
    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        return view('pages/institution/upload', [
            'title'          => 'Upload from CSV',
            'page'           => 'people',
            'pageTitle'      => 'Upload from CSV',
            'user'           => auth()->user(),
            'uploadStep'     => 1,
            'uploadBlocked'  => $this->uploads->blockedBySis($institutionId),
            'uploadCanEdit'  => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'uploadDatasets' => CsvUploadService::datasets(),
            'uploadKept'     => null,
            'uploadToken'    => '',
        ]);
    }

    /** Step 1 submitted: check the files, keep them, preview. */
    public function store(): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $files         = [];

        foreach (CsvUploadService::datasets() as $dataset) {
            $file = $this->request->getFile($dataset);

            if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if (! $file->isValid()) {
                return redirect()->to(self::BASE)->with('error', "{$dataset}.csv could not be uploaded: " . $file->getErrorString() . '.');
            }

            $files[$dataset] = (string) file_get_contents($file->getTempName());
        }

        try {
            $preview = $this->uploads->preview($institutionId, $files, auth()->user());
        } catch (StructureException $e) {
            return redirect()->to(self::BASE)->with('error', $e->getMessage());
        }

        return redirect()->to(self::BASE . '/' . $preview['token']);
    }

    /** Step 2: what applying would do. */
    public function show(string $token): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $kept          = $this->uploads->kept($institutionId, $token);

        return view('pages/institution/upload', [
            'title'          => 'Upload from CSV',
            'page'           => 'people',
            'pageTitle'      => 'Upload from CSV',
            'user'           => auth()->user(),
            'reauthDialog'   => true,
            'uploadStep'     => 2,
            'uploadBlocked'  => $this->uploads->blockedBySis($institutionId),
            'uploadCanEdit'  => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'uploadDatasets' => CsvUploadService::datasets(),
            'uploadKept'     => $kept,
            'uploadToken'    => $token,
        ]);
    }

    /** Step 3: apply the kept files. */
    public function apply(string $token): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        try {
            $result = $this->uploads->apply($institutionId, $token, auth()->user());
        } catch (StructureException $e) {
            return redirect()->to(self::BASE . '/' . $token)->with('error', $e->getMessage());
        }

        $totals = $result->totals();

        return redirect()->to('institution/people')->with(
            'message',
            "The upload was applied: {$totals['created']} created, {$totals['updated']} updated, {$totals['skipped']} unchanged"
                . ($result->ended() > 0 ? ", {$result->ended()} ended" : '')
                . ($totals['failed'] > 0 ? ", {$totals['failed']} refused." : '.'),
        );
    }

    public function discard(string $token): RedirectResponse
    {
        $this->uploads->discard((new InstitutionScope())->requireInstitutionId(), $token);

        return redirect()->to(self::BASE)->with('message', 'The upload was discarded. Nothing was saved.');
    }

    /** A file with only the header row, to fill in. */
    public function template(string $dataset): ResponseInterface
    {
        if (! in_array($dataset, CsvUploadService::datasets(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $dataset . '.csv"')
            ->setBody(CsvUploadService::template($dataset));
    }
}

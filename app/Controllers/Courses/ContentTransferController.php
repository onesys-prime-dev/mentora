<?php

namespace App\Controllers\Courses;

use App\Controllers\BaseController;
use App\Services\Courses\ContentTransferService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Copy and move to another course: what the choosing steps list, and the two actions. See ContentTransferService.
 * Every address is the course the content is in; where it goes is checked against what the person teaches.
 */
class ContentTransferController extends BaseController
{
    protected ContentTransferService $transfer;

    public function __construct()
    {
        $this->transfer = new ContentTransferService();
    }

    /** The courses the person may put content into. */
    public function offerings(int $offeringId)
    {
        return $this->response->setJSON(['success' => true, 'offerings' => $this->transfer->offeringsFor(auth()->user(), $offeringId)]);
    }

    public function modules(int $offeringId, int $targetOfferingId)
    {
        $modules = $this->transfer->modulesOf(auth()->user(), $targetOfferingId);

        if ($modules === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON(['success' => true, 'modules' => $modules]);
    }

    public function units(int $offeringId, int $moduleId)
    {
        $units = $this->transfer->unitsOf(auth()->user(), $moduleId);

        if ($units === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON(['success' => true, 'units' => $units]);
    }

    /** What a module or a learning unit holds, to choose from when copying. */
    public function tree(int $offeringId, string $kind, int $id)
    {
        $tree = $this->transfer->treeOf(auth()->user(), $offeringId, $kind, $id);

        if ($tree === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON(['success' => true] + $tree);
    }

    public function copy(int $offeringId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON($this->transfer->copy(
            auth()->user(),
            $offeringId,
            (string) ($data['kind'] ?? ''),
            (int) ($data['id'] ?? 0),
            is_array($data['target'] ?? null) ? $data['target'] : [],
            is_array($data['unit_ids'] ?? null) ? $data['unit_ids'] : null,
            is_array($data['item_ids'] ?? null) ? $data['item_ids'] : null
        ));
    }

    public function move(int $offeringId)
    {
        $data = $this->request->getJSON(true) ?? [];

        return $this->response->setJSON($this->transfer->move(
            auth()->user(),
            $offeringId,
            (string) ($data['kind'] ?? ''),
            (int) ($data['id'] ?? 0),
            is_array($data['target'] ?? null) ? $data['target'] : []
        ));
    }
}

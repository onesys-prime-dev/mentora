<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\OfferingCopyService;
use App\Services\Institutions\StructureException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Copying offerings into another term: choose the terms, choose the
 * offerings, copy. The routes decide who may (terms.manage, and not while the
 * institution is read-only); the rules and audit entry live in
 * OfferingCopyService.
 */
class OfferingCopyController extends BaseController
{
    private const BASE = 'institution/terms/copy';

    private OfferingCopyService $copies;

    public function __construct()
    {
        $this->copies = new OfferingCopyService();
    }

    public function index(): string|RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        $source = (int) $this->request->getGet('source');
        $target = (int) $this->request->getGet('target');

        $plan  = null;
        $error = null;

        if ($source > 0 && $target > 0) {
            try {
                $plan = $this->copies->plan($institutionId, $source, $target);
            } catch (StructureException $e) {
                $error = $e->getMessage();
            }
        }

        return view('pages/institution/offering-copy', [
            'title'       => 'Copy offerings',
            'page'        => 'terms',
            'pageTitle'   => 'Copy offerings',
            'user'        => auth()->user(),
            'copyTerms'   => $this->copies->terms($institutionId),
            'copySource'  => $source,
            'copyTarget'  => $target,
            'copyPlan'    => $plan,
            'copyError'   => $error,
            'copyCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function copy(): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $post          = $this->request->getPost();

        $source = (int) ($post['source'] ?? 0);
        $target = (int) ($post['target'] ?? 0);
        $ids    = is_array($post['offerings'] ?? null) ? array_map('intval', $post['offerings']) : [];

        try {
            $done = $this->copies->copy($institutionId, $source, $target, $ids, (string) ($post['with_instructors'] ?? '') === '1', auth()->user());
        } catch (StructureException $e) {
            return redirect()->to(self::BASE . '?' . http_build_query(['source' => $source, 'target' => $target]))->with('error', $e->getMessage());
        }

        $parts = [
            $done['offerings'] . ' offering' . ($done['offerings'] === 1 ? '' : 's'),
            $done['modules'] . ' module' . ($done['modules'] === 1 ? '' : 's'),
            $done['assessments'] . ' assessment' . ($done['assessments'] === 1 ? '' : 's'),
        ];

        return redirect()->to('institution/offerings?' . http_build_query(['term' => $target]))->with(
            'message',
            'Copied ' . implode(', ', $parts) . '. No students, work or grades were copied.',
        );
    }
}

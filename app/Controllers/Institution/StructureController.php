<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\StructureException;
use App\Services\Institutions\StructureService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's academic structure: departments,
 * sub-departments and programs. The routes decide who may (structure.manage,
 * and not while the institution is read-only); the rules and the audit
 * entries live in StructureService, and every id is checked against the
 * administrator's own institution there (a foreign one is a 404).
 *
 * A form in a dialog (data-dialog-form) is answered with JSON; a plain form
 * post is answered with a redirect and a message, so it works without
 * JavaScript too.
 */
class StructureController extends BaseController
{
    private StructureService $structure;

    public function __construct()
    {
        $this->structure = new StructureService();
    }

    public function index(): string|RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $departments   = $this->structure->tree($institutionId);

        $programs = 0;
        $subs     = 0;

        foreach ($departments as $department) {
            $subs += count($department['subDepartments']);

            foreach ($department['subDepartments'] as $sub) {
                $programs += count($sub['programs']);
            }
        }

        return view('pages/institution/structure', [
            'title'            => 'Structure',
            'page'             => 'academics',
            'pageTitle'        => 'Structure',
            'user'             => auth()->user(),
            'structureTree'    => $departments,
            'structureCounts'  => ['departments' => count($departments), 'subDepartments' => $subs, 'programs' => $programs],
            'structureCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */

    public function createDepartment(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createDepartment($inst, $this->input(), auth()->user()), 'The department was added.');
    }

    public function updateDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateDepartment($inst, $id, $this->input(), auth()->user()), 'The department was saved.');
    }

    public function deactivateDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setDepartmentActive($inst, $id, false, auth()->user()), 'The department was deactivated.');
    }

    public function reactivateDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setDepartmentActive($inst, $id, true, auth()->user()), 'The department is active again.');
    }

    /*
    |--------------------------------------------------------------------------
    | Sub-departments
    |--------------------------------------------------------------------------
    */

    public function createSubDepartment(int $departmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createSubDepartment($inst, $departmentId, $this->input(), auth()->user()), 'The sub-department was added.');
    }

    public function updateSubDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateSubDepartment($inst, $id, $this->input(), auth()->user()), 'The sub-department was saved.');
    }

    public function deactivateSubDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setSubDepartmentActive($inst, $id, false, auth()->user()), 'The sub-department was deactivated.');
    }

    public function reactivateSubDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setSubDepartmentActive($inst, $id, true, auth()->user()), 'The sub-department is active again.');
    }

    /*
    |--------------------------------------------------------------------------
    | Programs
    |--------------------------------------------------------------------------
    */

    public function createProgram(int $subDepartmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createProgram($inst, $subDepartmentId, $this->input(), auth()->user()), 'The program was added.');
    }

    public function updateProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateProgram($inst, $id, $this->input(), auth()->user()), 'The program was saved.');
    }

    public function deactivateProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setProgramStatus($inst, $id, 'inactive', auth()->user()), 'The program was deactivated.');
    }

    public function archiveProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setProgramStatus($inst, $id, 'archived', auth()->user()), 'The program was archived.');
    }

    public function reactivateProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setProgramStatus($inst, $id, 'active', auth()->user()), 'The program is active again.');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Runs a change for the administrator's institution and answers it in the
     * way the caller asked for.
     *
     * @param callable(int): mixed $change
     */
    private function run(callable $change, string $success): ResponseInterface|RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $wantsJson     = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back          = site_url('institution/structure');

        try {
            $change($institutionId);
        } catch (StructureException $e) {
            if ($wantsJson) {
                return $this->response->setStatusCode(422)->setJSON(['errors' => $e->errors(), 'message' => $e->errors() === [] ? $e->getMessage() : 'Nothing was saved.']);
            }

            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        if ($wantsJson) {
            // The dialog's script goes to the list next; the message is waiting there.
            session()->setFlashdata('message', $success);

            return $this->response->setJSON(['message' => $success, 'redirect' => $back]);
        }

        return redirect()->to($back)->with('message', $success);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $post = $this->request->getPost();

        return is_array($post) ? $post : [];
    }
}

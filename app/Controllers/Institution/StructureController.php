<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
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
    use AnswersDialogForms;

    private const BACK = 'institution/structure';

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
            'structureChairs'  => (new ChairAssignmentService())->bySubDepartment($institutionId),
            'structureCandidates' => (new ChairAssignmentService())->candidates($institutionId),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */

    public function createDepartment(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createDepartment($inst, $this->input(), auth()->user()), 'The department was added.', self::BACK);
    }

    public function updateDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateDepartment($inst, $id, $this->input(), auth()->user()), 'The department was saved.', self::BACK);
    }

    public function deactivateDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setDepartmentActive($inst, $id, false, auth()->user()), 'The department was deactivated.', self::BACK);
    }

    public function reactivateDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setDepartmentActive($inst, $id, true, auth()->user()), 'The department is active again.', self::BACK);
    }

    /*
    |--------------------------------------------------------------------------
    | Sub-departments
    |--------------------------------------------------------------------------
    */

    public function createSubDepartment(int $departmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createSubDepartment($inst, $departmentId, $this->input(), auth()->user()), 'The sub-department was added.', self::BACK);
    }

    public function updateSubDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateSubDepartment($inst, $id, $this->input(), auth()->user()), 'The sub-department was saved.', self::BACK);
    }

    public function deactivateSubDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setSubDepartmentActive($inst, $id, false, auth()->user()), 'The sub-department was deactivated.', self::BACK);
    }

    public function reactivateSubDepartment(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setSubDepartmentActive($inst, $id, true, auth()->user()), 'The sub-department is active again.', self::BACK);
    }

    /*
    |--------------------------------------------------------------------------
    | Programs
    |--------------------------------------------------------------------------
    */

    public function createProgram(int $subDepartmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createProgram($inst, $subDepartmentId, $this->input(), auth()->user()), 'The program was added.', self::BACK);
    }

    public function updateProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateProgram($inst, $id, $this->input(), auth()->user()), 'The program was saved.', self::BACK);
    }

    public function deactivateProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setProgramStatus($inst, $id, 'inactive', auth()->user()), 'The program was deactivated.', self::BACK);
    }

    public function archiveProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setProgramStatus($inst, $id, 'archived', auth()->user()), 'The program was archived.', self::BACK);
    }

    public function reactivateProgram(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setProgramStatus($inst, $id, 'active', auth()->user()), 'The program is active again.', self::BACK);
    }

    /*
    |--------------------------------------------------------------------------
    | Chairpersons (per sub-department)
    |--------------------------------------------------------------------------
    */

    public function assignChairperson(int $subDepartmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst) => (new ChairAssignmentService())->assign($inst, $subDepartmentId, (int) ($this->input()['user_id'] ?? 0), auth()->user()),
            'The chairperson was assigned.',
            self::BACK,
        );
    }

    public function revokeChairperson(int $assignmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => (new ChairAssignmentService())->revoke($inst, $assignmentId, auth()->user()), 'The chairperson was removed from the sub-department.', self::BACK);
    }
}

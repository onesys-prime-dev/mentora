<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\StructureException;
use App\Services\Institutions\StructureService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's academic structure: colleges,
 * departments and programs. The routes decide who may (structure.manage,
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
        $colleges   = $this->structure->tree($institutionId);

        $programs = 0;
        $departments     = 0;

        foreach ($colleges as $college) {
            $departments += count($college['departments']);

            foreach ($college['departments'] as $department) {
                $programs += count($department['programs']);
            }
        }

        return view('pages/institution/structure', [
            'title'            => 'Structure',
            'page'             => 'academics',
            'pageTitle'        => 'Structure',
            'user'             => auth()->user(),
            'structureTree'    => $colleges,
            'structureCounts'  => ['colleges' => count($colleges), 'departments' => $departments, 'programs' => $programs],
            'structureCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'structureChairs'  => (new ChairAssignmentService())->byDepartment($institutionId),
            'structureCandidates' => (new ChairAssignmentService())->candidates($institutionId),
            'structureDeans'   => (new DeanAssignmentService())->byCollege($institutionId),
            'structureDeanCandidates' => (new DeanAssignmentService())->candidates($institutionId),
            'structureSisDeans' => (new \App\Services\Sis\SisOwnership())->managedAssignments('dean', $institutionId),
            'structureSisChairs' => (new \App\Services\Sis\SisOwnership())->managedAssignments('chairperson', $institutionId),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Colleges
    |--------------------------------------------------------------------------
    */

    public function createCollege(): ResponseInterface|RedirectResponse
    {
        return $this->run(function (int $inst) {
            $input = $this->input();
            $id    = $this->structure->createCollege($inst, $input, auth()->user());
            $dean  = (int) ($input['dean_user_id'] ?? 0);

            // A dean is chosen with the college. The college is already saved if the choice is refused.
            if ($dean > 0) {
                try {
                    (new DeanAssignmentService())->assign($inst, $id, $dean, auth()->user());
                } catch (StructureException $e) {
                    throw new StructureException('The college was added, but its dean was not assigned: ' . $e->getMessage() . ' Assign one from the college.');
                }
            }

            return $id;
        }, 'The college was added.', self::BACK);
    }

    public function updateCollege(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->updateCollege($inst, $id, $this->input(), auth()->user()), 'The college was saved.', self::BACK);
    }

    public function deactivateCollege(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setCollegeActive($inst, $id, false, auth()->user()), 'The college was deactivated.', self::BACK);
    }

    public function reactivateCollege(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->setCollegeActive($inst, $id, true, auth()->user()), 'The college is active again.', self::BACK);
    }

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */

    public function createDepartment(int $collegeId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createDepartment($inst, $collegeId, $this->input(), auth()->user()), 'The department was added.', self::BACK);
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
    | Programs
    |--------------------------------------------------------------------------
    */

    public function createProgram(int $departmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->structure->createProgram($inst, $departmentId, $this->input(), auth()->user()), 'The program was added.', self::BACK);
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
    | Chairpersons (per department)
    |--------------------------------------------------------------------------
    */

    public function assignChairperson(int $departmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(
            // replace=1: the administrator confirmed replacing the department's chairperson (16.0b).
            fn (int $inst) => (new ChairAssignmentService())->assign($inst, $departmentId, (int) ($this->input()['user_id'] ?? 0), auth()->user(), (string) ($this->input()['replace'] ?? '') === '1'),
            'The chairperson was assigned.',
            self::BACK,
        );
    }

    public function revokeChairperson(int $assignmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => (new ChairAssignmentService())->revoke($inst, $assignmentId, auth()->user()), 'The chairperson was removed from the department.', self::BACK);
    }

    /*
    |--------------------------------------------------------------------------
    | Deans (per college)
    |--------------------------------------------------------------------------
    */

    public function assignDean(int $collegeId): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst) => (new DeanAssignmentService())->assign($inst, $collegeId, (int) ($this->input()['user_id'] ?? 0), auth()->user()),
            'The dean was assigned.',
            self::BACK,
        );
    }

    public function revokeDean(int $assignmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => (new DeanAssignmentService())->end($inst, $assignmentId, auth()->user()), 'The dean was removed from the college.', self::BACK);
    }
}

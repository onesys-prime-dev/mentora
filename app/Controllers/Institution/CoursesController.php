<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\CourseCatalogService;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Sis\SisOwnership;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's course catalog: every course of the
 * institution with search, filters, sort and paging, and the forms to add,
 * edit and switch courses off. The routes decide who may (structure.manage,
 * and not while the institution is read-only); the rules, the SIS lock and
 * the audit entries live in CourseCatalogService.
 */
class CoursesController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/courses';

    private CourseCatalogService $courses;

    public function __construct()
    {
        $this->courses = new CourseCatalogService();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        $filters = [
            'q'       => (string) $this->request->getGet('q'),
            'program' => (int) $this->request->getGet('program'),
            'status'  => (string) $this->request->getGet('status'),
            'sort'    => (string) $this->request->getGet('sort'),
            'dir'     => (string) $this->request->getGet('dir'),
            'page'    => (int) $this->request->getGet('page'),
        ];

        $result = $this->courses->list($institutionId, $filters);

        return view('pages/institution/courses', [
            'title'         => 'Courses',
            'page'          => 'courses',
            'pageTitle'     => 'Courses',
            'user'          => auth()->user(),
            'courseRows'    => $result['rows'],
            'courseTotal'   => $result['total'],
            'coursePages'   => $result['pages'],
            'coursePage'    => $result['page'],
            'courseFilters' => $filters,
            'coursePrograms' => $this->courses->programs($institutionId, false),
            'courseActivePrograms' => $this->courses->programs($institutionId, true),
            'courseCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
            'courseLockedFields' => SisOwnership::COURSE_FIELDS,
        ]);
    }

    public function create(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->courses->create($inst, $this->input(), auth()->user()), 'The course was added.', self::BACK);
    }

    public function update(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->courses->update($inst, $id, $this->input(), auth()->user()), 'The course was saved.', self::BACK);
    }

    public function deactivate(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->courses->setStatus($inst, $id, 'inactive', auth()->user()), 'The course was deactivated.', self::BACK);
    }

    public function archive(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->courses->setStatus($inst, $id, 'archived', auth()->user()), 'The course was archived.', self::BACK);
    }

    public function reactivate(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->courses->setStatus($inst, $id, 'active', auth()->user()), 'The course is active again.', self::BACK);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\OfferingService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's course offerings and their instructors.
 * The routes decide who may (offerings.manage, and not while the institution
 * is read-only); the rules and the audit entries live in OfferingService.
 */
class OfferingsController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/offerings';

    private OfferingService $offerings;

    public function __construct()
    {
        $this->offerings = new OfferingService();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        $filters = [
            'q'          => (string) $this->request->getGet('q'),
            'term'       => (int) $this->request->getGet('term'),
            'program'    => (int) $this->request->getGet('program'),
            'instructor' => (string) $this->request->getGet('instructor'),
            'status'     => (string) $this->request->getGet('status'),
            'sort'       => (string) $this->request->getGet('sort'),
            'dir'        => (string) $this->request->getGet('dir'),
            'page'       => (int) $this->request->getGet('page'),
        ];

        $result = $this->offerings->list($institutionId, $filters);

        return view('pages/institution/offerings', [
            'title'              => 'Offerings',
            'page'               => 'offerings',
            'pageTitle'          => 'Offerings',
            'user'               => auth()->user(),
            'offeringRows'       => $result['rows'],
            'offeringTotal'      => $result['total'],
            'offeringPages'      => $result['pages'],
            'offeringPage'       => $result['page'],
            'offeringFilters'    => $filters,
            'offeringChoices'    => $this->offerings->filterChoices($institutionId),
            'offeringOptions'    => $this->offerings->options($institutionId),
            'offeringInstructors' => $this->offerings->instructorCandidates($institutionId),
            'offeringSisInstructors' => (new \App\Services\Sis\SisOwnership())->managedAssignments('instructor', $institutionId),
            'offeringCanEdit'    => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function create(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->offerings->create($inst, $this->input(), auth()->user()), 'The offering was added.', self::BACK);
    }

    public function update(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->offerings->update($inst, $id, $this->input(), auth()->user()), 'The offering was saved.', self::BACK);
    }

    public function archive(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->offerings->setArchived($inst, $id, true, auth()->user()), 'The offering was archived.', self::BACK);
    }

    public function reactivate(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->offerings->setArchived($inst, $id, false, auth()->user()), 'The offering is active again.', self::BACK);
    }

    public function assignInstructor(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(
            fn (int $inst) => $this->offerings->assignInstructor($inst, $id, (int) ($this->input()['user_id'] ?? 0), auth()->user()),
            'The instructor was assigned.',
            self::BACK,
        );
    }

    public function unassignInstructor(int $assignmentId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->offerings->unassignInstructor($inst, $assignmentId, auth()->user()), 'The instructor was removed from the offering.', self::BACK);
    }
}

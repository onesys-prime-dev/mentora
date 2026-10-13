<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\TermService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's academic terms. The routes decide who
 * may (terms.manage, and not while the institution is read-only); the rules,
 * the warnings and the audit entries live in TermService.
 */
class TermsController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/terms';

    private TermService $terms;

    public function __construct()
    {
        $this->terms = new TermService();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        return view('pages/institution/terms', [
            'title'       => 'Terms',
            'page'        => 'terms',
            'pageTitle'   => 'Terms',
            'user'        => auth()->user(),
            'termRows'    => $this->terms->list($institutionId),
            'termCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function create(): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst): array => $this->terms->create($inst, $this->input(), auth()->user()), 'The term was added.', self::BACK);
    }

    public function update(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst): array => $this->terms->update($inst, $id, $this->input(), auth()->user()), 'The term was saved.', self::BACK);
    }

    public function start(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst): array => $this->terms->setStatus($inst, $id, 'active', auth()->user()), 'The term is now active.', self::BACK);
    }

    public function unstart(int $id): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst): array => $this->terms->setStatus($inst, $id, 'upcoming', auth()->user()), 'The term is upcoming again.', self::BACK);
    }
}

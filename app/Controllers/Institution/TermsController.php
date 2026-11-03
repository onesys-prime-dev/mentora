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

        $all = $this->terms->list($institutionId);

        $filters = [
            'q'      => trim((string) $this->request->getGet('q')),
            'year'   => trim((string) $this->request->getGet('year')),
            'status' => (string) $this->request->getGet('status'),
        ];

        // The list is short and comes whole, so it is narrowed here. The running term's card
        // above the table is drawn from $all, so it stays when a search hides its row.
        $rows = array_values(array_filter($all, static function (array $term) use ($filters): bool {
            if ($filters['status'] !== '' && $term['status'] !== $filters['status']) {
                return false;
            }

            if ($filters['year'] !== '' && (string) $term['academic_year'] !== $filters['year']) {
                return false;
            }

            return $filters['q'] === ''
                || stripos($term['name'] . ' ' . $term['academic_year'], $filters['q']) !== false;
        }));

        $years = array_values(array_unique(array_map(static fn (array $term): string => (string) $term['academic_year'], $all)));
        rsort($years);

        return view('pages/institution/terms', [
            'title'       => 'Terms',
            'page'        => 'terms',
            'pageTitle'   => 'Terms',
            'user'        => auth()->user(),
            'termRows'    => $rows,
            'termAll'     => $all,
            'termYears'   => $years,
            'termFilters' => $filters,
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

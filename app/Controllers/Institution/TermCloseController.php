<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\StructureException;
use App\Services\Institutions\TermCloseService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Closing a term and reopening it. The routes decide who may (terms.manage,
 * the password, and not while the institution is read-only); the rules and
 * audit entries live in TermCloseService.
 */
class TermCloseController extends BaseController
{
    private const BACK = 'institution/terms';

    private TermCloseService $terms;

    public function __construct()
    {
        $this->terms = new TermCloseService();
    }

    /** What closing would change, and the button. */
    public function confirm(int $id): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();
        $plan          = $this->terms->preview($institutionId, $id);

        return view('pages/institution/term-close', [
            'title'        => 'Close ' . $plan['term']['name'],
            'page'         => 'terms',
            'pageTitle'    => 'Close a term',
            'user'         => auth()->user(),
            'closePlan'    => $plan,
            'closeCanEdit' => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function close(int $id): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        try {
            $done = $this->terms->close($institutionId, $id, auth()->user());
        } catch (StructureException $e) {
            return redirect()->to(self::BACK . '/' . $id . '/close')->with('error', $e->getMessage());
        }

        return redirect()->to(self::BACK)->with(
            'message',
            'The term was closed: ' . $done['offerings'] . ' offering' . ($done['offerings'] === 1 ? '' : 's') . ' and '
            . $done['enrolments'] . ' enrolment' . ($done['enrolments'] === 1 ? '' : 's') . ' completed. Its courses can be looked at but not changed.',
        );
    }

    public function reopen(int $id): RedirectResponse
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        try {
            $done = $this->terms->reopen($institutionId, $id, auth()->user());
        } catch (StructureException $e) {
            return redirect()->to(self::BACK)->with('error', $e->getMessage());
        }

        return redirect()->to(self::BACK)->with(
            'message',
            'The term is open again: ' . $done['offerings'] . ' offering' . ($done['offerings'] === 1 ? '' : 's') . ' and '
            . $done['enrolments'] . ' enrolment' . ($done['enrolments'] === 1 ? '' : 's') . ' put back.',
        );
    }
}

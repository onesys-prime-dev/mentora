<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Controllers\Institution\Concerns\AnswersDialogForms;
use App\Services\Institutions\InstitutionAccess;
use App\Services\Institutions\InstitutionScope;
use App\Services\Institutions\ParentLinkService;
use App\Services\Institutions\PeopleService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institutional administrator's parent-to-student links. The routes
 * decide who may (people.view to look, people.manage to change, and not while
 * the institution is read-only); the rules and audit entries live in
 * ParentLinkService.
 */
class ParentLinksController extends BaseController
{
    use AnswersDialogForms;

    private const BACK = 'institution/people/parents';

    private ParentLinkService $links;

    public function __construct()
    {
        $this->links = new ParentLinkService();
    }

    public function index(): string
    {
        $institutionId = (new InstitutionScope())->requireInstitutionId();

        $filters = ['q' => (string) $this->request->getGet('q'), 'page' => (int) $this->request->getGet('page')];
        $result  = $this->links->list($institutionId, $filters);

        return view('pages/institution/parents', [
            'title'          => 'Parents',
            'page'           => 'people',
            'pageTitle'      => 'Parents',
            'user'           => auth()->user(),
            'parentRows'     => $result['rows'],
            'parentTotal'    => $result['total'],
            'parentPages'    => $result['pages'],
            'parentPage'     => $result['page'],
            'parentFilters'  => $filters,
            'parentOpen'     => (new PeopleService())->openInvitations($institutionId),
            'parentCanEdit'  => (new InstitutionAccess())->canWrite((int) auth()->id()),
        ]);
    }

    public function link(int $parentId): ResponseInterface|RedirectResponse
    {
        $input = $this->input();

        return $this->run(
            fn (int $inst) => $this->links->link($inst, $parentId, (string) ($input['students'] ?? ''), auth()->user()),
            static function (mixed $result): string {
                $linked = (int) ($result['linked'] ?? 0);

                return $linked === 1 ? '1 student was linked.' : "{$linked} students were linked.";
            },
            self::BACK,
        );
    }

    public function unlink(int $linkId): ResponseInterface|RedirectResponse
    {
        return $this->run(fn (int $inst) => $this->links->unlink($inst, $linkId, auth()->user()), 'The link was removed.', self::BACK);
    }
}

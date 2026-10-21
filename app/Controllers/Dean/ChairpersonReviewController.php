<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\ChairpersonReviewFilters;
use App\Services\Courses\ChairpersonReviewService;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DeanCourseSearch;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The dean's review of what chairpersons changed in the department's courses: who, which course, what and
 * when, with filters, a link into the course (read-only) and a "reviewed" mark per entry. Review only:
 * nothing here blocks or reverts a change. Marking answers JSON for a script and redirects with a message
 * without one. Which entries are the dean's to mark is the service's check.
 */
class ChairpersonReviewController extends BaseController
{
    private const PER_PAGE = 15;

    public function index(): string
    {
        $user = auth()->user();

        if ((new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Chairperson review', 'page' => 'chairperson-review', 'user' => $user]);
        }

        $service = new ChairpersonReviewService();
        $filters = ChairpersonReviewFilters::fromInput($this->request->getGet());
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        $loadError      = false;
        $result         = ['rows' => [], 'total' => 0, 'counts' => ['unreviewed' => 0, 'reviewed' => 0]];
        $chairpersons   = [];
        $subDepartments = [];

        try {
            $result         = $service->entries($user, $filters, $page, self::PER_PAGE);
            $chairpersons   = $service->chairpersons($user);
            $subDepartments = (new DeanCourseSearch())->subDepartments($user);
        } catch (\Throwable $e) {
            log_message('error', 'Chairperson review failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/chairperson-review', [
            'title'          => 'Chairperson review',
            'page'           => 'chairperson-review',
            'user'           => $user,
            'filters'        => $filters,
            'entries'        => $result['rows'],
            'total'          => $result['total'],
            'counts'         => $result['counts'],
            'pageNumber'     => $page,
            'pages'          => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'chairpersons'   => $chairpersons,
            'subDepartments' => $subDepartments,
            'loadError'      => $loadError,
            'flashToasts'    => true,
            'previousPage'   => 'dashboard',
        ]);
    }

    public function reviewed(): ResponseInterface|RedirectResponse
    {
        $ids    = (array) $this->request->getPost('ids');
        $single = $this->request->getPost('id');

        if ($single !== null && $single !== '') {
            $ids[] = $single;
        }

        $result    = (new ChairpersonReviewService())->markReviewed(auth()->user(), array_map('intval', $ids));
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('dean/chairperson-review') . (($query = (string) $this->request->getPost('back')) !== '' ? '?' . ltrim($query, '?') : '');

        if (! $result['success']) {
            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => ['ids' => $result['message']], 'message' => $result['message']])
                : redirect()->to($back)->with('error', $result['message']);
        }

        return $wantsJson
            ? $this->response->setJSON(['message' => $result['message'], 'marked' => $result['marked'], 'redirect' => $back])
            : redirect()->to($back)->with('message', $result['message']);
    }
}

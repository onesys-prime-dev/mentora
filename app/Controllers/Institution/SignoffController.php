<?php

declare(strict_types=1);

namespace App\Controllers\Institution;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeSignoffService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The institution administrator's Sign-off (audit decisions 2.1, 2.4): the
 * finalized grades of departments with no dean who may sign them off, per
 * section or for a program's ready sections, with the password asked first
 * (see the routes), and reopening with a reason. The same page as the dean's;
 * never a section the administrator teaches (D4), which is the service's check.
 */
class SignoffController extends BaseController
{
    public function index(): string
    {
        $user    = auth()->user();
        $service = new GradeSignoffService();
        $term    = $this->request->getGet('term');

        $loadError = false;
        $board     = ['terms' => [], 'term' => null, 'programs' => []];

        try {
            $board = $service->boardFor($service->adminProgramIds($user), is_string($term) && ctype_digit($term) ? (int) $term : null, 'institution');
        } catch (\Throwable $e) {
            log_message('error', 'Institution sign-off failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/signoff', [
            'title'        => 'Sign-off',
            'page'         => 'institution',
            'user'         => $user,
            'area'         => 'institution',
            'board'        => $board,
            'loadError'    => $loadError,
            'reauthDialog' => true,
            'previousPage' => 'dashboard',
        ]);
    }

    public function signOff(int $offeringId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeSignoffService())->signOff(auth()->user(), $offeringId));
    }

    public function signOffProgram(int $programId, int $termId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeSignoffService())->signOffProgram(auth()->user(), $programId, $termId), 'term', $termId);
    }

    public function reopen(int $offeringId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeSignoffService())->reopen(auth()->user(), $offeringId, (string) $this->request->getPost('reason')), 'reason');
    }

    /**
     * @param array{success: bool, message: string} $result
     */
    private function answer(array $result, string $field = 'sign-off', ?int $termId = null): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('institution/signoff') . ($termId !== null ? '?term=' . $termId : '');

        if (! $result['success']) {
            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => [$field => $result['message']], 'message' => $result['message']])
                : redirect()->to($back)->with('error', $result['message']);
        }

        return $wantsJson
            ? $this->response->setJSON(['message' => $result['message'], 'redirect' => $back])
            : redirect()->to($back)->with('message', $result['message']);
    }
}

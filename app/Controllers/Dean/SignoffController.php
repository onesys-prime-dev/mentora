<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\Grades\GradeSignoffService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The dean's term-end sign-off: the department's sections for a term, grouped by
 * program, each ready, blocked (and why) or signed off. Signing off, alone or for
 * a program's ready sections, asks for the password again (the reauth filter on
 * the routes); reopening a sign-off needs a reason. The actions answer JSON for
 * the dialog that sends them, and redirect with a message without JavaScript.
 * Who may sign off what is the service's check.
 */
class SignoffController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        if ((new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Sign-off', 'page' => 'signoff', 'user' => $user]);
        }

        $term = $this->request->getGet('term');

        $loadError = false;
        $board     = ['terms' => [], 'term' => null, 'programs' => []];

        try {
            $board = (new GradeSignoffService())->board($user, is_string($term) && ctype_digit($term) ? (int) $term : null);
        } catch (\Throwable $e) {
            log_message('error', 'Dean sign-off failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/signoff', [
            'title'        => 'Sign-off',
            'area'         => 'dean',
            'page'         => 'signoff',
            'user'         => $user,
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
        $back      = site_url('dean/signoff') . ($termId !== null ? '?term=' . $termId : '');

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

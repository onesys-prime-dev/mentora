<?php

declare(strict_types=1);

namespace App\Controllers\Chairperson;

use App\Controllers\BaseController;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The chairperson's grade-override requests: the ones from the sub-departments
 * they chair, to endorse or send back (with a comment). The list is what waits
 * for them, or everything in their sub-departments to read. The actions answer
 * JSON for the dialog that sends them, and redirect with a message without
 * JavaScript. Who may act on which request is the service's check.
 */
class OverrideRequestsController extends BaseController
{
    public function index(): string
    {
        $view = $this->request->getGet('view') === 'all' ? 'all' : 'pending';

        return view('pages/chairperson/override-requests', [
            'title'        => 'Override requests',
            'page'         => 'override-requests',
            'user'         => auth()->user(),
            'view'         => $view,
            'requests'     => (new GradeOverrideRequestService())->listForChair(auth()->user(), $view),
            'previousPage' => 'dashboard',
        ]);
    }

    public function endorse(int $requestId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeOverrideRequestService())->endorse(auth()->user(), $requestId, (string) $this->request->getPost('comment')));
    }

    public function sendBack(int $requestId): ResponseInterface|RedirectResponse
    {
        return $this->answer((new GradeOverrideRequestService())->sendBack(auth()->user(), $requestId, (string) $this->request->getPost('comment')), 'comment');
    }

    /**
     * @param array{success: bool, message: string} $result
     */
    private function answer(array $result, string $field = 'comment'): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('chairperson/override-requests');

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

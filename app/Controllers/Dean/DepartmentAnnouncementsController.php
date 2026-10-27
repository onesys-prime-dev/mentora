<?php

declare(strict_types=1);

namespace App\Controllers\Dean;

use App\Controllers\BaseController;
use App\Services\Courses\DeanAccessService;
use App\Services\Courses\DepartmentAnnouncementService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The dean's announcements to their department: the ones showing and the ones that ended, a form to post a
 * new one (to the department, a sub-department or a program, for everyone, staff or students) and to edit
 * or end one. The actions answer JSON for the dialog that sends them (an error beside its field), and
 * redirect with a message without JavaScript. Who may do what is the service's check.
 */
class DepartmentAnnouncementsController extends BaseController
{
    public function index(): string
    {
        $user = auth()->user();

        if ((new DeanAccessService())->departmentFor($user) === null) {
            return view('pages/dean/no-assignment', ['title' => 'Announcements', 'page' => 'announcements', 'user' => $user]);
        }

        $service = new DepartmentAnnouncementService();
        $tab     = $this->request->getGet('tab') === 'ended' ? 'ended' : 'active';

        $loadError = false;
        $list      = ['rows' => [], 'counts' => ['active' => 0, 'ended' => 0]];
        $scopes    = [];

        try {
            $list   = $service->listForDean($user, $tab);
            $scopes = $service->scopeOptions($user);
        } catch (\Throwable $e) {
            log_message('error', 'Department announcements failed to load: ' . $e->getMessage());
            $loadError = true;
        }

        return view('pages/dean/announcements', [
            'title'        => 'Announcements',
            'page'         => 'announcements',
            'user'         => $user,
            'tab'          => $tab,
            'list'         => $list,
            'scopes'       => $scopes,
            'loadError'    => $loadError,
            'flashToasts'  => true,
            'previousPage' => 'dashboard',
        ]);
    }

    public function store(): ResponseInterface|RedirectResponse
    {
        return $this->answer((new DepartmentAnnouncementService())->post(auth()->user(), (array) $this->request->getPost()));
    }

    public function update(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer((new DepartmentAnnouncementService())->edit(auth()->user(), $id, (array) $this->request->getPost()));
    }

    public function end(int $id): ResponseInterface|RedirectResponse
    {
        return $this->answer((new DepartmentAnnouncementService())->end(auth()->user(), $id));
    }

    /**
     * @param array{success: bool, message: string, errors?: array<string, string>} $result
     */
    private function answer(array $result): ResponseInterface|RedirectResponse
    {
        $wantsJson = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $back      = site_url('dean/announcements');

        if (! $result['success']) {
            $errors = ($result['errors'] ?? []) !== [] ? $result['errors'] : ['message' => $result['message']];

            return $wantsJson
                ? $this->response->setStatusCode(422)->setJSON(['errors' => $errors, 'message' => $result['message']])
                : redirect()->to($back)->with('error', $result['message']);
        }

        return $wantsJson
            ? $this->response->setJSON(['message' => $result['message'], 'redirect' => $back])
            : redirect()->to($back)->with('message', $result['message']);
    }
}
